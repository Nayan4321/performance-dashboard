@extends('layouts.app')
@section('title', 'Log in')
@section('content')
@php
    $brandName = \App\Support\Branding::name();
    $logo = \App\Support\Branding::logoDark();
@endphp
<div class="container-xxl">
    <div class="row vh-100 d-flex justify-content-center">
        <div class="col-12 align-self-center">
            <div class="card-body">
                <div class="row">
                    <div class="col-lg-4 col-md-6 mx-auto">
                        <div class="card">
                            <div class="card-body p-0 bg-black auth-header-box rounded-top">
                                <div class="text-center p-3">
                                    <span class="logo logo-admin">
                                        @if($logo)
                                            <img src="{{ $logo }}" alt="{{ $brandName }}" class="auth-logo auth-logo-img">
                                        @else
                                            <span class="brand-initial">{{ mb_substr($brandName, 0, 1) }}</span>
                                        @endif
                                    </span>
                                    <h4 class="mt-3 mb-1 fw-semibold text-white fs-18">Let's Get Started {{ $brandName }}</h4>
                                    <p class="text-muted fw-medium mb-0">{{ \App\Support\Branding::tagline() }}</p>
                                </div>
                            </div>
                            <div class="card-body pt-0">
                                <form class="my-4" method="post" action="{{ route('login') }}">
                                    @csrf
                                    @include('partials.flash')
                                    <div class="form-group mb-2">
                                        <label class="form-label" for="email">Email</label>
                                        <input type="email" class="form-control" id="email" name="email" value="{{ old('email') }}" placeholder="Enter email" required autofocus>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="userpassword">Password</label>
                                        <input type="password" class="form-control" name="password" id="userpassword" placeholder="Enter password" required>
                                    </div>
                                    <div class="form-group row mt-3">
                                        <div class="col-sm-6">
                                            <div class="form-check form-switch form-switch-success">
                                                <input class="form-check-input" type="checkbox" name="remember" id="remember">
                                                <label class="form-check-label" for="remember">Remember me</label>
                                            </div>
                                        </div>
                                        <div class="col-sm-6 text-sm-end">
                                            <span class="text-muted fs-12"><i class="iconoir-lock"></i> Forgot password? Ask admin</span>
                                        </div>
                                    </div>
                                    <div class="d-grid mt-3">
                                        <button class="btn btn-primary" type="submit">Log In <i class="iconoir-arrow-right ms-1 align-middle"></i></button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
