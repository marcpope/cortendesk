@extends('layouts.guest')

@section('title', 'Two-Factor Authentication')

@section('content')
    <div class="card">

        <div class="card-header rd-auth-head py-3 text-center d-flex align-items-center justify-content-center">
            <a href="{{ url('/') }}" class="rd-auth-brand mb-0">
                <img src="{{ asset('assets/images/cortendesk-sm.svg') }}" alt="CortenDesk" width="60" height="60" class="rd-auth-brand-logo">
                <span class="rd-auth-brand-wordmark">Corten<span>Desk</span></span>
            </a>
        </div>

        <div class="card-body p-4">

            <div class="text-center mb-4">
                <h4 class="rd-auth-title">Two-Step Verification</h4>
                <p class="rd-auth-sub">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
            </div>

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.2fa.attempt') }}">
                @csrf

                <div class="mb-3">
                    <label for="code" class="form-label">Authentication code</label>
                    <input class="form-control rd-code-input rd-mono" type="text" id="code" name="code"
                           required autofocus autocomplete="one-time-code" inputmode="text"
                           placeholder="123456">
                    <div class="form-text">A recovery code (XXXXX-XXXXX) works here too.</div>
                </div>

                <div class="mb-0 d-grid">
                    <button class="btn btn-primary" type="submit">
                        <i class="ri-shield-check-line me-1"></i> Verify
                    </button>
                </div>
            </form>

            <div class="rd-auth-foot">
                <a href="{{ route('login') }}">Sign in as someone else</a>
            </div>
        </div>
    </div>
@endsection
