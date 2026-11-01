<div>
    @php
        $resourceLabels = [
            'device' => __('common.devices'),
            'user' => __('common.users'),
            'group' => __('common.groups'),
            'strategy' => __('common.strategies'),
            'address_book' => __('common.address_books_2'),
            'audit' => __('api_tokens.audit_logs'),
        ];
        $levelLabels = ['none' => __('common.none'), 'r' => __('api_tokens.read'), 'rw' => __('api_tokens.read_write')];
    @endphp

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <div>
                <h4 class="header-title">{{ __('api_tokens.api_tokens') }}</h4>
                <p class="rd-card-sub mb-0">{{ __('api_tokens.scoped_bearer_tokens_for_automation') }}<code>/api/v1/…</code>{{ __('api_tokens.grant_each_token_only_resources') }} <code>docs/admin-api.md</code>.</p>
            </div>
            @if (auth()->user()?->consoleAllows('token', 'rw'))
                <div class="rd-card-actions">
                    <button type="button" class="btn btn-primary" wire:click="create">
                        <i class="ri-add-line"></i>{{ __('api_tokens.new_token') }}
                    </button>
                </div>
            @endif
        </div>

            {{-- One-time plaintext reveal --}}
            @if ($plaintext)
                <div class="rd-toolbar">
                <div class="alert alert-success mb-0 w-100">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="me-2">
                            <strong><i class="ri-check-line me-1"></i>{{ __('api_tokens.token_created') }}</strong>
                            <div class="fs-13">{{ __('api_tokens.copy_it_now_it_is') }}</div>
                        </div>
                        <button type="button" class="btn-close" wire:click="dismissPlaintext" aria-label="{{ __('common.dismiss') }}"></button>
                    </div>
                    <div class="input-group input-group-sm mt-2">
                        <input type="text" class="form-control font-monospace" readonly value="{{ $plaintext }}">
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
                        <th>{{ __('common.name') }}</th>
                        <th>{{ __('common.permissions') }}</th>
                        <th>{{ __('api_tokens.created_by') }}</th>
                        <th>{{ __('api_tokens.last_used') }}</th>
                        <th>{{ __('common.expires') }}</th>
                        <th class="text-end">{{ __('common.action') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($tokens as $token)
                        <tr wire:key="t{{ $token->id }}">
                            <td>
                                <div class="rd-cell rd-tone-amber">
                                    <span class="rd-avatar"><i class="ri-key-2-line"></i></span>
                                    <div class="min-width-0">
                                        <span class="rd-cell-title">{{ $token->name }}</span>
                                        <span class="rd-cell-sub rd-mono">{{ $token->token_prefix }}…</span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @foreach ($token->permissions as $res => $lvl)
                                    @if ($lvl !== 'none')
                                        <span class="badge {{ $lvl === 'rw' ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary' }}">
                                            {{ $resourceLabels[$res] ?? $res }}: {{ strtoupper($lvl) }}
                                        </span>
                                    @endif
                                @endforeach
                            </td>
                            <td>{{ $token->user?->username ?? '—' }}</td>
                            <td>
                                @if ($token->last_used_at)
                                    <span title="{{ $token->last_used_at }}">{{ $token->last_used_at->diffForHumans() }}</span>
                                @else
                                    <span class="text-muted">{{ __('api_tokens.never') }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($token->expires_at)
                                    <span class="{{ $token->isExpired() ? 'text-danger' : '' }}" title="{{ $token->expires_at }}">
                                        {{ $token->expires_at->format('Y-m-d') }}
                                    </span>
                                @else
                                    <span class="text-muted">{{ __('api_tokens.never') }}</span>
                                @endif
                            </td>
                            <td class="text-end rd-rowact">
                                @if (auth()->user()?->consoleAllows('token', 'rw'))
                                    <a href="javascript:void(0);" class="text-danger"
                                       wire:click="revoke({{ $token->id }})"
                                       wire:confirm="{{ __('api_tokens.revoke_token_name_any_script', ['name' => $token->name]) }}">{{ __('common.revoke') }}</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="rd-empty-cell">
                                <div class="rd-empty">
                                    <div class="rd-empty-icon"><i class="ri-key-2-line"></i></div>
                                    <p class="rd-empty-title">{{ __('api_tokens.no_api_tokens_yet') }}</p>
                                    <p class="rd-empty-text">{{ __('api_tokens.token_lets_script_talk_to') }}</p>
                                    @if (auth()->user()?->consoleAllows('token', 'rw'))
                                        <button type="button" class="btn btn-sm btn-outline-light" wire:click="create">{{ __('api_tokens.new_token') }}</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list --}}
            <div class="d-md-none rd-cardlist">
                @forelse ($tokens as $token)
                    <div class="rd-mini" wire:key="mt{{ $token->id }}">
                            <div class="rd-mini-head">
                                <div class="min-width-0">
                                    <span class="rd-mini-title text-truncate">{{ $token->name }}</span>
                                    <span class="rd-mini-sub rd-mono">{{ $token->token_prefix }}…</span>
                                </div>
                                @if (auth()->user()?->consoleAllows('token', 'rw'))
                                    <a href="javascript:void(0);" class="rd-iconbtn text-danger flex-shrink-0" title="{{ __('common.revoke') }}"
                                       wire:click="revoke({{ $token->id }})"
                                       wire:confirm="{{ __('api_tokens.revoke_token_name_any_script', ['name' => $token->name]) }}">
                                        <i class="ri-delete-bin-line"></i>
                                    </a>
                                @endif
                            </div>
                            <div class="mt-2">
                                @foreach ($token->permissions as $res => $lvl)
                                    @if ($lvl !== 'none')
                                        <span class="badge {{ $lvl === 'rw' ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary' }}">
                                            {{ $resourceLabels[$res] ?? $res }}: {{ strtoupper($lvl) }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                            <span class="rd-mini-sub mt-2">
                                {{ __('api_tokens.last_used_when', ['when' => $token->last_used_at ? $token->last_used_at->diffForHumans() : __('common.never')]) }} ·
                                {{ __('api_tokens.expires_when', ['when' => $token->expires_at ? $token->expires_at->format('Y-m-d') : __('common.never')]) }}
                            </span>
                    </div>
                @empty
                    <div class="rd-empty">
                        <div class="rd-empty-icon"><i class="ri-key-2-line"></i></div>
                        <p class="rd-empty-title">{{ __('api_tokens.no_api_tokens_yet') }}</p>
                        @if (auth()->user()?->consoleAllows('token', 'rw'))
                            <button type="button" class="btn btn-sm btn-outline-light" wire:click="create">{{ __('api_tokens.new_token') }}</button>
                        @endif
                    </div>
                @endforelse
            </div>
    </div>

    {{-- Create modal --}}
    @if ($showModal)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form wire:submit="save">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('api_tokens.new_api_token') }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="{{ __('common.close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="at-name">{{ __('common.name') }} <span class="text-danger">*</span></label>
                                <input type="text" id="at-name" class="form-control @error('name') is-invalid @enderror"
                                       wire:model="name" placeholder="{{ __('api_tokens.e_g_ansible_provisioning') }}" autocomplete="off">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="at-expires">{{ __('api_tokens.expires_in_days') }}</label>
                                <input type="number" id="at-expires" min="1" max="3650" style="max-width:160px;"
                                       class="form-control @error('expiresDays') is-invalid @enderror"
                                       wire:model="expiresDays" placeholder="{{ __('api_tokens.never') }}">
                                @error('expiresDays') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">{{ __('api_tokens.leave_blank_for_token_that') }}</div>
                            </div>

                            <label class="form-label">{{ __('common.permissions') }}</label>
                            @unless (auth()->user()?->is_admin)
                                <div class="form-text mb-1">{{ __('api_tokens.token_can_never_be_granted') }}</div>
                            @endunless
                            @error('permissions') <div class="text-danger fs-13 mb-1">{{ $message }}</div> @enderror
                            {{-- Desktop: radio grid --}}
                            <div class="table-responsive d-none d-md-block">
                                <table class="table table-sm table-centered mb-0">
                                    <thead>
                                    <tr>
                                        <th>{{ __('api_tokens.resource') }}</th>
                                        <th class="text-center">{{ __('common.none') }}</th>
                                        <th class="text-center">{{ __('api_tokens.read') }}</th>
                                        <th class="text-center">{{ __('api_tokens.read_write') }}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach ($resources as $res)
                                        <tr wire:key="perm-{{ $res }}">
                                            <td>{{ $resourceLabels[$res] ?? $res }}</td>
                                            @foreach ($levels as $lvl)
                                                <td class="text-center">
                                                    <input class="form-check-input" type="radio"
                                                           aria-label="{{ ($resourceLabels[$res] ?? $res).' '.($levelLabels[$lvl] ?? $lvl) }}"
                                                           wire:model="permissions.{{ $res }}" value="{{ $lvl }}">
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>

                            {{-- Mobile: one select per resource (four radio columns do not fit 390px) --}}
                            <div class="d-md-none">
                                @foreach ($resources as $res)
                                    <div class="mb-2" wire:key="mperm-{{ $res }}">
                                        <label class="form-label mb-1" for="at-perm-{{ $res }}">{{ $resourceLabels[$res] ?? $res }}</label>
                                        <select id="at-perm-{{ $res }}" class="form-select" wire:model="permissions.{{ $res }}">
                                            @foreach ($levels as $lvl)
                                                <option value="{{ $lvl }}">{{ $levelLabels[$lvl] ?? $lvl }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">{{ __('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary">
                                <span wire:loading.remove wire:target="save"><i class="ri-key-2-line me-1"></i>{{ __('api_tokens.create_token') }}</span>
                                <span wire:loading wire:target="save">{{ __('api_tokens.creating') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
