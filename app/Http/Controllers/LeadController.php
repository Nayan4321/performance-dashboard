<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Manual leads (walk-ins, referrals ...) that sit next to synced Zenoti / CallGear leads in the funnel. */
class LeadController extends Controller
{
    public function index(Request $request)
    {
        $allowed = $request->user()->visibleBranchIds();
        $leads = Lead::with(['branch', 'employee'])
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed ?: [0]))
            ->when($request->stage, fn ($q, $s) => $q->where('stage', $s))
            ->when($request->source, fn ($q, $s) => $q->where('source', $s))
            ->when($request->integer('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))
            ->latest('lead_at')->paginate(30)->withQueryString();

        return view('leads.index', ['leads' => $leads, ...$this->options($request)]);
    }

    public function create(Request $request)
    {
        return view('leads.form', ['lead' => new Lead(['stage' => 'new', 'lead_at' => now()]), ...$this->options($request)]);
    }

    public function store(Request $request)
    {
        Lead::create($this->validated($request) + ['source' => 'manual']);

        return redirect()->route('leads.index')->with('status', 'Lead added.');
    }

    public function edit(Request $request, Lead $lead)
    {
        $this->authorizeLead($request, $lead);

        return view('leads.form', ['lead' => $lead, ...$this->options($request)]);
    }

    public function update(Request $request, Lead $lead)
    {
        $this->authorizeLead($request, $lead);
        // Synced leads: only stage/owner can change locally; the next sync owns the rest.
        $data = $this->validated($request);
        $lead->update($lead->source === 'manual' ? $data : array_intersect_key($data, array_flip(['stage', 'employee_id'])));

        return redirect()->route('leads.index')->with('status', 'Lead saved.');
    }

    public function destroy(Request $request, Lead $lead)
    {
        $this->authorizeLead($request, $lead);
        abort_unless($lead->source === 'manual', 422, 'Synced leads are removed in their source system.');
        $lead->delete();

        return back()->with('status', 'Lead deleted.');
    }

    private function authorizeLead(Request $request, Lead $lead): void
    {
        $allowed = $request->user()->visibleBranchIds();
        abort_unless($allowed === null || in_array($lead->branch_id, $allowed, true), 403);
    }

    private function options(Request $request): array
    {
        $allowed = $request->user()->visibleBranchIds();

        return [
            'branches' => Branch::when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed ?: [0]))->orderBy('name')->get(),
            'employees' => Employee::where('is_active', true)->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed ?: [0]))->orderBy('first_name')->get(),
            'stages' => Lead::STAGES,
        ];
    }

    private function validated(Request $request): array
    {
        $allowed = $request->user()->visibleBranchIds();

        return $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:120',
            'channel' => 'nullable|string|max:60',
            'branch_id' => ['required', 'exists:branches,id', $allowed !== null ? Rule::in($allowed) : 'integer'],
            'employee_id' => 'nullable|exists:employees,id',
            'stage' => ['required', Rule::in(Lead::STAGES)],
            'value' => 'nullable|numeric|min:0',
            'lead_at' => 'required|date',
        ]);
    }
}
