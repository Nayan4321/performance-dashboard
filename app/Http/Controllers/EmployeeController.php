<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tag;
use Illuminate\Http\Request;

/** Employee list with this month's headline numbers per person. */
class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $allowed = $user->visibleBranchIds();
        $own = $user->visibleEmployeeId();
        $from = now()->startOfMonth();

        $employees = Employee::with(['branch', 'user.roles', 'tags'])
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed ?: [0]))
            ->when($own !== null, fn ($q) => $q->whereKey($own))
            ->when($request->integer('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))
            ->when($request->integer('tag_id'), fn ($q, $t) => $q->whereHas('tags', fn ($w) => $w->whereKey($t)))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('first_name', 'like', "%$s%")->orWhere('last_name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")))
            ->when($request->status !== 'all', fn ($q) => $q->where('is_active', true))
            ->withCount(['appointments' => fn ($q) => $q->where('start_time', '>=', $from)])
            ->withSum(['sales' => fn ($q) => $q->where('sold_at', '>=', $from)], 'net_amount')
            ->withCount(['bookedAppointments as booked_count' => fn ($q) => $q->where('booked_at', '>=', $from)])
            ->withSum(['enteredSales as entered_sum' => fn ($q) => $q->where('sold_at', '>=', $from)], 'net_amount')
            ->withCount(['leads' => fn ($q) => $q->where('lead_at', '>=', $from)])
            ->withCount(['calls' => fn ($q) => $q->where('started_at', '>=', $from)])
            ->orderBy('first_name')->paginate(30)->withQueryString();

        $branches = Branch::when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed ?: [0]))->orderBy('name')->get();

        $tags = Tag::orderBy('name')->get();

        return view('employees.index', compact('employees', 'branches', 'tags'));
    }

    /** Salary, section and target multiplier used for Module B targets. */
    public function updateTarget(Request $request, Employee $employee)
    {
        $allowed = $request->user()->visibleBranchIds();
        abort_if($allowed !== null && ! in_array($employee->branch_id, $allowed, true), 403);
        $employee->update($request->validate([
            'salary' => 'nullable|numeric|min:0|max:10000000',
            'target_multiplier' => 'nullable|numeric|min:0|max:50',
            'section' => ['nullable', \Illuminate\Validation\Rule::in(Employee::SECTIONS)],
        ]));

        return back()->with('status', 'Target settings saved.');
    }

    /** Link a CallGear agent to this employee by hand (when the names differ too much to match). */
    public function linkCallgear(Request $request, Employee $employee)
    {
        $this->authorizeBranch($request, $employee);
        $data = $request->validate(['agent_id' => 'nullable|integer']);
        if (empty($data['agent_id'])) {
            if ($employee->source !== 'callgear' && $employee->callgear_id) {
                // Unlink: give the agent its own record again so its calls aren't lost.
                $agent = Employee::create(['source' => 'callgear', 'first_name' => $employee->first_name, 'last_name' => $employee->last_name, 'callgear_id' => $employee->callgear_id]);
                \App\Models\Call::where('employee_id', $employee->id)->update(['employee_id' => $agent->id]);
                $employee->forceFill(['callgear_id' => null])->save();
            }

            return back()->with('status', 'CallGear agent unlinked.');
        }
        $agent = Employee::where('source', 'callgear')->whereNotNull('callgear_id')->findOrFail($data['agent_id']);
        \App\Services\CallGear\CallGearSource::link($employee, $agent);

        return back()->with('status', "Linked to CallGear agent {$agent->first_name} {$agent->last_name}: their calls now count for {$employee->first_name}.");
    }

    /** Replace one employee's tags with the names typed in (new names become new tags). */
    public function updateTags(Request $request, Employee $employee)
    {
        $this->authorizeBranch($request, $employee);
        $data = $request->validate(['tags' => 'nullable|string|max:1000']);
        $employee->tags()->sync(Tag::idsFor(explode(',', (string) ($data['tags'] ?? ''))));
        Tag::prune();

        return back()->with('status', 'Tags saved.');
    }

    /** Add a tag to, or remove it from, the employees ticked on the list. */
    public function bulkTags(Request $request)
    {
        $data = $request->validate([
            'employee_ids' => 'required|array|max:500',
            'employee_ids.*' => 'integer',
            'tag' => 'required|string|max:60',
            'action' => 'required|in:add,remove',
        ]);
        $allowed = $request->user()->visibleBranchIds();
        $ids = Employee::whereIn('id', $data['employee_ids'])
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed ?: [0]))->pluck('id');

        if ($data['action'] === 'add') {
            Tag::find(Tag::idsFor([$data['tag']])[0] ?? 0)?->employees()->syncWithoutDetaching($ids);
        } else {
            Tag::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['tag']))])->first()?->employees()->detach($ids);
            Tag::prune();
        }

        return back()->with('status', ($data['action'] === 'add' ? 'Tagged ' : 'Untagged ').$ids->count().' employee(s) "'.trim($data['tag']).'".');
    }

    private function authorizeBranch(Request $request, Employee $employee): void
    {
        $allowed = $request->user()->visibleBranchIds();
        abort_if($allowed !== null && ! in_array($employee->branch_id, $allowed, true), 403);
    }
}
