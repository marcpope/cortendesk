@extends('layouts.guest')

@section('title', 'Choose a New Password')

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
                <h4 class="rd-auth-title">Choose a New Password</h4>
                <p class="rd-auth-sub">This link can be used once. Signing in elsewhere will end when you save.</p>
            </div>

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('password.update', ['token' => $token]) }}">
                @csrf
                <div class="mb-3">
                    <label for="password" class="form-label">New password</label>
                    <input type="password" id="password" name="password" class="form-control"
                           required autofocus autocomplete="new-password">
                    <div class="form-text">At least 8 characters.</div>
                </div>

                <div class="mb-3">
                    <label for="password_confirmation" class="form-label">Confirm new password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation"
                           class="form-control" required autocomplete="new-password">
                </div>

                <div class="mb-0 d-grid">
                    <button class="btn btn-primary" type="submit">
                        <i class="ri-key-2-line me-1"></i> Set Password
                    </button>
                </div>
            </form>

            <div class="rd-auth-foot">
                <a href="{{ route('login') }}"><i class="ri-arrow-left-line me-1"></i>Back to sign in</a>
            </div>
        </div>
    </div>
@endsection
