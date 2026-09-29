<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Browse synced Zenoti records: guests, appointments and sales, scoped to what the user may see. */
class RecordsController extends Controller
{
    public function guests(Request $request)
    {
        [$from, $to] = $this->period($request);
        $query = $this->scoped(Guest::query(), $request, false)
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('first_name', 'like', "%$s%")->orWhere('last_name', 'like', "%$s%")
                ->orWhere('email', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")))
            ->when($request->period !== 'all', fn ($q) => $q->whereBetween('registered_at', [$from, $to]));

        return view('records.guests', [
            'total' => (clone $query)->count(),
            'rows' => $query->with('branch')->withCount('appointments')->latest('registered_at')->paginate(50)->withQueryString(),
            'branches' => $this->branches($request),
            'from' => $from, 'to' => $to,
        ]);
    }

    public function appointments(Request $request)
    {
        [$from, $to] = $this->period($request);
        $base = $this->scoped(Appointment::query(), $request)
            ->whereBetween('start_time', [$from, $to])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('service_name', 'like', "%$s%")
                ->orWhereHas('guest', fn ($g) => $g->where('first_name', 'like', "%$s%")->orWhere('last_name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%"))));

        $byStatus = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('records.appointments', [
            'byStatus' => $byStatus,
            'total' => $byStatus->sum(),
            'rows' => (clone $base)->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->with(['branch', 'employee', 'guest'])->orderByDesc('start_time')->paginate(50)->withQueryString(),
            'branches' => $this->branches($request),
            'from' => $from, 'to' => $to,
        ]);
    }

    public function sales(Request $request)
    {
        [$from, $to] = $this->period($request);
        $base = $this->scoped(Sale::query(), $request)
            ->whereBetween('sold_at', [$from, $to])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('item_name', 'like', "%$s%")->orWhere('invoice_no', 'like', "%$s%")));

        $byCategory = (clone $base)->selectRaw('category, sum(net_amount) as amount, count(*) as n')->groupBy('category')->get()->keyBy(fn ($r) => $r->category ?? 'Other');

        return view('records.sales', [
            'byCategory' => $byCategory,
            'total' => $byCategory->sum('amount'),
            'rows' => (clone $base)->when($request->category, fn ($q, $c) => $q->where('category', $c))
                ->with(['branch', 'employee'])->orderByDesc('sold_at')->paginate(50)->withQueryString(),
            'branches' => $this->branches($request),
            'from' => $from, 'to' => $to,
        ]);
    }

    public function calls(Request $request)
    {
        [$from, $to] = $this->period($request);
        $base = $this->scoped(\App\Models\Call::query(), $request)
            ->whereBetween('started_at', [$from, $to])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('caller', 'like', "%$s%")->orWhere('callee', 'like', "%$s%")
                ->orWhereHas('guest', fn ($g) => $g->where('first_name', 'like', "%$s%")->orWhere('last_name', 'like', "%$s%"))));

        return view('records.calls', [
            'total' => (clone $base)->count(),
            'known' => (clone $base)->whereNotNull('guest_id')->count(),
            'rows' => $base->with(['branch', 'guest', 'employee'])->orderByDesc('started_at')->paginate(50)->withQueryString(),
            'branches' => $this->branches($request),
            'from' => $from, 'to' => $to,
        ]);
    }

    /** Date range from ?period= (today, this_week, this_month, last_month, last_90_days) or ?from=&to=. Defaults to this month. */
    protected function period(Request $request): array
    {
        if ($request->filled('from') || $request->filled('to')) {
            $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->startOfMonth();
            $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

            return [$from, $to];
        }

        return match ($request->period) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'this_week' => [now()->startOfWeek(), now()->endOfWeek()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'last_90_days' => [now()->subDays(89)->startOfDay(), now()->endOfDay()],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    protected function scoped($query, Request $request, bool $hasEmployee = true)
    {
        $user = $request->user();
        $allowed = $user->visibleBranchIds();
        $own = $user->visibleEmployeeId();

        return $query
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed ?: [0]))
            ->when($own !== null, fn ($q) => $hasEmployee ? $q->where('employee_id', $own) : $q->whereHas('appointments', fn ($a) => $a->where('employee_id', $own)))
            ->when($request->integer('branch_id'), fn ($q, $b) => $q->where('branch_id', $b));
    }

    protected function branches(Request $request)
    {
        $allowed = $request->user()->visibleBranchIds();

        return Branch::when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed ?: [0]))->orderBy('name')->get();
    }
}
