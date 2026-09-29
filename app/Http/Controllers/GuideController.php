<?php

namespace App\Http\Controllers;

use App\Models\GuideNote;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Read-only guides for CallGear agents (scripts, booking rules, audit criteria), shown as sticky notes.
 * Notes live in guide_notes (first imported from resources/guides/{slug}.md); users with
 * guides.manage can add, edit, reorder and remove them.
 */
class GuideController extends Controller
{
    /** slug => [menu title, icon, one-line description, optional 'flow' => default chart JSON in resources/guides (a flowchart page, no notes)] */
    public const GUIDES = [
        'call-flow' => ['Call Flow', 'iconoir-arrow-right', 'How every call moves: bookings, enquiries, no-shows and complaints', 'flow' => 'call-flow.json'],
        'inbound-call-script' => ['Inbound call script', 'iconoir-help-circle', 'Greeting, customer types, re-confirmation and the 30-minute call'],
        'complaints-and-outbound' => ['Complaints & outbound', 'iconoir-page-edit', 'Complaint handling, outbound scripts and accountability'],
        'sla-and-escalation' => ['SLA & escalation', 'iconoir-clock', 'Complaint time limits, owners and escalation rules'],
        'call-quality-audit' => ['Call quality audit', 'iconoir-check-circle', 'How every call is scored, and what fails a call'],
    ];

    public static function visibleTo(?User $user): bool
    {
        return (bool) ($user?->can('callgear.only') || $user?->can('guides.manage'));
    }

    public function show(Request $request, string $guide)
    {
        abort_unless(self::visibleTo($request->user()), 403);
        abort_unless(isset(self::GUIDES[$guide]), 404);
        if (isset(self::GUIDES[$guide]['flow'])) {
            return view('guides.flow', [
                'slug' => $guide, 'guide' => self::GUIDES[$guide], 'chart' => self::chart($guide),
                'edited' => Setting::get("guides.flow.$guide") !== null, 'canEdit' => $request->user()->can('guides.manage'),
            ]);
        }
        $raw = (string) @file_get_contents(resource_path("guides/$guide.md"));
        $intro = trim((string) Str::of($raw)->after("\n")->before("\n## "));

        return view('guides.show', [
            'slug' => $guide,
            'guide' => self::GUIDES[$guide],
            'title' => trim((string) Str::of($raw)->match('/^# (.+)$/m')) ?: self::GUIDES[$guide][0],
            'intro' => $intro === '' ? '' : Str::markdown($intro, ['html_input' => 'escape']),
            'notes' => GuideNote::where('guide', $guide)->orderBy('position')->orderBy('id')->get(),
            'canEdit' => $request->user()->can('guides.manage'),
        ]);
    }

    public const FLOW_SHAPES = ['box', 'decision', 'rounded', 'start'];

    public const FLOW_GROUPS = ['intro', 'book', 'noshow', 'complaint', 'endnode', 'dark'];

    /** The chart as saved on the page, or the shipped default. */
    public static function chart(string $guide): array
    {
        $saved = json_decode((string) Setting::get("guides.flow.$guide"), true);

        return is_array($saved) ? $saved : (json_decode((string) @file_get_contents(resource_path('guides/'.self::GUIDES[$guide]['flow'])), true) ?: ['direction' => 'LR', 'steps' => [], 'links' => []]);
    }

    /** Save the chart edited on the page (steps and arrows). */
    public function saveFlow(Request $request, string $guide)
    {
        $this->authorizeManage($request);
        abort_unless(isset(self::GUIDES[$guide]['flow']), 404);
        $chart = json_decode((string) $request->input('chart'), true);
        $v = validator(is_array($chart) ? $chart : [], [
            'direction' => 'required|in:LR,TD',
            'steps' => 'required|array|min:1|max:200',
            'steps.*.id' => ['required', 'regex:/^[A-Za-z][A-Za-z0-9_]{0,30}$/', 'distinct'],
            'steps.*.label' => 'required|string|max:200',
            'steps.*.shape' => 'required|in:'.implode(',', self::FLOW_SHAPES),
            'steps.*.group' => 'required|in:'.implode(',', self::FLOW_GROUPS),
            'links' => 'present|array|max:400',
            'links.*.from' => 'required|string',
            'links.*.to' => 'required|string',
            'links.*.label' => 'nullable|string|max:100',
            'links.*.dotted' => 'boolean',
        ], ['steps.*.label.required' => 'Every step needs a label.']);
        $ids = collect($chart['steps'] ?? [])->pluck('id')->all();
        $v->after(function ($v) use ($chart, $ids) {
            foreach ($chart['links'] ?? [] as $l) {
                if (! in_array($l['from'] ?? null, $ids, true) || ! in_array($l['to'] ?? null, $ids, true)) {
                    $v->errors()->add('links', 'An arrow points to a step that no longer exists.');
                    break;
                }
            }
        });
        if ($v->fails()) {
            return back()->withErrors($v->errors());
        }
        $clean = [
            'direction' => $chart['direction'],
            'steps' => array_map(fn ($s) => ['id' => $s['id'], 'label' => trim($s['label']), 'shape' => $s['shape'], 'group' => $s['group']], $chart['steps']),
            'links' => array_map(fn ($l) => ['from' => $l['from'], 'to' => $l['to'], 'label' => trim((string) ($l['label'] ?? '')), 'dotted' => (bool) ($l['dotted'] ?? false)], $chart['links']),
        ];
        Setting::put("guides.flow.$guide", json_encode($clean, JSON_UNESCAPED_UNICODE));

        return redirect()->route('guides.show', $guide)->with('status', 'Chart saved.');
    }

    /** Go back to the chart that shipped with the app. */
    public function resetFlow(Request $request, string $guide)
    {
        $this->authorizeManage($request);
        abort_unless(isset(self::GUIDES[$guide]['flow']), 404);
        Setting::put("guides.flow.$guide", null);

        return redirect()->route('guides.show', $guide)->with('status', 'Chart reset to the original.');
    }

    public function store(Request $request, string $guide)
    {
        $this->authorizeManage($request);
        abort_unless(isset(self::GUIDES[$guide]) && ! isset(self::GUIDES[$guide]['flow']), 404);
        GuideNote::create($this->validated($request) + [
            'guide' => $guide, 'position' => (int) GuideNote::where('guide', $guide)->max('position') + 1, 'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('guides.show', $guide)->with('status', 'Note added.');
    }

    public function update(Request $request, GuideNote $note)
    {
        $this->authorizeManage($request);
        $note->update($this->validated($request) + ['updated_by' => $request->user()->id]);

        return redirect()->route('guides.show', $note->guide)->with('status', 'Note saved.');
    }

    public function destroy(Request $request, GuideNote $note)
    {
        $this->authorizeManage($request);
        $note->delete();

        return redirect()->route('guides.show', $note->guide)->with('status', 'Note removed.');
    }

    /** Swap a note with its neighbour (direction up or down). */
    public function move(Request $request, GuideNote $note)
    {
        $this->authorizeManage($request);
        $notes = GuideNote::where('guide', $note->guide)->orderBy('position')->orderBy('id')->get()->values();
        $i = $notes->search(fn ($n) => $n->id === $note->id);
        $j = $request->input('direction') === 'up' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < $notes->count()) {
            $order = $notes->pluck('id')->all();
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            foreach ($order as $pos => $id) {
                GuideNote::whereKey($id)->update(['position' => $pos + 1]);
            }
        }

        return redirect()->route('guides.show', $note->guide);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('guides.manage'), 403);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:200',
            'important' => 'nullable|string|max:2000',
            'body' => 'nullable|string|max:20000',
        ]);
    }
}
