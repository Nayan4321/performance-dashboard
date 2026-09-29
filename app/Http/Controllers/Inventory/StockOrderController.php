<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockOrder;
use App\Services\Inventory\StockOrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockOrderController extends Controller
{
    public function __construct(private StockOrderService $service) {}

    public function index(Request $request)
    {
        $orders = StockOrder::visibleTo($request->user())
            ->with(['requestingBranch', 'supplyingBranch', 'requester'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->integer('branch_id'), fn ($q, $b) => $q->where('requesting_branch_id', $b))
            ->latest('id')->paginate(25)->withQueryString();

        $counts = StockOrder::visibleTo($request->user())->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return view('inventory.orders.index', [
            'orders' => $orders,
            'counts' => $counts,
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    public function create(Request $request)
    {
        $products = Product::where('is_active', true)->orderBy('name')->get();

        return view('inventory.orders.create', [
            'productOptions' => $products->map(fn ($p) => [
                'id' => $p->id, 'name' => "{$p->name} ({$p->sku})", 'unit' => $p->unit,
                'price' => (float) $p->unit_price, 'tax' => (float) $p->tax_rate,
            ])->values(),
            'requestingBranches' => $this->requestableBranches($request),
            'supplyingBranches' => Branch::where('is_active', true)->orderByDesc('is_warehouse')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $allowedBranches = $this->requestableBranches($request)->pluck('id')->all();
        $data = $request->validate([
            'requesting_branch_id' => ['required', Rule::in($allowedBranches)],
            'supplying_branch_id' => 'nullable|exists:branches,id|different:requesting_branch_id',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.quantity' => 'nullable|numeric|min:0',
        ]);

        $order = $this->service->create(
            $request->user(), (int) $data['requesting_branch_id'], $data['supplying_branch_id'] ?? null, $data['items'], $data['notes'] ?? null
        );

        return redirect()->route('inventory.orders.show', $order)->with('status', "Order {$order->order_number} sent for approval.");
    }

    public function show(Request $request, StockOrder $order)
    {
        abort_unless(StockOrder::visibleTo($request->user())->whereKey($order->id)->exists(), 403);

        return view('inventory.orders.show', ['order' => $order->load(['items', 'logs.user', 'requestingBranch', 'supplyingBranch', 'requester', 'approver', 'invoice'])]);
    }

    public function updateStatus(Request $request, StockOrder $order)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(StockOrder::STATUS_LABELS))],
            'note' => 'nullable|string|max:1000',
        ]);
        if ($data['status'] === StockOrder::REJECTED && blank($data['note'] ?? null)) {
            return back()->withErrors(['note' => 'Please give a reason for rejecting the order.']);
        }
        $this->service->transition($order, $data['status'], $request->user(), $data['note'] ?? null);

        return back()->with('status', 'Order marked as '.StockOrder::STATUS_LABELS[$data['status']].'.');
    }

    /** The requester (or an approver) can withdraw an order that hasn't been approved yet. */
    public function cancel(Request $request, StockOrder $order)
    {
        $user = $request->user();
        abort_unless($order->requested_by === $user->id || $user->can('inventory.orders.approve'), 403);
        $this->service->transition($order, StockOrder::CANCELLED, $user, $request->input('note'));

        return back()->with('status', 'Order cancelled.');
    }

    private function requestableBranches(Request $request)
    {
        $user = $request->user();
        $all = $user->seesEverything() || $user->can('inventory.orders.approve');

        return Branch::where('is_active', true)->when(! $all, fn ($q) => $q->whereKey($user->branch_id))->orderBy('name')->get();
    }
}
