<div>
    @php $canManageSettings = auth()->user()?->consoleAllows('setting', 'rw'); @endphp

    @unless ($canManageSettings)
        <div class="alert alert-info py-2">
            <i class="ri-eye-line me-1"></i>{{ __('settings.you_have_view_only_access') }}
        </div>
    @endunless

    @php
        $mailSvc = app(\App\Services\MailSettings::class);
        $mailBroken = \App\Support\LoginEmailVerification::isActive() && ! $mailSvc->isHealthy();
    @endphp

    @if ($mailBroken || session('mail_broken'))
        <div class="alert alert-danger">
            <i class="ri-mail-close-line me-1"></i><strong>{{ __('settings.email_is_failing') }}</strong>
            {{ session('mail_broken') ?: __('settings.nobody_else_can_sign_in') }}
            {{-- SMTP errors routinely carry a long unbroken token (a host, a URL, a
                 certificate subject). .rd-mono breaks anywhere, so it cannot set a
                 min-content wider than the phone and drag the body sideways. --}}
            @if ($mailError = $mailSvc->lastError())
                <div class="mt-2 rd-mono">{{ $mailError }}</div>
            @endif
        </div>
    @endif

    @if ($saved)
        <div class="alert alert-success py-2" wire:poll.4s="$set('saved', false)">
            <i class="ri-check-line me-1"></i>{{ __('settings.settings_saved') }}
        </div>
    @endif

    {{-- Nothing was written if any field failed — say so where it can be seen.
         save() also jumps $tab to the failing field, since one giant validate()
         covers every tab and the error may otherwise render off-screen. --}}
    @if ($errors->any())
        <div class="alert alert-danger py-2">
            <i class="ri-error-warning-line me-1"></i>{{ __('settings.settings_were') }} <strong>{{ __('settings.not') }}</strong> {{ __('settings.saved_fix_highlighted_field_below') }}
        </div>
    @endif

    {{-- Tab nav. Active tab lives in the $tab Livewire prop (survives save re-renders). --}}
    @php
        $tabs = [
            'server'      => ['ri-server-line', __('settings.server')],
            'client'      => ['ri-download-2-line', __('settings.client_setup')],
            'security'    => ['ri-shield-keyhole-line', __('settings.security')],
            'sso'         => ['ri-shield-user-line', __('settings.sso')],
            'email'       => ['ri-mail-send-line', __('common.email')],
            'notifications' => ['ri-notification-3-line', __('devices.notifications')],
            'maintenance' => ['ri-database-2-line', __('settings.maintenance')],
            'backups' => ['ri-archive-line', __('backups.backups')],
        ];
    @endphp
    {{-- The label stays visible at every width. Six tabs do not fit across a
         390px screen, but .rd-tabbar is a horizontal scroller (section 17 of
         cortendesk.css) so the strip slides instead of wrapping — and six
         unlabelled icons, two of them shields, are not a navigation. Livewire
         morphs this list rather than replacing it, so the scroll position
         survives a tab change. --}}
    <ul class="nav nav-tabs nav-bordered rd-tabbar mb-3">
        @foreach ($tabs as $key => [$icon, $label])
            <li class="nav-item">
                <a href="#" wire:click.prevent="$set('tab', '{{ $key }}')"
                   class="nav-link {{ $tab === $key ? 'active' : '' }}">
                    <i class="{{ $icon }} me-1"></i><span>{{ $label }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="tab-content">

        {{-- ============================ SERVER ============================ --}}
        <div class="tab-pane {{ $tab === 'server' ? 'show active' : '' }}">
            <div class="row">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">{{ __('settings.rustdesk_server') }}</h5>
                        </div>
                        <div class="card-body">
                            <form wire:submit="save">
                                <div class="mb-3">
                                    <label class="form-label">{{ __('settings.id_server_hbbs') }}</label>
                                    <input type="text" class="form-control" wire:model="idServer" placeholder="{{ __('settings.e_g_hbbs_example_com') }}">
                                    <div class="form-text">{{ __('settings.host_port_of_rendezvous_server') }}</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">{{ __('settings.relay_server_hbbr') }}</label>
                                    <input type="text" class="form-control" wire:model="relayServer" placeholder="{{ __('settings.e_g_hbbs_example_com_2') }}">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">{{ __('settings.public_key') }}</label>
                                    <input type="text" class="form-control rd-mono" wire:model="publicKey" placeholder="{{ __('settings.contents_of_id_ed25519_pub') }}">
                                    <div class="form-text">{{ __('settings.servers_ed25519_public_key_clients') }} <code>ENCRYPTED_ONLY</code> {{ __('settings.is_enabled') }}</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">{{ __('settings.build_installers_url') }}</label>
                                    <input type="text" class="form-control @error('rdgenUrl') is-invalid @enderror"
                                           wire:model="rdgenUrl" placeholder="https://rdgen.crayoneater.org">
                                    @error('rdgenUrl') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">{{ __('settings.opened_by_sidebars') }} <strong>{{ __('common.build_installers') }}</strong> {{ __('settings.entry_point_it_at_your') }}</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">{{ __('settings.client_url_scheme') }}</label>
                                    <input type="text" class="form-control @error('clientUrlScheme') is-invalid @enderror"
                                           wire:model="clientUrlScheme" placeholder="rustdesk" style="max-width: 280px;">
                                    @error('clientUrlScheme') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">{{ __('settings.used_by_links_that_open') }} <code>level1-tech-support</code>.</div>
                                </div>
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="downloadsOnLogin"
                                               wire:model="downloadsOnLogin">
                                        <label class="form-check-label" for="downloadsOnLogin">{{ __('settings.link_to_client_downloads_on') }}</label>
                                    </div>
                                    <div class="form-text">{{ __('settings.adds_link_beneath_sign_in') }} <a href="{{ route('client-downloads') }}">{{ __('common.client_downloads') }}</a>{{ __('settings.text') }} <a href="{{ route('downloads.index') }}" target="_blank" rel="noopener">/downloads</a> {{ __('settings.page_stays_reachable_either_way') }}</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">{{ __('settings.online_window_seconds') }}</label>
                                    <input type="number" class="form-control @error('onlineWindow') is-invalid @enderror"
                                           wire:model="onlineWindow" min="20" max="600" style="max-width: 140px;">
                                    @error('onlineWindow') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">{{ __('settings.seconds_without_heartbeat_before_device') }}</div>
                                </div>
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="requireDeviceApproval"
                                               wire:model="requireDeviceApproval">
                                        <label class="form-check-label" for="requireDeviceApproval">{{ __('settings.require_device_approval') }}</label>
                                    </div>
                                    <div class="form-text">{{ __('settings.when_on_newly_seen_device') }} <strong>{{ __('common.pending') }}</strong> {{ __('settings.and_hidden_from_console_and') }}</div>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="autoApproveSignedIn"
                                               wire:model="autoApproveSignedIn">
                                        <label class="form-check-label" for="autoApproveSignedIn">{{ __('settings.approve_when_devices_user_signs') }}</label>
                                    </div>
                                    <div class="form-text">{{ __('settings.with_gate_on_pending_device') }}</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="defaultDeviceGroupId">{{ __('settings.default_group_for_new_devices') }}</label>
                                    <select id="defaultDeviceGroupId" class="form-select @error('defaultDeviceGroupId') is-invalid @enderror"
                                            wire:model="defaultDeviceGroupId" style="max-width: 320px;">
                                        <option value="0">{{ __('common.none') }}</option>
                                        @foreach ($deviceGroups as $group)
                                            <option value="{{ $group->id }}">{{ $group->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('defaultDeviceGroupId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">{{ __('settings.every_device_that_first_appears') }}</div>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="newDevicesIncomingOnly"
                                               wire:model="newDevicesIncomingOnly">
                                        <label class="form-check-label" for="newDevicesIncomingOnly">{{ __('settings.new_devices_incoming_only') }}</label>
                                    </div>
                                    <div class="form-text">{{ __('settings.new_devices_incoming_only_help') }}</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="serverAccessMode">{{ __('settings.server_access') }}</label>
                                    <select id="serverAccessMode" class="form-select @error('serverAccessMode') is-invalid @enderror"
                                            wire:model="serverAccessMode" style="max-width: 320px;">
                                        <option value="{{ \App\Services\ServerLink::MODE_OPEN }}">{{ __('settings.open_any_device_with_key') }}</option>
                                        <option value="{{ \App\Services\ServerLink::MODE_APPROVED }}">{{ __('settings.approved_devices_only') }}</option>
                                    </select>
                                    @error('serverAccessMode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">{{ __('settings.enforced_by_id_server_with') }}</div>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="serverIpMatch" wire:model="serverIpMatch">
                                        <label class="form-check-label" for="serverIpMatch">{{ __('settings.identify_signed_out_devices_by') }}</label>
                                    </div>
                                    <div class="form-text">{{ __('settings.approved_devices_only_lets_signed') }}</div>
                                    @php $linkTone = $serverLink['ok'] === true ? 'text-success' : ($serverLink['ok'] === false ? 'text-danger' : 'text-muted'); @endphp
                                    <div class="form-text {{ $linkTone }}" id="server-link-status">
                                        <i class="{{ $serverLink['ok'] === false ? 'ri-error-warning-line' : 'ri-link' }} me-1"></i>{{ __('settings.id_server_link_note', ['note' => \App\Services\ServerLink::translatedNote($serverLink)]) }}
                                        @if ($serverLink['ok'] === false) {{ __('settings.needs_cortendesk_server_1_1') }} @endif
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary" @disabled(! $canManageSettings)><i class="ri-save-line me-1"></i>{{ __('settings.save_settings') }}</button>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">{{ __('settings.relay_servers') }}</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted fs-13 mb-3">{{ __('settings.relay_pool_hbbr_your_rendezvous') }} <code>relay-servers</code> {{ __('settings.config_geo_tags_are_for') }} <strong>{{ __('settings.relay_server_hbbr') }}</strong> {{ __('settings.above_as_fallback') }}</p>

                            <form wire:submit="save">
                                {{-- Address takes the whole first line on a phone; the geo tag and
                                     the remove button share the second. The old split put the button
                                     in a col-1, which is a 17px content box at 390px against the
                                     ~45px a button needs — it wrapped under the inputs. col-sm-auto
                                     sizes the button to itself instead of to a twelfth of the row,
                                     so it stays honest at every width. --}}
                                @forelse ($relayServers as $i => $relay)
                                    <div class="row g-2 mb-2 align-items-start" wire:key="relay-{{ $i }}">
                                        <div class="col-12 col-sm">
                                            <input type="text" class="form-control @error('relayServers.'.$i.'.address') is-invalid @enderror"
                                                   wire:model="relayServers.{{ $i }}.address" placeholder="relay.example.com:21117">
                                            @error('relayServers.'.$i.'.address') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-8 col-sm-4">
                                            <input type="text" class="form-control @error('relayServers.'.$i.'.geo') is-invalid @enderror"
                                                   wire:model="relayServers.{{ $i }}.geo" placeholder="{{ __('settings.geo_tag') }}">
                                            @error('relayServers.'.$i.'.geo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-4 col-sm-auto d-grid">
                                            <button type="button" class="btn btn-light text-danger" wire:click="removeRelay({{ $i }})"
                                                    title="{{ __('settings.remove_relay') }}" @disabled(! $canManageSettings)><i class="ri-close-line"></i></button>
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-muted fs-13 fst-italic mb-2">{{ __('settings.no_relay_servers_configured_using') }}</p>
                                @endforelse

                                <div class="d-flex gap-2 mt-3">
                                    <button type="button" class="btn btn-light" wire:click="addRelay" @disabled(! $canManageSettings)>
                                        <i class="ri-add-line me-1"></i>{{ __('settings.add_relay') }}
                                    </button>
                                    <button type="submit" class="btn btn-primary" @disabled(! $canManageSettings)><i class="ri-save-line me-1"></i>{{ __('settings.save_settings') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ========================= CLIENT SETUP ========================= --}}
        <div class="tab-pane {{ $tab === 'client' ? 'show active' : '' }}">
            <div class="row">
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">{{ __('settings.client_setup') }}</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted fs-13">{{ __('settings.point_rustdesk_clients_at_this') }}
                                <strong>{{ __('common.rustdesk_network_path') }}</strong>:</p>

                            <label class="form-label fs-13 text-muted mb-0">{{ __('settings.id_server') }}</label>
                            <div class="input-group input-group-sm mb-2">
                                <input type="text" class="form-control rd-mono" readonly value="{{ $idServer }}">
                                <button class="btn btn-light" type="button" onclick="rdCopyPrevious(this)"><i class="ri-file-copy-line"></i></button>
                            </div>

                            <label class="form-label fs-13 text-muted mb-0">{{ __('settings.relay_server') }}</label>
                            <div class="input-group input-group-sm mb-2">
                                <input type="text" class="form-control rd-mono" readonly value="{{ $relayServer }}">
                                <button class="btn btn-light" type="button" onclick="rdCopyPrevious(this)"><i class="ri-file-copy-line"></i></button>
                            </div>

                            <label class="form-label fs-13 text-muted mb-0">{{ __('settings.api_server') }}</label>
                            <div class="input-group input-group-sm mb-2">
                                <input type="text" class="form-control rd-mono" readonly value="{{ $apiUrl }}">
                                <button class="btn btn-light" type="button" onclick="rdCopyPrevious(this)"><i class="ri-file-copy-line"></i></button>
                            </div>

                            <label class="form-label fs-13 text-muted mb-0">{{ __('settings.key') }}</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control rd-mono" readonly value="{{ $publicKey }}">
                                <button class="btn btn-light" type="button" onclick="rdCopyPrevious(this)"><i class="ri-file-copy-line"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- =========================== SECURITY =========================== --}}
        <div class="tab-pane {{ $tab === 'security' ? 'show active' : '' }}">
            <div class="row">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">{{ __('common.two_factor_authentication') }}</h5>
                        </div>
                        <div class="card-body">
                            <form wire:submit="save">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="twoFactorRequired"
                                               wire:model="twoFactorRequired">
                                        <label class="form-check-label" for="twoFactorRequired">{{ __('settings.require_two_factor_authentication_for_2') }}</label>
                                    </div>
                                    <div class="form-text">{{ __('settings.signed_in_users_without_2fa') }}</div>
                                </div>
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="twoFactorRequiredAdmins"
                                               wire:model="twoFactorRequiredAdmins" @if($twoFactorRequired) disabled @endif>
                                        <label class="form-check-label" for="twoFactorRequiredAdmins">{{ __('settings.require_two_factor_authentication_for') }}</label>
                                    </div>
                                    <div class="form-text">{{ __('settings.enforce_2fa_for_admin_accounts') }}</div>
                                </div>

                                {{-- Email sign-in verification sits beside 2FA because it is the
                                     same kind of policy; the relay itself is configured on the Email tab. --}}
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="emailLoginVerification"
                                               wire:model="emailLoginVerification" @unless($mailEnabled) disabled @endunless>
                                        <label class="form-check-label" for="emailLoginVerification">{{ __('settings.require_emailed_code_on_new') }}</label>
                                    </div>
                                    <div class="form-text">
                                        @if ($mailEnabled)
                                            {{ __('settings.users_without_authenticator_app_get', ['days' => $emailTrustedDeviceDays]) }}
                                            @if ($usersWithoutEmail > 0)
                                                <span class="text-warning d-block mt-1">
                                                    {{ trans_choice('settings.count_active_account_s_have', $usersWithoutEmail) }}
                                                </span>
                                            @else
                                                <span class="d-block mt-1">{{ __('settings.account_with_no_email_address') }}</span>
                                            @endif
                                        @else
                                            {{ __('settings.configure_and_enable_mail_relay') }} <a href="#" wire:click.prevent="$set('tab', 'email')">{{ __('common.email') }}</a>
                                            {{ __('settings.tab_first_without_one_there') }}
                                        @endif
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">{{ __('settings.remember_verified_browser_for_days') }}</label>
                                    <input type="number" class="form-control @error('emailTrustedDeviceDays') is-invalid @enderror"
                                           wire:model="emailTrustedDeviceDays" min="1" max="365" style="max-width: 140px;">
                                    @error('emailTrustedDeviceDays') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">{{ __('settings.trust_is_revoked_immediately_by') }}</div>
                                </div>

                                <button type="submit" class="btn btn-primary" @disabled(! $canManageSettings)><i class="ri-save-line me-1"></i>{{ __('settings.save_settings') }}</button>
                            </form>
                        </div>
                    </div>

                    {{-- API tokens live under Security — stable key keeps it mounted across saves --}}
                    {{-- Nested component: a 403 in the child kills the whole Settings page,
                         so a role with settings but no token access simply does not render it. --}}
                    @if (auth()->user()?->consoleAllows('token', 'r'))
                        @livewire(App\Livewire\ApiTokenManager::class, [], 'api-tokens')
                    @endif
                </div>
            </div>
        </div>

        {{-- ============================= SSO ============================= --}}
        <div class="tab-pane {{ $tab === 'sso' ? 'show active' : '' }}">
            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">{{ __('settings.single_sign_on_oidc') }}</h5>
                            @if ($oidcEnabled)
                                <span class="badge bg-success-subtle text-success">{{ __('common.enabled') }}</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">{{ __('common.disabled') }}</span>
                            @endif
                        </div>
                        <div class="card-body">
                            <p class="text-muted fs-13">
                                {{ __('settings.let_operators_sign_in_with') }}
                            </p>
                            <div class="mb-3">
                                <input type="text" class="form-control form-control-sm rd-mono"
                                       value="{{ $oidcCallbackUrl }}" readonly onfocus="this.select()">
                            </div>

                            <form wire:submit="save">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="oidcEnabled"
                                               wire:model="oidcEnabled">
                                        <label class="form-check-label" for="oidcEnabled">{{ __('settings.enable_single_sign_on') }}</label>
                                    </div>
                                    <div class="form-text">{{ __('settings.adds_sign_in_button_to') }}</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">{{ __('settings.provider_url') }}</label>
                                    <input type="text" class="form-control @error('oidcDiscoveryUrl') is-invalid @enderror"
                                           wire:model="oidcDiscoveryUrl" placeholder="https://idp.example.com/realms/main">
                                    @error('oidcDiscoveryUrl') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">
                                        {{ __('settings.issuer_url') }} <code>/.well-known/openid-configuration</code> {{ __('settings.is_appended_automatically_or_paste') }}
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">{{ __('settings.client_id') }}</label>
                                        <input type="text" class="form-control" wire:model="oidcClientId">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">{{ __('settings.client_secret') }}</label>
                                        <input type="password" class="form-control" wire:model="oidcClientSecret"
                                               autocomplete="new-password"
                                               placeholder="{{ $oidcClientSecretSet ? __('settings.stored_leave_blank_to_keep') : __('roles.required') }}">
                                        <div class="form-text">{{ __('settings.encrypted_at_rest_blank_leaves_2') }}</div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="testOidc"
                                            wire:loading.attr="disabled" wire:target="testOidc" @disabled(! $canManageSettings)>
                                        <i class="ri-plug-line me-1"></i>
                                        <span wire:loading.remove wire:target="testOidc">{{ __('settings.test_connection') }}</span>
                                        <span wire:loading wire:target="testOidc">{{ __('settings.contacting_provider') }}</span>
                                    </button>
                                    @if ($oidcTestMessage)
                                        <div class="mt-2 alert {{ $oidcTestOk ? 'alert-success' : 'alert-danger' }} py-2 mb-0 fs-13">
                                            {{ $oidcTestMessage }}
                                        </div>
                                    @endif
                                </div>

                                <hr>

                                <div class="mb-3">
                                    <label class="form-label">{{ __('settings.new_users') }}</label>
                                    <select class="form-select" wire:model="oidcNewUserPolicy">
                                        <option value="deny">{{ __('settings.deny_only_existing_console_accounts') }}</option>
                                        <option value="pending">{{ __('settings.create_pending_administrator_approval') }}</option>
                                        <option value="active">{{ __('settings.create_and_allow_in_immediately') }}</option>
                                    </select>
                                    <div class="form-text">
                                        {{ __('settings.applies_when_providers_identity_matches') }}
                                        {{ __('settings.pending_accounts_show_under_users') }}
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="oidcRequireVerifiedEmail"
                                               wire:model="oidcRequireVerifiedEmail">
                                        <label class="form-check-label" for="oidcRequireVerifiedEmail">{{ __('settings.require_provider_to_assert_email') }}</label>
                                    </div>
                                    <div class="form-text">
                                        {{ __('settings.off_by_default_because_claim') }}
                                        <code>false</code> {{ __('settings.for_everyone_accounts_are_matched') }}
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">{{ __('settings.default_group_for_new_users') }}</label>
                                        <select class="form-select" wire:model="oidcDefaultGroupId">
                                            <option value="0">{{ __('common.none') }}</option>
                                            @foreach ($userGroups as $group)
                                                <option value="{{ $group->id }}">{{ $group->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">{{ __('settings.allowed_email_domains') }}</label>
                                        <input type="text" class="form-control" wire:model="oidcAllowedDomains"
                                               placeholder="example.com, example.org">
                                        <div class="form-text">{{ __('settings.blank_allows_any_domain_provider') }}</div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="oidcDefaultAdmin"
                                               wire:model="oidcDefaultAdmin">
                                        <label class="form-check-label" for="oidcDefaultAdmin">{{ __('settings.make_new_sso_users_administrators') }}</label>
                                    </div>
                                    <div class="form-text text-warning">
                                        {{ __('settings.leave_off_unless_every_account') }}
                                    </div>
                                </div>

                                <hr>

                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="oidcLogoutEnabled"
                                               wire:model="oidcLogoutEnabled">
                                        <label class="form-check-label" for="oidcLogoutEnabled">{{ __('settings.sign_out_of_provider_too') }}</label>
                                    </div>
                                    <div class="form-text">{{ __('settings.ends_provider_session_on_logout') }}</div>
                                </div>

                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="oidcDisableLocalLogin"
                                               wire:model="oidcDisableLocalLogin">
                                        <label class="form-check-label" for="oidcDisableLocalLogin">{{ __('settings.disable_password_sign_in') }}</label>
                                    </div>
                                    <div class="form-text">
                                        {{ __('settings.turns_away_passwords_on_console') }}
                                        <code>CORTENDESK_OIDC_DISABLED=true</code> {{ __('settings.in_file') }} <code>.env</code> {{ __('settings.forces_it_off_from_shell') }}
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">{{ __('settings.advanced') }}</label>
                                    <input type="text" class="form-control mb-2" wire:model="oidcScopes" placeholder="openid email profile">
                                    <div class="form-text mb-2">{{ __('settings.scopes_requested_from_provider') }}</div>
                                    <input type="text" class="form-control mb-2" wire:model="oidcButtonLabel" placeholder="{{ __('settings.sign_in_with_sso') }}">
                                    <div class="form-text mb-2">{{ __('settings.label_for_login_page_button') }}</div>
                                    <input type="text" class="form-control" wire:model="oidcPublicBaseUrl"
                                           placeholder="https://idp.example.com">
                                    <div class="form-text">
                                        {{ __('settings.browser_facing_provider_url_only') }}
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary" @disabled(! $canManageSettings)><i class="ri-save-line me-1"></i>{{ __('settings.save_settings') }}</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================ EMAIL ============================ --}}
        <div class="tab-pane {{ $tab === 'email' ? 'show active' : '' }}">
            <div class="row">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">{{ __('settings.outbound_email_smtp') }}</h5>
                            @if ($smtpEnabled)
                                <span class="badge bg-success-subtle text-success">{{ __('common.enabled') }}</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">{{ __('common.disabled') }}</span>
                            @endif
                        </div>
                        <div class="card-body">
                            <p class="text-muted fs-13">
                                {{ __('settings.used_for_user_invitations_and') }}
                            </p>

                            <form wire:submit="save">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="smtpEnabled"
                                               wire:model="smtpEnabled">
                                        <label class="form-check-label" for="smtpEnabled">{{ __('settings.enable_outbound_email') }}</label>
                                    </div>
                                    <div class="form-text">{{ __('settings.off_means_nothing_is_ever') }}</div>
                                </div>

                                <div class="row">
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label">{{ __('settings.smtp_host') }}</label>
                                        <input type="text" class="form-control @error('smtpHost') is-invalid @enderror"
                                               wire:model="smtpHost" placeholder="smtp.example.com">
                                        @error('smtpHost') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">{{ __('common.port') }}</label>
                                        <input type="number" class="form-control @error('smtpPort') is-invalid @enderror"
                                               wire:model="smtpPort" min="1" max="65535">
                                        @error('smtpPort') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">{{ __('settings.encryption') }}</label>
                                    <select class="form-select" wire:model="smtpEncryption" style="max-width: 420px;">
                                        <option value="starttls">{{ __('settings.starttls_usually_port_587') }}</option>
                                        <option value="ssl">{{ __('settings.implicit_tls_ssl_usually_port') }}</option>
                                        <option value="none">{{ __('settings.none_starttls_still_used_if') }}</option>
                                    </select>
                                    <div class="form-text">
                                        {{ __('settings.mail_library_upgrades_plain_connection') }}
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">{{ __('common.username') }}</label>
                                        <input type="text" class="form-control @error('smtpUsername') is-invalid @enderror"
                                               wire:model="smtpUsername" autocomplete="off" placeholder="{{ __('common.optional') }}">
                                        @error('smtpUsername') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">{{ __('common.password') }}</label>
                                        <input type="password" class="form-control @error('smtpPassword') is-invalid @enderror"
                                               wire:model="smtpPassword" autocomplete="new-password"
                                               placeholder="{{ $smtpPasswordSet ? __('settings.stored_leave_blank_to_keep') : __('common.optional') }}">
                                        @error('smtpPassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        <div class="form-text">{{ __('settings.encrypted_at_rest_blank_leaves') }}</div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">{{ __('settings.from_address') }}</label>
                                        <input type="text" class="form-control @error('smtpFromAddress') is-invalid @enderror"
                                               wire:model="smtpFromAddress" placeholder="console@example.com">
                                        @error('smtpFromAddress') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        <div class="form-text">{{ __('settings.must_be_address_your_relay') }}</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">{{ __('settings.from_name') }}</label>
                                        <input type="text" class="form-control @error('smtpFromName') is-invalid @enderror"
                                               wire:model="smtpFromName" placeholder="{{ config('app.name') }}">
                                        @error('smtpFromName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary" @disabled(! $canManageSettings)><i class="ri-save-line me-1"></i>{{ __('settings.save_settings') }}</button>
                            </form>

                            <hr>

                            <label class="form-label">{{ __('settings.send_test_email') }}</label>
                            <div class="row g-2 align-items-start">
                                <div class="col-sm-7">
                                    <input type="email" class="form-control" wire:model="smtpTestTo" placeholder="you@example.com">
                                </div>
                                <div class="col-sm-5 d-grid">
                                    <button type="button" class="btn btn-outline-secondary" wire:click="sendTestEmail"
                                            wire:loading.attr="disabled" wire:target="sendTestEmail" @disabled(! $canManageSettings)>
                                        <i class="ri-send-plane-line me-1"></i>
                                        <span wire:loading.remove wire:target="sendTestEmail">{{ __('settings.send_test_email_2') }}</span>
                                        <span wire:loading wire:target="sendTestEmail">{{ __('common.sending') }}</span>
                                    </button>
                                </div>
                            </div>
                            <div class="form-text">{{ __('settings.uses') }} <strong>{{ __('settings.saved') }}</strong> {{ __('settings.settings_not_what_is_typed') }}</div>
                            @if ($smtpTestMessage)
                                <div class="mt-2 alert {{ $smtpTestOk ? 'alert-success' : 'alert-danger' }} py-2 mb-0 fs-13">
                                    {{ $smtpTestMessage }}
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ======================== NOTIFICATIONS ======================== --}}
        <div class="tab-pane {{ $tab === 'notifications' ? 'show active' : '' }}">
            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0">{{ __('settings.apprise_notifications') }}</h5></div>
                        <div class="card-body">
                            <p class="text-muted fs-13">{{ __('settings.send_fleet_and_security_events') }}</p>
                            <form wire:submit="save">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="appriseEnabled" wire:model="appriseEnabled">
                                    <label class="form-check-label" for="appriseEnabled">{{ __('settings.enable_notifications') }}</label>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">{{ __('settings.apprise_api_endpoint') }}</label>
                                    <input type="url" class="form-control @error('appriseEndpoint') is-invalid @enderror" wire:model="appriseEndpoint"
                                           autocomplete="off" data-1p-ignore data-lpignore="true"
                                           placeholder="{{ $appriseEndpointSet ? __('settings.stored_leave_blank_to_keep') : 'https://apprise.example.com' }}">
                                    @error('appriseEndpoint')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <div class="form-text">{{ __('settings.base_url_only_cortendesk_appends') }} <code>/notify</code>.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">{{ __('settings.delivery_mode') }}</label>
                                    <select class="form-select" wire:model.live="appriseMode">
                                        <option value="config">{{ __('settings.saved_apprise_configuration_key') }}</option>
                                        <option value="urls">{{ __('settings.stateless_apprise_urls') }}</option>
                                    </select>
                                </div>
                                @if ($appriseMode === 'config')
                                    <div class="mb-3"><label class="form-label">{{ __('settings.configuration_key') }}</label><input type="password" class="form-control" wire:model="appriseConfigKey"
                                               autocomplete="new-password" data-1p-ignore data-lpignore="true"
                                               placeholder="{{ $appriseConfigKeySet ? __('settings.stored_leave_blank_to_keep') : 'office-alerts' }}"></div>
                                @else
                                    <div class="mb-3"><label class="form-label">{{ __('settings.apprise_urls') }}</label><textarea class="form-control rd-mono" rows="4" wire:model="appriseUrls"
                                                       autocomplete="off" data-1p-ignore data-lpignore="true"
                                                       placeholder="{{ $appriseUrlsSet ? __('settings.stored_leave_blank_to_keep') : __('settings.one_apprise_url_per_line') }}"></textarea><div class="form-text">{{ __('settings.write_only_and_encrypted_at') }}</div></div>
                                @endif
                                <div class="mb-3"><label class="form-label">{{ __('settings.cooldown_minutes') }}</label><input type="number" class="form-control" style="max-width: 140px" min="0" max="1440" wire:model="appriseCooldownMinutes"></div>
                                <div class="mb-3"><label class="form-label">{{ __('settings.offline_alert_grace_minutes') }}</label><input type="number" class="form-control @error('appriseOfflineGraceMinutes') is-invalid @enderror" style="max-width: 140px" min="0" max="1440" wire:model="appriseOfflineGraceMinutes"><div class="form-text">{{ __('settings.wait_this_long_after_device') }}</div>@error('appriseOfflineGraceMinutes')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="row">
                                    @foreach ($appriseEventLabels as $event => $label)
                                        @php $eventKey = str_replace('.', '_', $event); @endphp
                                        <div class="col-md-6 mb-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="event-{{ str_replace('.', '-', $event) }}" wire:model="appriseEvents.{{ $eventKey }}">
                                                <label class="form-check-label" for="event-{{ str_replace('.', '-', $event) }}">{{ $label }}</label>
                                            </div>
                                            @if (in_array($event, \App\Services\AppriseNotifications::SCOPED_EVENTS, true))
                                                <select class="form-select form-select-sm mt-2" wire:model.live="appriseScopes.{{ $eventKey }}">
                                                    <option value="all">{{ __('settings.all_approved_devices') }}</option>
                                                    <option value="selected">{{ __('settings.selected_groups_or_devices') }}</option>
                                                </select>
                                                @if (($appriseScopes[$eventKey] ?? 'all') === 'selected')
                                                    <select class="form-select form-select-sm mt-2" multiple wire:model="appriseScopeGroups.{{ $eventKey }}" aria-label="Groups for {{ $label }}">
                                                        @foreach ($appriseDeviceGroups as $group)<option value="{{ $group->id }}">Group: {{ $group->name }}</option>@endforeach
                                                    </select>
                                                    <select class="form-select form-select-sm mt-2" multiple wire:model="appriseScopeDevices.{{ $eventKey }}" aria-label="Devices for {{ $label }}">
                                                        @foreach ($appriseDevices as $device)<option value="{{ $device->id }}">{{ $device->alias ?: ($device->hostname ?: $device->rustdesk_id) }}</option>@endforeach
                                                    </select>
                                                @endif
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                <button class="btn btn-primary mt-2" type="submit" @disabled(! $canManageSettings)><i class="ri-save-line me-1"></i>{{ __('settings.save_settings_2') }}</button>
                                <button class="btn btn-outline-secondary mt-2" type="button" wire:click="sendTestNotification" @disabled(! $canManageSettings)>{{ __('settings.send_test') }}</button>
                                @if ($appriseTestMessage)<div class="alert {{ $appriseTestOk ? 'alert-success' : 'alert-danger' }} py-2 mt-3 mb-0">{{ $appriseTestMessage }}</div>@endif
                            </form>
                        </div>
                    </div>
                    @if (auth()->user()?->is_admin)
                        <div class="card">
                            <div class="card-header"><h5 class="card-title mb-0">{{ __('settings.presence_alert_maintenance') }}</h5></div>
                        <div class="card-body">
                            <p class="text-muted fs-13">{{ __('settings.temporarily_suppress_offline_and_recover') }}</p>
                            <form wire:submit="createPresenceSnooze" class="row g-2 align-items-end">
                                <div class="col-md-3"><label class="form-label">{{ __('settings.apply_to') }}</label><select class="form-select" wire:model.live="presenceSnoozeTargetType"><option value="device">{{ __('common.device') }}</option><option value="group">{{ __('common.device_group') }}</option></select></div>
                                <div class="col-md-5"><label class="form-label">{{ __('common.target') }}</label><select class="form-select @error('presenceSnoozeTargetId') is-invalid @enderror" wire:model="presenceSnoozeTargetId"><option value="0">{{ __('settings.choose_target') }}</option>@if($presenceSnoozeTargetType === 'group')@foreach($presenceSnoozeGroups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach @else @foreach($presenceSnoozeDevices as $device)<option value="{{ $device->id }}">{{ $device->alias ?: ($device->hostname ?: $device->rustdesk_id) }}</option>@endforeach @endif</select></div>
                                <div class="col-md-2"><label class="form-label">{{ __('settings.minutes') }}</label><input type="number" class="form-control @error('presenceSnoozeMinutes') is-invalid @enderror" min="15" max="10080" wire:model="presenceSnoozeMinutes"></div>
                                <div class="col-md-2"><button class="btn btn-outline-warning w-100" type="submit" @disabled(! $canManageSettings)>{{ __('settings.snooze_alerts') }}</button></div>
                            </form>
                            @error('presenceSnoozeTargetId')<div class="text-danger fs-13 mt-2">{{ $message }}</div>@enderror @error('presenceSnoozeMinutes')<div class="text-danger fs-13 mt-2">{{ $message }}</div>@enderror
                            <div class="mt-3">
                                @forelse($presenceSnoozes as $snooze)
                                    @php $target = $snooze->target_type === 'group' ? $presenceSnoozeGroups->firstWhere('id', $snooze->target_id) : $presenceSnoozeDevices->firstWhere('id', $snooze->target_id); @endphp
                                    <div class="d-flex justify-content-between align-items-center border-top py-2"><div><strong>{{ $snooze->target_type === 'group' ? __('common.group') : __('common.device') }}: {{ $target?->name ?: $target?->alias ?: $target?->hostname ?: $target?->rustdesk_id ?: __('settings.deleted_target') }}</strong><div class="text-muted fs-13">{{ __('settings.alerts_muted_until_until_when', ['until' => $snooze->expires_at->toDayDateTimeString(), 'when' => $snooze->expires_at->diffForHumans()]) }}</div></div><button type="button" class="btn btn-sm btn-outline-secondary" wire:click="clearPresenceSnooze({{ $snooze->id }})" @disabled(! $canManageSettings)>{{ __('settings.end_now') }}</button></div>
                                @empty
                                    <div class="text-muted fs-13">{{ __('settings.no_active_presence_alert_maintenance') }}</div>
                                @endforelse
                            </div>
                        </div>
                        </div>
                    @endif
                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0">{{ __('settings.recent_deliveries') }}</h5></div>
                        <div class="card-body">
                            <p class="text-muted fs-13">{{ __('settings.send_that_times_out_cannot') }}</p>
                            @forelse ($notificationDeliveries as $delivery)
                                <div class="d-flex justify-content-between gap-3 border-bottom py-2">
                                    @php $rdDevice = $notificationDeliveryDevices[$delivery->id] ?? null; @endphp
                                    <div><strong>{{ $delivery->title }}</strong>
                                        <div class="text-muted fs-13">
                                            {{ \App\Services\AppriseNotifications::eventLabel($delivery->event) }}
                                            @if ($rdDevice)
                                                · <a href="{{ route('devices.show', $rdDevice->id) }}">{{ trim((string) ($rdDevice->alias ?: $rdDevice->hostname)) ?: __('common.device') }} ({{ $rdDevice->rustdesk_id }})</a>
                                            @endif
                                            · <span title="{{ $delivery->created_at?->format('Y-m-d H:i:s T') }}">{{ $delivery->created_at?->diffForHumans() }}</span>
                                        </div>
                                        @if($delivery->error)<div class="text-danger fs-13 text-break">{{ $delivery->error }}</div>@endif
                                        @php $maxAttempts = \App\Services\AppriseNotifications::MAX_ATTEMPTS; @endphp
                                        @if ($delivery->retryPending())
                                            <div class="text-muted fs-13">{{ __('settings.attempt_n_of_max_next', ['n' => $delivery->attempts, 'max' => $maxAttempts]) }} <span title="{{ $delivery->next_retry_at->format('Y-m-d H:i:s T') }}">{{ $delivery->next_retry_at->diffForHumans() }}</span></div>
                                        @elseif ($delivery->status === 'superseded')
                                            <div class="text-muted fs-13">{{ __('settings.attempt_n_of_max_retry', ['n' => $delivery->attempts, 'max' => $maxAttempts]) }}</div>
                                        @elseif ($delivery->attempts > 1)
                                            <div class="text-muted fs-13">{{ $delivery->status === 'sent' ? __('settings.sent_on_attempt_n_of', ['n' => $delivery->attempts, 'max' => $maxAttempts]) : __('settings.failed_after_attempt_n_of', ['n' => $delivery->attempts, 'max' => $maxAttempts]) }}</div>
                                        @endif
                                    </div>
                                    @php $tone = $delivery->retryPending() ? 'warning' : (['sent' => 'success', 'failed' => 'danger'][$delivery->status] ?? 'secondary'); @endphp
                                    <span class="badge bg-{{ $tone }}-subtle text-{{ $tone }} align-self-start">{{ $delivery->retryPending() ? 'retrying' : $delivery->status }}</span>
                                </div>
                            @empty
                                <p class="text-muted mb-0">{{ __('settings.no_delivery_attempts_yet') }}</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ========================= MAINTENANCE ========================= --}}
        <div class="tab-pane {{ $tab === 'maintenance' ? 'show active' : '' }}">
            <div class="row">
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">{{ __('settings.log_retention') }}</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">{{ __('settings.log_retention_days') }}</label>
                                <input type="number" class="form-control @error('logRetentionDays') is-invalid @enderror"
                                       wire:model="logRetentionDays" min="0" max="3650" style="max-width: 140px;">
                                @error('logRetentionDays') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">{{ __('settings.connection_file_transfer_login_and') }} <strong>{{ __('settings.0_keeps_logs_forever') }}</strong></div>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-primary" wire:click="save" @disabled(! $canManageSettings)><i class="ri-save-line me-1"></i>{{ __('common.save') }}</button>
                                <button type="button" class="btn btn-light" wire:click="pruneNow"
                                        wire:confirm="{{ __('settings.delete_logs_older_than_confirm', ['days' => $logRetentionDays]) }}"
                                        @if($logRetentionDays < 1 || ! $canManageSettings) disabled @endif>
                                    <i class="ri-delete-bin-6-line me-1"></i>{{ __('settings.prune_now') }}
                                </button>
                            </div>
                            @if ($pruneResult)
                                {{-- pre-line keeps the line breaks the pruner emits; rd-mono adds the
                                     break-anywhere guard, since the counts are printed per table name. --}}
                                <div class="alert alert-info py-2 mt-2 mb-0 rd-mono" style="white-space: pre-line;">{{ $pruneResult }}</div>
                            @endif
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">{{ __('settings.about') }}</h5>
                        </div>
                        <div class="card-body">
                            <dl class="rd-deflist">
                                <div class="rd-def"><dt>{{ __('common.cortendesk') }}</dt><dd class="rd-mono">v{{ config('cortendesk.api_version') }}</dd></div>
                                <div class="rd-def"><dt>{{ __('settings.laravel') }}</dt><dd class="rd-mono">{{ app()->version() }}</dd></div>
                                <div class="rd-def"><dt>{{ __('settings.php') }}</dt><dd class="rd-mono">{{ PHP_VERSION }}</dd></div>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- =========================== BACKUPS =========================== --}}
        <div class="tab-pane {{ $tab === 'backups' ? 'show active' : '' }}">
            @livewire(App\Livewire\BackupManager::class)
        </div>

    </div>
</div>
