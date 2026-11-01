@extends('layouts.guest')

@section('title', __('auth.accept_invitation'))

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
                <h4 class="rd-auth-title">{{ __('auth.set_your_password') }}</h4>
                <p class="rd-auth-sub">{{ __('auth.you_were_invited_to_this') }}</p>
            </div>

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('invite.accept', $token) }}">
                @csrf

                <div class="mb-3">
                    <label for="username" class="form-label">{{ __('common.username') }}</label>
                    <input class="form-control" type="text" id="username" value="{{ $invitation->username }}" readonly disabled>
                    <div class="form-text">{{ __('auth.chosen_by_your_administrator_sign') }}</div>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">{{ __('common.email') }}</label>
                    <input class="form-control" type="text" id="email" value="{{ $invitation->email }}" readonly disabled>
                </div>

                <div class="mb-3">
                    <label for="name" class="form-label">{{ __('common.display_name') }}</label>
                    <input class="form-control" type="text" id="name" name="name"
                           value="{{ old('name', $invitation->name) }}" autocomplete="name" placeholder="{{ __('common.optional') }}">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">{{ __('common.password') }}</label>
                    <div class="input-group input-group-merge">
                        <input type="password" id="password" name="password" class="form-control"
                               required autofocus autocomplete="new-password" placeholder="{{ __('auth.at_least_8_characters') }}">
                        <div class="input-group-text" data-password="false">
                            <span class="rd-password-eye"></span>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="password_confirmation" class="form-label">{{ __('auth.confirm_password') }}</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control"
                           required autocomplete="new-password" placeholder="{{ __('auth.repeat_password') }}">
                </div>

                <div class="mb-0 d-grid">
                    <button class="btn btn-primary" type="submit">
                        <i class="ri-user-add-line me-1"></i> {{ __('auth.create_my_account') }}
                    </button>
                </div>
            </form>

            <div class="rd-auth-foot">
                {{ __('auth.this_link_works_once_and', ['when' => $invitation->expires_at->diffForHumans()]) }}
            </div>
        </div>
    </div>
@endsection
