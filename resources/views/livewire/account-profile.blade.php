<div>
    {{-- ---------------------------------------------------------------- Profile --}}
    <div class="card">
        <div class="card-header">
            <h4 class="header-title">{{ __('common.profile') }}</h4>
        </div>
        <div class="card-body">
            {{-- Identity strip: who you are signed in as, before the fields that change it. --}}
            <div class="rd-cell {{ $user->is_admin ? 'rd-tone-accent' : 'rd-tone-purple' }} mb-3">
                <span class="rd-avatar rd-avatar-lg">{{ strtoupper(substr($user->username, 0, 1)) }}</span>
                <div class="min-width-0">
                    <span class="rd-cell-title">{{ $user->name ?: $user->username }}</span>
                    <span class="rd-cell-sub">
                        {{ $user->email ?: __('account.no_email_on_file') }}
                        @if ($user->isSsoLinked())
                            · {{ __('account.signed_in_with_sso') }}
                        @endif
                    </span>
                </div>
            </div>

            @if ($profileSaved)
                <div class="alert alert-success py-2" wire:poll.4s="$set('profileSaved', false)">
                    <i class="ri-check-line me-1"></i>{{ __('account.profile_saved') }}
                </div>
            @endif

            <form wire:submit="saveProfile">
                <div class="mb-3">
                    <label class="form-label">{{ __('common.username') }}</label>
                    <input type="text" class="form-control" value="{{ $user->username }}" disabled>
                    <div class="form-text">
                        {{ __('account.your_sign_in_name_only') }}
                    </div>
                </div>

                <div class="mb-3">
                    <label for="account-name" class="form-label">{{ __('common.display_name') }}</label>
                    <input type="text" id="account-name" class="form-control @error('name') is-invalid @enderror"
                           wire:model="name" placeholder="{{ __('account.your_name') }}">
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label for="account-email" class="form-label">{{ __('common.email') }}</label>
                    <input type="email" id="account-email" class="form-control @error('email') is-invalid @enderror"
                           wire:model="email" placeholder="you@example.com">
                    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    @if ($user->isSsoLinked())
                        <div class="form-text">
                            <i class="ri-information-line me-1"></i>{{ __('account.your_name_and_email_come') }}
                        </div>
                    @endif
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="ri-save-line me-1"></i>{{ __('account.save_changes') }}
                </button>
            </form>
        </div>
    </div>

    {{-- --------------------------------------------------------------- Password --}}
    @if ($user->isSsoProvisioned())
        <div class="card">
            <div class="card-header">
                <h4 class="header-title">{{ __('common.password') }}</h4>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-start gap-2 rd-inset mb-0">
                    <i class="ri-shield-user-line fs-20 text-primary"></i>
                    <div class="text-muted">
                        {{ __('account.this_account_signs_in_through') }}
                        {{ __('account.manage_your_credentials_with_your') }}
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="card">
            <div class="card-header">
                <h4 class="header-title">{{ __('account.change_password') }}</h4>
            </div>
            <div class="card-body">
                @if ($passwordSaved)
                    <div class="alert alert-success py-2" wire:poll.4s="$set('passwordSaved', false)">
                        <i class="ri-check-line me-1"></i>{{ __('account.password_changed') }}
                    </div>
                @endif

                <form wire:submit="updatePassword">
                    <div class="mb-3">
                        <label for="current-password" class="form-label">{{ __('account.current_password') }}</label>
                        <input type="password" id="current-password" autocomplete="current-password"
                               class="form-control @error('currentPassword') is-invalid @enderror"
                               wire:model="currentPassword">
                        @error('currentPassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label for="new-password" class="form-label">{{ __('common.new_password') }}</label>
                        <input type="password" id="new-password" autocomplete="new-password"
                               class="form-control @error('password') is-invalid @enderror"
                               wire:model="password">
                        @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">{{ __('common.at_least_8_characters') }}</div>
                    </div>

                    <div class="mb-3">
                        <label for="confirm-password" class="form-label">{{ __('common.confirm_new_password') }}</label>
                        <input type="password" id="confirm-password" autocomplete="new-password"
                               class="form-control @error('passwordConfirmation') is-invalid @enderror"
                               wire:model="passwordConfirmation">
                        @error('passwordConfirmation') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <button type="submit" class="btn btn-outline-primary">
                        <i class="ri-key-2-line me-1"></i>{{ __('account.change_password') }}
                    </button>
                </form>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------- Appearance --}}
    <div class="card">
        <div class="card-header">
            <h4 class="header-title">{{ __('account.appearance') }}</h4>
        </div>
        <div class="card-body">
            <div class="btn-group" role="group" aria-label="Theme">
                <input type="radio" class="btn-check" id="theme-dark" value="dark" wire:model.live="theme">
                <label class="btn btn-outline-primary" for="theme-dark"><i class="ri-moon-line me-1"></i>{{ __('account.dark') }}</label>
                <input type="radio" class="btn-check" id="theme-light" value="light" wire:model.live="theme">
                <label class="btn btn-outline-primary" for="theme-light"><i class="ri-sun-line me-1"></i>{{ __('account.light') }}</label>
                <input type="radio" class="btn-check" id="theme-system" value="system" wire:model.live="theme">
                <label class="btn btn-outline-primary" for="theme-system"><i class="ri-computer-line me-1"></i>{{ __('account.match_system') }}</label>
            </div>
            @error('theme') <div class="text-danger fs-13 mt-2">{{ $message }}</div> @enderror
            <p class="text-muted fs-13 mb-0 mt-2">{{ __('account.saved_to_your_account_so') }}</p>

            <hr>
            <label for="account-locale" class="form-label">{{ __('account.language') }}</label>
            <select id="account-locale" class="form-select w-auto @error('locale') is-invalid @enderror" wire:model.live="locale">
                <option value="">{{ __('account.match_browser') }}</option>
                @foreach (\App\Models\User::LOCALES as $code => $name)
                    <option value="{{ $code }}">{{ $name }}</option>
                @endforeach
            </select>
            @error('locale') <div class="text-danger fs-13 mt-2">{{ $message }}</div> @enderror
            <p class="text-muted fs-13 mb-0 mt-2">{{ __('account.saved_to_your_account_anything') }}</p>
        </div>
    </div>
</div>
