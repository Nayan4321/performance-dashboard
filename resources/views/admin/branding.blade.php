@extends('layouts.app')
@section('title', 'Branding & logo')
@section('content')
<div class="page-title-box"><h4 class="page-title">Branding &amp; logo</h4></div>
<form method="post" action="{{ route('admin.branding.update') }}" enctype="multipart/form-data">
    @csrf @method('put')
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card"><div class="card-header"><h4 class="card-title">Name</h4></div><div class="card-body">
                <div class="mb-3">
                    <label class="form-label">App name</label>
                    <input name="brand_name" value="{{ old('brand_name', $name) }}" class="form-control" placeholder="{{ config('app.name') }}" maxlength="60">
                    <div class="form-text">Shown in the sidebar when no logo is uploaded, on the login page and in the browser tab.</div>
                </div>
                <div>
                    <label class="form-label">Login page subtitle</label>
                    <input name="brand_tagline" value="{{ old('brand_tagline', $tagline) }}" class="form-control" placeholder="Sign in to continue to {{ \App\Support\Branding::name() }}." maxlength="120">
                </div>
            </div></div>
        </div>
        <div class="col-lg-7">
            <div class="card"><div class="card-header"><h4 class="card-title">Logos</h4></div><div class="card-body">
                @foreach(\App\Support\Branding::LOGOS as $kind => [$label, $help])
                    @php($url = \App\Support\Branding::url($kind))
                    <div class="d-flex gap-3 align-items-start {{ $loop->last ? '' : 'border-dashed-bottom pb-3 mb-3' }}">
                        <div class="flex-shrink-0 rounded border d-flex align-items-center justify-content-center {{ $kind === 'logo_dark' ? 'bg-dark' : 'bg-light' }}" style="width:140px;height:64px">
                            @if($url)<img src="{{ $url }}" alt="" style="max-width:128px;max-height:52px;object-fit:contain">@else<span class="text-muted small">None</span>@endif
                        </div>
                        <div class="flex-grow-1">
                            <label class="form-label mb-1">{{ $label }}</label>
                            <input type="file" name="{{ $kind }}" accept=".png,.jpg,.jpeg,.svg,.webp,.ico" class="form-control form-control-sm">
                            <div class="form-text">{{ $help }} Max 2 MB.</div>
                            @if($url)
                                <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="remove_{{ $kind }}" value="1" id="rm-{{ $kind }}"><label class="form-check-label small" for="rm-{{ $kind }}">Remove</label></div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div></div>
        </div>
    </div>
    <button class="btn btn-primary">Save branding</button>
</form>
@endsection
