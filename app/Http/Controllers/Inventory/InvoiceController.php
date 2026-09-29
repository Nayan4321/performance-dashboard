<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\StockOrder;
use App\Services\Inventory\StockOrderService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function generate(Request $request, StockOrder $order, StockOrderService $service)
    {
        abort_unless(StockOrder::visibleTo($request->user())->whereKey($order->id)->exists(), 403);
        $invoice = $service->generateInvoice($order, $request->user());

        return redirect()->route('inventory.invoices.show', $invoice)->with('status', "Invoice {$invoice->invoice_number} ready.");
    }

    public function show(Request $request, Invoice $invoice)
    {
        $this->authorizeInvoice($request, $invoice);

        return view('inventory.invoices.show', ['invoice' => $invoice, 'order' => $invoice->order, 'printable' => false]);
    }

    public function pdf(Request $request, Invoice $invoice)
    {
        $this->authorizeInvoice($request, $invoice);

        return Pdf::loadView('inventory.invoices.pdf', ['invoice' => $invoice, 'order' => $invoice->order])
            ->download($invoice->invoice_number.'.pdf');
    }

    private function authorizeInvoice(Request $request, Invoice $invoice): void
    {
        $invoice->load('order.items', 'order.requestingBranch.organization', 'order.supplyingBranch', 'order.requester', 'order.approver');
        abort_unless(StockOrder::visibleTo($request->user())->whereKey($invoice->stock_order_id)->exists(), 403);
    }
}
