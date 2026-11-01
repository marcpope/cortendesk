@extends('layouts.guest')

@section('title', __('auth.verify_sign_in'))

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
                <h4 class="rd-auth-title">{{ __('auth.check_your_email') }}</h4>
                <p class="rd-auth-sub">
                    @if ($sentTo)
                        {!! __('auth.this_browser_is_new_to', ['address' => '<span class="rd-mono">'.e($sentTo).'</span>']) !!}
                    @else
                        {{ __('auth.this_browser_is_new_to_2') }}
                    @endif
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
                    <label for="code" class="form-label">{{ __('common.verification_code') }}</label>
                    <input class="form-control rd-code-input rd-mono" type="text" id="code" name="code"
                           required autofocus autocomplete="one-time-code" inputmode="numeric" maxlength="6"
                           placeholder="123456">
                </div>

                <div class="mb-0 d-grid">
                    <button class="btn btn-primary" type="submit">
                        <i class="ri-mail-check-line me-1"></i> {{ __('auth.verify') }}
                    </button>
                </div>
            </form>

            <div class="rd-auth-foot">
                <form method="POST" action="{{ route('login.email.resend') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-link btn-sm p-0">{{ __('auth.send_new_code') }}</button>
                </form>
                <span class="mx-1">·</span>
                <a href="{{ route('login') }}">{{ __('auth.sign_in_as_someone_else') }}</a>
                <span class="rd-auth-foot-note">
                    {{ __('auth.once_verified_this_browser_is', ['days' => \App\Models\TrustedDevice::trustDays()]) }}
                </span>
            </div>
        </div>
    </div>
@endsection
