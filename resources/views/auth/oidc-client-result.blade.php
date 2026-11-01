@extends('layouts.guest')

@section('title', $ok ? __('auth.signed_in') : __('auth.sign_in_failed'))

@section('content')
    <div class="card">

        <div class="card-header rd-auth-head py-3 text-center d-flex align-items-center justify-content-center">
            <a href="{{ url('/') }}" class="rd-auth-brand mb-0">
                <img src="{{ asset('assets/images/cortendesk-sm.svg') }}" alt="CortenDesk" width="60" height="60" class="rd-auth-brand-logo">
                <span class="rd-auth-brand-wordmark">Corten<span>Desk</span></span>
            </a>
        </div>

        <div class="card-body p-4 text-center">
            <span class="rd-auth-icon {{ $ok ? 'rd-tone-green' : 'rd-tone-red' }}">
                <i class="{{ $ok ? 'ri-checkbox-circle-line' : 'ri-error-warning-line' }}"></i>
            </span>

            <h4 class="rd-auth-title">
                {{ $ok ? __('auth.signed_in_to_rustdesk') : __('auth.sign_in_failed_2') }}
            </h4>

            <p class="rd-auth-sub mb-0">{{ $message }}</p>

            @unless ($ok)
                <p class="rd-auth-foot-note mt-3">
                    {{ __('auth.return_to_rustdesk_client_and') }}
                </p>
            @endunless
        </div>
    </div>
@endsection
