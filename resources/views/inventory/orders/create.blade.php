@extends('layouts.app')
@section('title', 'New stock order')
@section('content')
<h1 class="h4 mb-3">New stock order request</h1>
<form method="post" action="{{ route('inventory.orders.store') }}" id="orderForm">@csrf
<div class="card mb-3"><div class="card-body row g-3">
    <div class="col-md-4"><label class="form-label">Order for branch</label>
        <select name="requesting_branch_id" class="form-select" required>@foreach($requestingBranches as $b)<option value="{{ $b->id }}" @selected(old('requesting_branch_id', auth()->user()->branch_id) == $b->id)>{{ $b->name }}</option>@endforeach</select>
        @if($requestingBranches->isEmpty())<div class="form-text text-danger">Your account has no branch. Ask an admin to set one.</div>@endif</div>
    <div class="col-md-4"><label class="form-label">Supply from</label>
        <select name="supplying_branch_id" class="form-select"><option value="">Main stock (decided by stock manager)</option>@foreach($supplyingBranches as $b)<option value="{{ $b->id }}" @selected(old('supplying_branch_id') == $b->id)>{{ $b->name }}{{ $b->is_warehouse ? ' (main)' : '' }}</option>@endforeach</select></div>
    <div class="col-md-4"><label class="form-label">Notes</label><input name="notes" value="{{ old('notes') }}" class="form-control"></div>
</div></div>

<div class="card"><div class="table-responsive"><table class="table mb-0 align-middle" id="lines">
    <thead><tr><th style="min-width:240px">Product</th><th style="width:120px">Qty</th><th class="text-end">Unit price</th><th class="text-end">Tax</th><th class="text-end">Line total</th><th></th></tr></thead>
    <tbody></tbody>
    <tfoot>
        <tr><td colspan="6"><button type="button" class="btn btn-sm btn-outline-primary" id="addLine"><i class="bi bi-plus"></i> Add product</button></td></tr>
        <tr><td colspan="4" class="text-end">Subtotal</td><td class="text-end" id="subtotal">0.00</td><td></td></tr>
        <tr><td colspan="4" class="text-end">Tax</td><td class="text-end" id="taxtotal">0.00</td><td></td></tr>
        <tr class="fw-bold"><td colspan="4" class="text-end">Total</td><td class="text-end" id="grandtotal">0.00</td><td></td></tr>
    </tfoot>
</table></div></div>
<p class="small text-muted mt-2">Prices come from the product list and are recalculated on the server when you submit.</p>
<button class="btn btn-primary mt-2">Send for approval</button>
</form>
@endsection

@push('scripts')
<script>
const PRODUCTS = @json($productOptions);
const cur = @json(config('app.currency_symbol'));
const tbody = document.querySelector('#lines tbody');
let idx = 0;
const money = n => cur + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function addLine() {
    const i = idx++;
    const tr = document.createElement('tr');
    tr.innerHTML = `<td><select name="items[${i}][product_id]" class="form-select form-select-sm prod"><option value="">Choose…</option>${PRODUCTS.map(p => `<option value="${p.id}">${p.name.replace(/</g,'&lt;')}</option>`).join('')}</select></td>
        <td><input type="number" name="items[${i}][quantity]" min="0" step="0.01" value="1" class="form-control form-control-sm qty"></td>
        <td class="text-end price">—</td><td class="text-end tax">—</td><td class="text-end total">—</td>
        <td><button type="button" class="btn btn-sm btn-link text-danger rm"><i class="bi bi-x-lg"></i></button></td>`;
    tbody.appendChild(tr);
    tr.querySelector('.rm').onclick = () => { tr.remove(); recalc(); };
    tr.querySelectorAll('select,input').forEach(el => el.addEventListener('input', recalc));
}

function recalc() {
    let sub = 0, tax = 0;
    tbody.querySelectorAll('tr').forEach(tr => {
        const p = PRODUCTS.find(x => x.id == tr.querySelector('.prod').value);
        const q = parseFloat(tr.querySelector('.qty').value) || 0;
        if (!p) { tr.querySelector('.price').textContent = tr.querySelector('.tax').textContent = tr.querySelector('.total').textContent = '—'; return; }
        const s = Math.round(q * p.price * 100) / 100, t = Math.round(s * p.tax) / 100;
        sub += s; tax += t;
        tr.querySelector('.price').textContent = money(p.price) + ' / ' + p.unit;
        tr.querySelector('.tax').textContent = p.tax + '%';
        tr.querySelector('.total').textContent = money(s + t);
    });
    document.getElementById('subtotal').textContent = money(sub);
    document.getElementById('taxtotal').textContent = money(tax);
    document.getElementById('grandtotal').textContent = money(sub + tax);
}

document.getElementById('addLine').onclick = addLine;
addLine();
</script>
@endpush
