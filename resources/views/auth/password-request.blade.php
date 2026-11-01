@extends('layouts.guest')

@section('title', __('auth.reset_password'))

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
                <h4 class="rd-auth-title">{{ __('auth.reset_password') }}</h4>
                <p class="rd-auth-sub">{{ __('auth.enter_your_username_or_email') }}</p>
            </div>

            @if (session('status'))
                <div class="alert alert-success" role="alert">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('password.email') }}">
                @csrf
                <div class="mb-3">
                    <label for="login" class="form-label">{{ __('auth.username_or_email') }}</label>
                    <input class="form-control" type="text" id="login" name="login"
                           value="{{ old('login') }}" required autofocus autocomplete="username">
                </div>

                <div class="mb-0 d-grid">
                    <button class="btn btn-primary" type="submit">
                        <i class="ri-mail-send-line me-1"></i> {{ __('auth.send_reset_link') }}
                    </button>
                </div>
            </form>

            <div class="rd-auth-foot">
                <a href="{{ route('login') }}"><i class="ri-arrow-left-line me-1"></i>{{ __('auth.back_to_sign_in') }}</a>
            </div>
        </div>
    </div>
@endsection
