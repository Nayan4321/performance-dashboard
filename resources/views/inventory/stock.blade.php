@extends('layouts.app')
@section('title', 'Stock levels')
@section('content')
<h1 class="h4 mb-3">Stock levels</h1>
@php($canEdit = auth()->user()->can('inventory.products.manage'))
<form method="post" action="{{ route('inventory.stock.update') }}">@csrf @method('put')
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
    <thead><tr><th>Product</th>@foreach($branches as $b)<th class="text-end">{{ $b->name }}@if($b->is_warehouse) <span class="badge text-bg-light border">main</span>@endif</th>@endforeach</tr></thead>
    <tbody>
    @forelse($products as $p)
        <tr><td>{{ $p->name }} <span class="text-muted small">{{ $p->unit }}</span></td>
            @foreach($branches as $b)
                @php($q = $stock[$p->id][$b->id] ?? 0)
                <td class="text-end">
                    @if($canEdit)<input type="number" step="0.01" name="stock[{{ $p->id }}][{{ $b->id }}]" value="{{ $q + 0 }}" class="form-control form-control-sm text-end ms-auto" style="max-width:110px">
                    @else<span class="{{ $q <= 0 ? 'text-danger' : '' }}">{{ $q + 0 }}</span>@endif
                </td>
            @endforeach
        </tr>
    @empty
        <tr><td colspan="{{ $branches->count() + 1 }}" class="text-center text-muted py-4">No products.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
@if($canEdit)<button class="btn btn-primary mt-3">Save stock levels</button>@endif
</form>
<p class="small text-muted mt-2">Stock moves automatically when an order is marked completed: it leaves the supplying branch and is added to the requesting branch.</p>
@endsection
