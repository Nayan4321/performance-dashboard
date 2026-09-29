@extends('layouts.app')
@section('title', $product->exists ? 'Edit product' : 'Add product')
@section('content')
<h1 class="h4 mb-3">{{ $product->exists ? 'Edit '.$product->name : 'Add product' }}</h1>
<div class="card" style="max-width:720px"><div class="card-body">
<form method="post" action="{{ $product->exists ? route('inventory.products.update', $product) : route('inventory.products.store') }}">
    @csrf @if($product->exists) @method('put') @endif
    <div class="row g-3">
        <div class="col-md-4"><label class="form-label">SKU</label><input name="sku" value="{{ old('sku', $product->sku) }}" class="form-control" required></div>
        <div class="col-md-8"><label class="form-label">Name</label><input name="name" value="{{ old('name', $product->name) }}" class="form-control" required></div>
        <div class="col-md-4"><label class="form-label">Category</label><input name="category" list="cats" value="{{ old('category', $product->category?->name) }}" class="form-control">
            <datalist id="cats">@foreach($categories as $c)<option value="{{ $c->name }}">@endforeach</datalist></div>
        <div class="col-md-2"><label class="form-label">Unit</label><input name="unit" value="{{ old('unit', $product->unit) }}" class="form-control" required></div>
        <div class="col-md-3"><label class="form-label">Unit price</label><input type="number" step="0.01" min="0" name="unit_price" value="{{ old('unit_price', $product->unit_price ?? 0) }}" class="form-control" required></div>
        <div class="col-md-3"><label class="form-label">Tax %</label><input type="number" step="0.01" min="0" max="100" name="tax_rate" value="{{ old('tax_rate', $product->tax_rate ?? 0) }}" class="form-control" required></div>
        <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2">{{ old('description', $product->description) }}</textarea></div>
        <div class="col-12"><input type="hidden" name="is_active" value="0"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="act" @checked(old('is_active', $product->is_active))><label class="form-check-label" for="act">Available for ordering</label></div></div>
    </div>
    <button class="btn btn-primary mt-3">Save</button>
</form>
</div></div>
@endsection
