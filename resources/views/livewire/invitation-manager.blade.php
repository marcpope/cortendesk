<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <div>
                <h4 class="header-title">{{ __('invitations.invitations') }}</h4>
                <p class="rd-card-sub mb-0">
                    {{ __('invitations.invite_someone_by_email_they') }}
                    @unless ($mailEnabled)
                        <span class="text-warning">{{ __('invitations.email_is_not_configured_so') }}<a href="{{ route('settings') }}?tab=email">{{ __('invitations.settings_email_link') }}</a>).</span>
                    @endunless
                </p>
            </div>
            <div class="rd-card-actions">
                <button type="button" class="btn btn-primary" wire:click="create">
                    <i class="ri-user-add-line"></i>{{ __('invitations.invite_user') }}
                </button>
            </div>
        </div>

            {{-- The plaintext token is unrecoverable, so the link is shown once. --}}
            @if ($inviteUrl)
                <div class="rd-toolbar">
                <div class="alert {{ $mailSent ? 'alert-success' : 'alert-warning' }} mb-0 w-100">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="me-2">
                            <strong>
                                <i class="{{ $mailSent ? 'ri-check-line' : 'ri-error-warning-line' }} me-1"></i>
                                {{ $mailSent ? __('invitations.emailed_to', ['email' => $inviteFor]) : __('invitations.invitation_created_but_no_email') }}
                            </strong>
                            <div class="fs-13">{{ __('invitations.copy_link_now_it_is') }}</div>
                        </div>
                        <button type="button" class="btn-close" wire:click="dismissLink" aria-label="{{ __('common.dismiss') }}"></button>
                    </div>
                    <div class="input-group input-group-sm mt-2">
                        <input type="text" class="form-control font-monospace" readonly value="{{ $inviteUrl }}">
                        <button class="btn btn-light" type="button"
                                onclick="rdCopyPrevious(this)">
                            <i class="ri-file-copy-line"></i>
                        </button>
                    </div>
                </div>
                </div>
            @endif

            {{-- Desktop table --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-centered mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('common.email') }}</th>
                        <th>{{ __('common.username') }}</th>
                        <th>{{ __('common.role') }}</th>
                        <th>{{ __('invitations.invited_by') }}</th>
                        <th>{{ __('common.expires') }}</th>
                        <th class="text-end">{{ __('invitations.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($invitations as $invite)
                        <tr wire:key="inv{{ $invite->id }}">
                            <td class="text-truncate" style="max-width:220px;">{{ $invite->email }}</td>
                            <td class="font-monospace">{{ $invite->username }}</td>
                            <td>
                                @if ($invite->is_admin)
                                    <span class="badge bg-danger-subtle text-danger">{{ __('common.administrator') }}</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">{{ __('common.user') }}</span>
                                @endif
                            </td>
                            <td>{{ $invite->inviter?->username ?? '—' }}</td>
                            <td>
                                @if ($invite->isExpired())
                                    <span class="badge bg-danger-subtle text-danger">{{ __('invitations.expired') }}</span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning"
                                          title="{{ $invite->expires_at }}">{{ $invite->expires_at->diffForHumans() }}</span>
                                @endif
                            </td>
                            <td class="text-end rd-rowact">
                                @if (in_array($invite->id, $manageableIds, true))
                                    <a href="javascript:void(0);" class="rd-act me-2" wire:click="resend({{ $invite->id }})"
                                       wire:confirm="{{ __('invitations.re_send_this_invitation_previous') }}">{{ __('invitations.resend') }}</a>
                                    <a href="javascript:void(0);" class="text-danger" wire:click="revoke({{ $invite->id }})"
                                       wire:confirm="{{ __('invitations.revoke_invitation_for_email', ['email' => $invite->email]) }}">{{ __('common.revoke') }}</a>
                                @else
                                    <span class="text-muted fs-13" title="{{ __('invitations.only_full_administrator_can_resend') }}">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="rd-empty-cell">
                                <div class="rd-empty">
                                    <div class="rd-empty-icon"><i class="ri-mail-send-line"></i></div>
                                    <p class="rd-empty-title">{{ __('invitations.no_pending_invitations') }}</p>
                                    <p class="rd-empty-text">{{ __('invitations.invitation_lets_someone_set_their') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list --}}
            <div class="d-md-none rd-cardlist">
                @forelse ($invitations as $invite)
                    <div class="rd-mini" wire:key="minv{{ $invite->id }}">
                            <div class="rd-mini-head">
                                <div class="min-width-0">
                                    <span class="rd-mini-title text-truncate">{{ $invite->email }}</span>
                                    <span class="rd-mini-sub rd-mono">{{ $invite->username }}</span>
                                </div>
                                @if (in_array($invite->id, $manageableIds, true))
                                    <a href="javascript:void(0);" class="rd-iconbtn text-danger flex-shrink-0" title="{{ __('common.revoke') }}"
                                       wire:click="revoke({{ $invite->id }})"
                                       wire:confirm="{{ __('invitations.revoke_invitation_for_email', ['email' => $invite->email]) }}">
                                        <i class="ri-delete-bin-line"></i>
                                    </a>
                                @endif
                            </div>
                            <div class="mt-2">
                                @if ($invite->is_admin)
                                    <span class="badge bg-danger-subtle text-danger">{{ __('common.administrator') }}</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">{{ __('common.user') }}</span>
                                @endif
                                @if ($invite->isExpired())
                                    <span class="badge bg-danger-subtle text-danger">{{ __('invitations.expired') }}</span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning">{{ $invite->expires_at->diffForHumans() }}</span>
                                @endif
                            </div>
                            <div class="rd-mini-foot">
                                <span class="rd-mini-sub">{{ __('invitations.invited_by_name', ['name' => $invite->inviter?->username ?? '—']) }}</span>
                                {{-- A bare text link is an 18px-tall target. Resend is the one action
                                     this card offers besides Revoke, so it gets button chrome and,
                                     with it, the 40px minimum height phones are held to. --}}
                                @if (in_array($invite->id, $manageableIds, true))
                                    <a href="javascript:void(0);" class="btn btn-sm btn-outline-light flex-shrink-0"
                                       wire:click="resend({{ $invite->id }})"
                                       wire:confirm="{{ __('invitations.re_send_this_invitation_previous') }}">{{ __('invitations.resend') }}</a>
                                @endif
                            </div>
                    </div>
                @empty
                    <div class="rd-empty">
                        <div class="rd-empty-icon"><i class="ri-mail-send-line"></i></div>
                        <p class="rd-empty-title">{{ __('invitations.no_pending_invitations') }}</p>
                    </div>
                @endforelse
            </div>
    </div>

    {{-- Invite modal --}}
    @if ($showModal)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form wire:submit="save">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('invitations.invite_user') }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="{{ __('common.close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="inv-email">{{ __('common.email') }} <span class="text-danger">*</span></label>
                                <input type="email" id="inv-email" class="form-control @error('email') is-invalid @enderror"
                                       wire:model="email" autocomplete="off" placeholder="person@example.com">
                                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="inv-username">{{ __('common.username') }} <span class="text-danger">*</span></label>
                                <input type="text" id="inv-username" class="form-control @error('username') is-invalid @enderror"
                                       wire:model="username" autocomplete="off" placeholder="{{ __('invitations.e_g_jsmith') }}">
                                @error('username') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">{{ __('invitations.console_sign_in_is_by') }}</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="inv-name">{{ __('common.display_name') }}</label>
                                <input type="text" id="inv-name" class="form-control @error('name') is-invalid @enderror"
                                       wire:model="name" autocomplete="off" placeholder="{{ __('common.optional') }}">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            @if (auth()->user()?->is_admin)
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="inv-admin" wire:model.live="is_admin">
                                        <label class="form-check-label" for="inv-admin">{{ __('common.administrator') }}</label>
                                    </div>
                                    <div class="form-text">{{ __('invitations.administrators_see_every_device_and') }}</div>
                                </div>
                            @endif

                            <div class="mb-3">
                                <label class="form-label" for="inv-user-groups">{{ __('invitations.user_groups') }}</label>
                                <select id="inv-user-groups" class="form-select" multiple size="4" wire:model="user_group_ids">
                                    @foreach ($userGroups as $group)
                                        <option value="{{ $group->id }}">{{ $group->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            @unless ($is_admin)
                                <div class="mb-3">
                                    <label class="form-label" for="inv-device-groups">{{ __('invitations.device_groups_they_may_access') }}</label>
                                    <select id="inv-device-groups" class="form-select" multiple size="4" wire:model="device_group_ids">
                                        @foreach ($deviceGroups as $group)
                                            <option value="{{ $group->id }}">{{ $group->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endunless

                            <p class="text-muted fs-13 mb-0">
                                {{ __('invitations.link_is_single_use_and', ['hours' => \App\Models\Invitation::expiryHours()]) }}
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">{{ __('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary">
                                <span wire:loading.remove wire:target="save"><i class="ri-mail-send-line me-1"></i>{{ __('invitations.send_invitation') }}</span>
                                <span wire:loading wire:target="save">{{ __('common.sending') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
