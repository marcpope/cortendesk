@extends('layouts.guest')

@section('title', 'Verify Sign-In')

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
                <h4 class="rd-auth-title">Check your email</h4>
                <p class="rd-auth-sub">
                    This browser is new to the console, so we emailed a 6-digit code @if ($sentTo) to <span class="rd-mono">{{ $sentTo }}</span>@endif. Enter it below.
                </p>
            </div>

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            @if (session('status'))
                <div class="alert alert-success" role="alert">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('login.email.attempt') }}">
                @csrf

                <div class="mb-3">
                    <label for="code" class="form-label">Verification code</label>
                    <input class="form-control rd-code-input rd-mono" type="text" id="code" name="code"
                           required autofocus autocomplete="one-time-code" inputmode="numeric" maxlength="6"
                           placeholder="123456">
                </div>

                <div class="mb-0 d-grid">
                    <button class="btn btn-primary" type="submit">
                        <i class="ri-mail-check-line me-1"></i> Verify
                    </button>
                </div>
            </form>

            <div class="rd-auth-foot">
                <form method="POST" action="{{ route('login.email.resend') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-link btn-sm p-0">Send a new code</button>
                </form>
                <span class="mx-1">·</span>
                <a href="{{ route('login') }}">Sign in as someone else</a>
                <span class="rd-auth-foot-note">
                    Once verified, this browser is remembered for {{ \App\Models\TrustedDevice::trustDays() }} days.
                </span>
            </div>
        </div>
    </div>
@endsection
