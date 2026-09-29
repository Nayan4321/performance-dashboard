<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Http\Request;

/** Employee activity from Zenoti (booked, changed, cancelled, deleted) and admin notifications. */
class ActivityController extends Controller
{
    public function index(Request $request)
    {
        return view('activity.index', [
            'logs' => $this->logs($request)->paginate(50)->withQueryString(),
            'branches' => $this->branches($request),
        ]);
    }

    public function employee(Request $request, Employee $employee)
    {
        $user = $request->user();
        $allowed = $user->visibleBranchIds();
        abort_if($allowed !== null && ! in_array($employee->branch_id, $allowed, true), 403);
        abort_if(($own = $user->visibleEmployeeId()) !== null && $own !== $employee->id, 403);

        $from = now()->startOfMonth();
        $appointments = $employee->appointments()->where('start_time', '>=', $from);

        return view('activity.employee', [
            'employee' => $employee->load(['branch', 'user.roles']),
            'stats' => [
                'Appointments this month' => (clone $appointments)->count(),
                'Cancelled' => (clone $appointments)->where('status', 'Cancelled')->count(),
                'No-show' => (clone $appointments)->where('status', 'No-show')->count(),
                'Revenue this month' => config('app.currency_symbol').number_format((float) $employee->sales()->where('sold_at', '>=', $from)->sum('net_amount')),
                'Booked this month (any provider)' => $employee->bookedAppointments()->where('booked_at', '>=', $from)->count(),
                'Value of those bookings' => config('app.currency_symbol').number_format((float) $employee->bookedAppointments()->where('booked_at', '>=', $from)->sum('price')),
                'Sales entered this month' => config('app.currency_symbol').number_format((float) $employee->enteredSales()->where('sold_at', '>=', $from)->sum('net_amount')),
            ],
            'lastActivity' => ActivityLog::where('actor_employee_id', $employee->id)->max('occurred_at'),
            'logs' => $this->logs($request)->where(fn ($q) => $q->where('actor_employee_id', $employee->id)->orWhere('employee_id', $employee->id))
                ->paginate(30)->withQueryString(),
        ]);
    }

    public function notifications(Request $request)
    {
        $user = $request->user();
        $list = $user->notifications()->paginate(30);
        $user->unreadNotifications()->update(['read_at' => now()]);

        return view('activity.notifications', ['notifications' => $list]);
    }

    public function markRead(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    protected function logs(Request $request)
    {
        $user = $request->user();
        $allowed = $user->visibleBranchIds();
        $own = $user->visibleEmployeeId();

        return ActivityLog::with(['actor', 'employee', 'branch'])
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed ?: [0]))
            ->when($own !== null, fn ($q) => $q->where(fn ($w) => $w->where('actor_employee_id', $own)->orWhere('employee_id', $own)))
            ->when($request->integer('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))
            ->when($request->action, fn ($q, $a) => $q->where('action', $a))
            ->when($request->q, fn ($q, $s) => $q->where('subject_label', 'like', "%$s%"))
            ->orderByDesc('occurred_at')->orderByDesc('id');
    }

    protected function branches(Request $request)
    {
        $allowed = $request->user()->visibleBranchIds();

        return Branch::when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed ?: [0]))->orderBy('name')->get();
    }
}
