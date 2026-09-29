@extends('layouts.app')
@section('title', 'Products')
@section('content')
<div class="d-flex align-items-center mb-3">
    <h1 class="h4 mb-0">Products</h1>
    <a href="{{ route('inventory.products.create') }}" class="btn btn-primary btn-sm ms-auto"><i class="bi bi-plus"></i> Add product</a>
</div>
<form class="mb-3" style="max-width:360px"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Search name or SKU"></form>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><th>SKU</th><th>Name</th><th>Category</th><th>Unit</th><th class="text-end">Price</th><th class="text-end">Tax %</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse($products as $p)
        <tr class="{{ $p->is_active ? '' : 'text-muted' }}">
            <td><code>{{ $p->sku }}</code></td><td>{{ $p->name }}</td><td>{{ $p->category?->name }}</td><td>{{ $p->unit }}</td>
            <td class="text-end">{{ number_format($p->unit_price, 2) }}</td><td class="text-end">{{ $p->tax_rate + 0 }}</td>
            <td>{{ $p->is_active ? 'Active' : 'Inactive' }}</td>
            <td class="text-end"><a href="{{ route('inventory.products.edit', $p) }}" class="btn btn-sm btn-outline-secondary">Edit</a></td>
        </tr>
    @empty
        <tr><td colspan="8" class="text-center text-muted py-4">No products yet.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $products->links() }}</div>
@endsection
