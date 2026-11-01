@extends('layouts.app')

@section('title', __('common.my_account'))
@section('subtitle', __('account.account'))

@section('content')
    @if (session('email_required'))
        <div class="alert alert-warning">
            <i class="ri-mail-line me-1"></i>{{ session('email_required') }}
        </div>
    @elseif (trim((string) auth()->user()->email) === '' && ! auth()->user()->isSsoProvisioned())
        {{-- Not enforced, just overdue: without an address we cannot send an
             invitation, a sign-in code, or anything else this account needs. --}}
        <div class="alert alert-info">
            <i class="ri-mail-line me-1"></i>{{ __('account.your_account_has_no_email') }}
        </div>
    @endif

    @if (session('twofactor_warning'))
        <div class="alert alert-warning">
            <i class="ri-error-warning-line me-1"></i>{{ session('twofactor_warning') }}
        </div>
    @endif

    <div class="row justify-content-center">
        <div class="col-lg-8">
            @livewire(App\Livewire\AccountProfile::class)

            {{-- Same component the dedicated /account/two-factor page uses, so
                 enrollment behaves identically wherever it is reached from. --}}
            @livewire(App\Livewire\TwoFactorSettings::class)
        </div>
    </div>
@endsection
