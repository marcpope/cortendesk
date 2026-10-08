@extends('layouts.guest')

@section('title', 'Sign In')

@section('content')
    <div class="card">

        <div class="card-header rd-auth-head py-3 text-center d-flex align-items-center justify-content-center">
            <a href="{{ url('/') }}" class="rd-auth-brand mb-0">
                <img src="{{ asset('assets/images/cortendesk-sm.svg') }}" alt="CortenDesk" width="60" height="60" class="rd-auth-brand-logo">
                <span class="rd-auth-brand-wordmark">Corten<span>Desk</span></span>
            </a>
        </div>

        <div class="card-body p-4">

            @php
                $oidc = app(\App\Services\OidcService::class);
                $ssoEnabled = $oidc->isEnabled();
                $passwordDisabled = $oidc->localLoginDisabled();
            @endphp

            <div class="text-center mb-4">
                <h4 class="rd-auth-title">Sign In</h4>
                <p class="rd-auth-sub">
                    {{ $passwordDisabled
                        ? 'Use your organisation account to access the console.'
                        : 'Enter your username and password to access the console.' }}
                </p>
            </div>

            @if ($httpsOnly ?? false)
                <div class="alert alert-warning" role="alert">
                    Sign-in only works over HTTPS here (<code>SESSION_SECURE_COOKIE=true</code>), and this page was opened over HTTP.
                    Open the console's <code>https://</code> address, or remove <code>SESSION_SECURE_COOKIE</code> to allow sign-in over HTTP.
                </div>
            @endif

            @if (session('status'))
                <div class="alert alert-success" role="alert">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            @if ($ssoEnabled)
                <div class="mb-3 d-grid">
                    <a href="{{ route('login.oidc') }}" class="btn btn-outline-primary">
                        <i class="ri-shield-user-line me-1"></i> {{ $oidc->buttonLabel() }}
                    </a>
                </div>

                @unless ($passwordDisabled)
                    <div class="rd-auth-or">
                        <hr class="flex-grow-1 my-0">
                        <span>or</span>
                        <hr class="flex-grow-1 my-0">
                    </div>
                @endunless
            @endif

            @unless ($passwordDisabled)
            <form method="POST" action="{{ route('login.attempt') }}">
                @csrf

                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input class="form-control" type="text" id="username" name="username"
                           value="{{ old('username') }}" required autofocus autocomplete="username"
                           placeholder="Enter your username">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group input-group-merge">
                        <input type="password" id="password" name="password" class="form-control"
                               required autocomplete="current-password" placeholder="Enter your password">
                        <div class="input-group-text" data-password="false">
                            <span class="rd-password-eye"></span>
                        </div>
                    </div>
                </div>

                <div class="mb-3 d-flex justify-content-between align-items-center">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember" checked>
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>
                    {{-- Only offered when a relay exists to deliver the link. --}}
                    @if (app(\App\Services\MailSettings::class)->isEnabled())
                        <a href="{{ route('password.request') }}" class="rd-auth-quiet">Forgot password?</a>
                    @endif
                </div>

                <div class="mb-0 d-grid">
                    <button class="btn btn-primary" type="submit">
                        <i class="ri-login-circle-fill me-1"></i> Log In
                    </button>
                </div>
            </form>
            @endunless

            {{-- A quiet link to the published client installers. Just a link,
                 not tiles: the sign-in card stays a sign-in card, and the
                 /downloads page is the place that lists builds. Hidden when
                 nothing is published or the operator turns it off. --}}
            @php
                $showDownloads = \App\Models\Setting::get(
                    'downloads_on_login',
                    config('cortendesk.downloads_on_login') ? '1' : '0'
                ) === '1' && \App\Models\ClientDownload::published()->exists();
            @endphp

            @if ($showDownloads)
                <div class="text-center mt-4">
                    <a href="{{ route('downloads.index') }}" class="rd-auth-quiet">
                        <i class="ri-download-2-line me-1"></i>Download the client
                    </a>
                </div>
            @endif
        </div>
    </div>
@endsection
