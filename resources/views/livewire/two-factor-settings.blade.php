<div>
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h4 class="header-title">{{ __('common.two_factor_authentication') }}</h4>
            @if ($enabled)
                <span class="badge bg-success-subtle text-success"><i class="rd-dot"></i>{{ __('common.enabled') }}</span>
            @else
                <span class="badge bg-secondary-subtle text-secondary">{{ __('common.disabled') }}</span>
            @endif
        </div>
        <div class="card-body">

            {{-- One-time recovery codes (shown right after enable / regenerate) --}}
            @if (! empty($recoveryCodes))
                <div class="alert alert-warning">
                    {{-- Not .rd-empty-title: that component hard-sets --rd-ink and
                         would drop this heading out of the alert's own colour. --}}
                    <p class="fw-semibold mb-1"><i class="ri-key-2-line me-1"></i>{{ __('account.save_your_recovery_codes') }}</p>
                    <p class="mb-2 fs-13">{{ __('account.each_code_works_once_store') }} <strong>{{ __('account.they_wont_be_shown_again') }}</strong></p>
                    <div class="row row-cols-2 g-1 mb-2" id="recovery-codes">
                        @foreach ($recoveryCodes as $code)
                            <div class="col"><span class="rd-codechip">{{ $code }}</span></div>
                        @endforeach
                    </div>
                    {{-- Copy / Download / "{{ __('account.ive_saved_them') }}" need ~276px side by side and the
                         alert offers ~266px at 390px, so the row wraps rather than squeezing
                         the labels. ms-auto on the last one still pushes it right, on
                         whichever line it lands. --}}
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-light"
                                onclick="rdCopy(Array.from(document.querySelectorAll('#recovery-codes span')).map(e=>e.textContent.trim()).join('\n'), this)">
                            <i class="ri-file-copy-line me-1"></i>{{ __('account.copy') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-light"
                                onclick="(function(){var t=Array.from(document.querySelectorAll('#recovery-codes span')).map(e=>e.textContent.trim()).join('\n');var b=new Blob([t],{type:'text/plain'});var a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='cortendesk-recovery-codes.txt';a.click();})()">
                            <i class="ri-download-2-line me-1"></i>{{ __('common.download') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-primary ms-auto" wire:click="dismissRecoveryCodes">
                            {{ __('account.ive_saved_them') }}
                        </button>
                    </div>
                </div>
            @endif

            @if ($enabled)
                {{-- Enabled state: status + recovery count + disable/regenerate --}}
                @if (empty($recoveryCodes))
                    <p class="text-muted mb-3">
                        {{ __('account.two_factor_authentication_is_protecting') }}
                        @if ($confirmedAt){{ __('account.enabled_since_date', ['date' => $confirmedAt->format('Y-m-d')]) }}@endif
                        {{ trans_choice('account.you_have_count_recovery_code', $remaining) }}
                        @if ($remaining <= 2)
                            <span class="badge bg-warning-subtle text-warning ms-1">{{ __('account.running_low_regenerate_soon') }}</span>
                        @endif
                    </p>

                    @if ($required)
                        <div class="alert alert-info py-2 fs-13"><i class="ri-lock-line me-1"></i>{{ __('account.your_account_requires_two_factor') }}</div>
                    @endif

                    <div class="rd-inset">
                        <label class="form-label">{{ __('account.account_password') }}</label>
                        <input type="password" class="form-control @error('disablePassword') is-invalid @enderror"
                               wire:model="disablePassword" autocomplete="current-password" style="max-width:320px;">
                        @error('disablePassword') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        {{-- "Regenerate recovery codes" alone is ~180px; with "Disable 2FA"
                             beside it the pair overruns the ~266px inside the inset at 390px. --}}
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <button type="button" class="btn btn-outline-light" wire:click="regenerateRecoveryCodes">
                                <i class="ri-refresh-line me-1"></i>{{ __('account.regenerate_recovery_codes') }}
                            </button>
                            @unless ($required)
                                <button type="button" class="btn btn-outline-danger" wire:click="disable">
                                    <i class="ri-shield-cross-line me-1"></i>{{ __('account.disable_2fa') }}
                                </button>
                            @endunless
                        </div>
                        <div class="form-text">{{ __('account.re_enter_your_password_to') }}</div>
                    </div>
                @endif

            @elseif ($settingUp)
                {{-- Wizard: scan QR + confirm code --}}
                <p class="text-muted">{{ __('account.scan_this_qr_code_with') }}</p>

                <div class="row">
                    <div class="col-md-5 text-center mb-3">
                        <div class="d-inline-block bg-white rounded p-2">{!! $qrSvg !!}</div>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label fs-13 text-muted mb-1">{{ __('account.cant_scan_enter_this_key') }}</label>
                        <div class="input-group input-group-sm mb-3">
                            <input type="text" class="form-control rd-mono" readonly value="{{ $secret }}">
                            <button class="btn btn-light" type="button" onclick="rdCopyPrevious(this)"><i class="ri-file-copy-line"></i></button>
                        </div>

                        <form wire:submit="confirmSetup">
                            <label class="form-label">{{ __('common.verification_code') }}</label>
                            <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                                   class="form-control @error('confirmCode') is-invalid @enderror"
                                   wire:model="confirmCode" placeholder="123456" style="max-width:180px;">
                            @error('confirmCode') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                            <div class="d-flex gap-2 mt-3">
                                <button type="submit" class="btn btn-primary"><i class="ri-check-line me-1"></i>{{ __('account.verify_and_enable') }}</button>
                                <button type="button" class="btn btn-outline-light" wire:click="cancelSetup">{{ __('common.cancel') }}</button>
                            </div>
                        </form>
                    </div>
                </div>

            @else
                {{-- Off, not yet setting up --}}
                <p class="text-muted">{{ __('account.add_second_step_to_your') }}</p>
                @if ($required)
                    <div class="alert alert-warning py-2 fs-13"><i class="ri-error-warning-line me-1"></i>{{ __('account.two_factor_authentication_is_required') }}</div>
                @endif
                <button type="button" class="btn btn-primary" wire:click="startSetup">
                    <i class="ri-shield-check-line me-1"></i>{{ __('account.enable_two_factor_authentication') }}
                </button>
            @endif
        </div>
    </div>
</div>
