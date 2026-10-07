<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\Complaint;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Complaint calls: agents log them (usually from a call), managers follow them up to resolution. */
class ComplaintController extends Controller
{
    public static function canView(User $user): bool
    {
        return $user->seesEverything() || $user->can('callgear.only') || $user->can('complaints.manage');
    }

    private function agents()
    {
        return Employee::where('is_active', true)
            ->whereHas('tags', fn ($t) => $t->where('name', 'like', \App\Models\Tag::AGENTS))
            ->orderBy('first_name')->get();
    }

    public function index(Request $request)
    {
        abort_unless(self::canView($request->user()), 403);
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : null;
        $base = Complaint::query()
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->integer('employee_id'), fn ($q, $e) => $q->where('employee_id', $e))
            ->when($from, fn ($q) => $q->where('called_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('called_at', '<=', $to))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('phone', 'like', "%$s%")->orWhere('message', 'like', "%$s%")
                ->orWhereHas('guest', fn ($g) => $g->where('first_name', 'like', "%$s%")->orWhere('last_name', 'like', "%$s%"))));
        $counts = Complaint::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('complaints.index', [
            'rows' => (clone $base)->with(['employee', 'guest', 'branch', 'creator', 'resolver', 'call'])->orderByDesc('called_at')->orderByDesc('id')->paginate(30)->withQueryString(),
            'counts' => $counts,
            'agents' => $this->agents(),
            'canManage' => $request->user()->can('complaints.manage'),
        ]);
    }

    public function create(Request $request)
    {
        abort_unless(self::canView($request->user()), 403);
        $call = $request->integer('call') ? Call::with(['guest', 'employee'])->find($request->integer('call')) : null;
        $own = $request->user()->employee;
        // Agents pick from their own recent calls; managers from everyone's.
        $recent = Call::with('guest')->where('started_at', '>=', now()->subDays(3))
            ->when(! $request->user()->can('complaints.manage') && $own, fn ($q) => $q->where('employee_id', $own->id))
            ->orderByDesc('started_at')->limit(100)->get();

        return view('complaints.create', ['call' => $call, 'recent' => $recent, 'agents' => $this->agents(), 'own' => $own,
            'canPickAgent' => $request->user()->can('complaints.manage') || ! $own]);
    }

    public function store(Request $request)
    {
        abort_unless(self::canView($request->user()), 403);
        $data = $request->validate([
            'call_id' => 'nullable|integer|exists:calls,id',
            'phone' => 'nullable|string|max:40',
            'employee_id' => 'nullable|integer|exists:employees,id',
            'category' => ['nullable', Rule::in(array_keys(Complaint::CATEGORIES))],
            'message' => 'required|string|max:5000',
        ]);
        $call = ! empty($data['call_id']) ? Call::find($data['call_id']) : null;
        $phone = $data['phone'] ?? null ?: $call?->caller;
        abort_if(! $call && ! $phone, 422, 'Pick the call or type the caller number.');
        $own = $request->user()->employee;
        $employeeId = ($request->user()->can('complaints.manage') || ! $own) ? ($data['employee_id'] ?? $call?->employee_id ?? $own?->id) : $own->id;
        $guest = $call?->guest ?? Complaint::guestForPhone($phone);

        $complaint = Complaint::create([
            'call_id' => $call?->id,
            'employee_id' => $employeeId,
            'guest_id' => $guest?->id,
            'branch_id' => $call?->branch_id ?? $guest?->branch_id,
            'created_by' => $request->user()->id,
            'phone' => $phone,
            'category' => $data['category'] ?? null,
            'message' => $data['message'],
            'called_at' => $call?->started_at ?? now(),
        ]);

        return redirect()->route('complaints.index')->with('status', "Complaint {$complaint->number()} saved.");
    }

    public function update(Request $request, Complaint $complaint)
    {
        abort_unless($request->user()->can('complaints.manage'), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Complaint::STATUSES))],
            'category' => ['nullable', Rule::in(array_keys(Complaint::CATEGORIES))],
            'resolution' => 'nullable|string|max:5000',
        ]);
        $resolved = $data['status'] === 'resolved';
        $complaint->update($data + [
            'resolved_at' => $resolved ? ($complaint->resolved_at ?? now()) : null,
            'resolved_by' => $resolved ? ($complaint->resolved_by ?? $request->user()->id) : null,
        ]);

        return back()->with('status', "Complaint {$complaint->number()} updated.");
    }

    /** Agents add the client's words to a complaint (e.g. one imported from a CallGear tag). */
    public function note(Request $request, Complaint $complaint)
    {
        abort_unless(self::canView($request->user()), 403);
        $data = $request->validate(['message' => 'required|string|max:5000']);
        $complaint->update(['message' => $data['message']]);

        return back()->with('status', "Complaint {$complaint->number()} updated.");
    }

    public function destroy(Request $request, Complaint $complaint)
    {
        abort_unless($request->user()->can('complaints.manage'), 403);
        $number = $complaint->number();
        $complaint->delete();

        return back()->with('status', "Complaint $number deleted.");
    }
}
