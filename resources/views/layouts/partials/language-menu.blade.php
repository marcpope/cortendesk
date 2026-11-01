{{-- Language switcher: used in the top bar and on the sign-in page. POSTs to
     /locale, which saves the choice and returns to the same page. --}}
<a class="{{ $toggleClass ?? 'nav-link rd-topbar-btn' }} dropdown-toggle rd-no-caret" data-bs-toggle="dropdown" href="#" role="button"
   aria-haspopup="true" aria-expanded="false" title="{{ __('account.language') }}" aria-label="{{ __('account.language') }}">
    <i class="ri-translate-2"></i>
</a>
<div class="dropdown-menu dropdown-menu-end rd-dropdown-slide">
    <form method="POST" action="{{ route('locale') }}">
        @csrf
        @foreach (\App\Models\User::LOCALES as $code => $name)
            <button type="submit" name="locale" value="{{ $code }}" class="dropdown-item {{ app()->getLocale() === $code ? 'active' : '' }}">{{ $name }}</button>
        @endforeach
    </form>
</div>
