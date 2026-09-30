<div>
    @php $canManageSettings = auth()->user()?->consoleAllows('setting', 'rw'); @endphp

    @unless ($canManageSettings)
        <div class="alert alert-info py-2">
            <i class="ri-eye-line me-1"></i>You have view-only access to settings. Changes are disabled.
        </div>
    @endunless

    @php
        $mailSvc = app(\App\Services\MailSettings::class);
        $mailBroken = \App\Support\LoginEmailVerification::isActive() && ! $mailSvc->isHealthy();
    @endphp

    @if ($mailBroken || session('mail_broken'))
        <div class="alert alert-danger">
            <i class="ri-mail-close-line me-1"></i><strong>Email is failing.</strong>
            {{ session('mail_broken') ?: 'Nobody else can sign in while sign-in verification is enabled. Fix the settings below and send a test message.' }}
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
            <i class="ri-check-line me-1"></i>Settings saved.
        </div>
    @endif

    {{-- Nothing was written if any field failed — say so where it can be seen.
         save() also jumps $tab to the failing field, since one giant validate()
         covers every tab and the error may otherwise render off-screen. --}}
    @if ($errors->any())
        <div class="alert alert-danger py-2">
            <i class="ri-error-warning-line me-1"></i>Settings were <strong>not</strong> saved — fix the highlighted field below.
        </div>
    @endif

    {{-- Tab nav. Active tab lives in the $tab Livewire prop (survives save re-renders). --}}
    @php
        $tabs = [
            'server'      => ['ri-server-line', 'Server'],
            'client'      => ['ri-download-2-line', 'Client Setup'],
            'security'    => ['ri-shield-keyhole-line', 'Security'],
            'sso'         => ['ri-shield-user-line', 'SSO'],
            'email'       => ['ri-mail-send-line', 'Email'],
            'notifications' => ['ri-notification-3-line', 'Notifications'],
            'maintenance' => ['ri-database-2-line', 'Maintenance'],
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
                            <h5 class="card-title mb-0">RustDesk Server</h5>
                        </div>
                        <div class="card-body">
                            <form wire:submit="save">
                                <div class="mb-3">
                                    <label class="form-label">ID Server (hbbs)</label>
                                    <input type="text" class="form-control" wire:model="idServer" placeholder="e.g. hbbs.example.com:21116">
                                    <div class="form-text">Host:port of the rendezvous server clients register with.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Relay Server (hbbr)</label>
                                    <input type="text" class="form-control" wire:model="relayServer" placeholder="e.g. hbbs.example.com:21117">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Public Key</label>
                                    <input type="text" class="form-control rd-mono" wire:model="publicKey" placeholder="contents of id_ed25519.pub">
                                    <div class="form-text">The server's ed25519 public key — clients need it when <code>ENCRYPTED_ONLY</code> is enabled.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Build Installers URL</label>
                                    <input type="text" class="form-control @error('rdgenUrl') is-invalid @enderror"
                                           wire:model="rdgenUrl" placeholder="https://rdgen.crayoneater.org">
                                    @error('rdgenUrl') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">Opened by the sidebar's <strong>Build Installers</strong> entry — point it at your own rdgen instance. Leave empty to hide the menu entry.</div>
                                </div>
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="downloadsOnLogin"
                                               wire:model="downloadsOnLogin">
                                        <label class="form-check-label" for="downloadsOnLogin">Link to client downloads on the sign-in page</label>
                                    </div>
                                    <div class="form-text">Adds a link beneath the sign-in form when builds are published under <a href="{{ route('client-downloads') }}">Client Downloads</a>. The <a href="{{ route('downloads.index') }}" target="_blank" rel="noopener">/downloads</a> page stays reachable either way — unpublish a build to withdraw it.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Online window (seconds)</label>
                                    <input type="number" class="form-control @error('onlineWindow') is-invalid @enderror"
                                           wire:model="onlineWindow" min="20" max="600" style="max-width: 140px;">
                                    @error('onlineWindow') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">Seconds without a heartbeat before a device shows as offline. Clients report every ~15s.</div>
                                </div>
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="requireDeviceApproval"
                                               wire:model="requireDeviceApproval">
                                        <label class="form-check-label" for="requireDeviceApproval">Require device approval</label>
                                    </div>
                                    <div class="form-text">When on, a newly seen device is held as <strong>Pending</strong> and hidden from the console and API until an operator approves it on the Devices &rarr; Pending tab. Deployed devices (via an API token) are pre-approved.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="serverAccessMode">Server access</label>
                                    <select id="serverAccessMode" class="form-select @error('serverAccessMode') is-invalid @enderror"
                                            wire:model="serverAccessMode" style="max-width: 320px;">
                                        <option value="{{ \App\Services\ServerLink::MODE_OPEN }}">Open: any device with the key</option>
                                        <option value="{{ \App\Services\ServerLink::MODE_APPROVED }}">Approved devices only</option>
                                    </select>
                                    @error('serverAccessMode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">Enforced by the ID server. With approved devices only, pending, recycled and unknown devices still come online and show as Pending, but cannot start or receive sessions, and only clients signed in to this console (and the web client) can start one. Turn on Require device approval as well, or every device that reports here is approved on arrival. Incoming-only devices are refused when they start a session in either mode.</div>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="serverIpMatch" wire:model="serverIpMatch">
                                        <label class="form-check-label" for="serverIpMatch">Identify signed-out devices by IP address</label>
                                    </div>
                                    <div class="form-text">Approved devices only: lets a signed-out client start sessions when an approved device registered from the same IP. Weak: devices sharing a public IP, or behind a proxy that rewrites source addresses (Docker's userland proxy, IPv6 port publishing, Docker Desktop), can pass as each other.</div>
                                    @php $linkTone = $serverLink['ok'] === true ? 'text-success' : ($serverLink['ok'] === false ? 'text-danger' : 'text-muted'); @endphp
                                    <div class="form-text {{ $linkTone }}" id="server-link-status">
                                        <i class="{{ $serverLink['ok'] === false ? 'ri-error-warning-line' : 'ri-link' }} me-1"></i>ID server link: {{ $serverLink['note'] }}
                                        @if ($serverLink['ok'] === false) Needs CortenDesk Server 1.1.0 or later linked to this console. @endif
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary" @disabled(! $canManageSettings)><i class="ri-save-line me-1"></i>Save Settings</button>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Relay Servers</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted fs-13 mb-3">The relay pool (hbbr) your rendezvous server hands to clients. Relay
                                selection is decided by hbbs at connect time — the console stores this list so it can manage the
                                server's <code>relay-servers</code> config. Geo tags are for your own documentation. Leave empty to
                                use the single <strong>Relay Server (hbbr)</strong> above as the fallback.</p>

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
                                                   wire:model="relayServers.{{ $i }}.geo" placeholder="Geo tag">
                                            @error('relayServers.'.$i.'.geo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-4 col-sm-auto d-grid">
                                            <button type="button" class="btn btn-light text-danger" wire:click="removeRelay({{ $i }})"
                                                    title="Remove relay" @disabled(! $canManageSettings)><i class="ri-close-line"></i></button>
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-muted fs-13 fst-italic mb-2">No relay servers configured — using the single relay fallback above.</p>
                                @endforelse

                                <div class="d-flex gap-2 mt-3">
                                    <button type="button" class="btn btn-light" wire:click="addRelay" @disabled(! $canManageSettings)>
                                        <i class="ri-add-line me-1"></i>Add relay
                                    </button>
                                    <button type="submit" class="btn btn-primary" @disabled(! $canManageSettings)><i class="ri-save-line me-1"></i>Save Settings</button>
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
                            <h5 class="card-title mb-0">Client Setup</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted fs-13">Point RustDesk clients at this console under
                                <strong>Settings &rarr; Network &rarr; ID/Relay Server</strong>:</p>

                            <label class="form-label fs-13 text-muted mb-0">ID Server</label>
                            <div class="input-group input-group-sm mb-2">
                                <input type="text" class="form-control rd-mono" readonly value="{{ $idServer }}">
                                <button class="btn btn-light" type="button" onclick="rdCopyPrevious(this)"><i class="ri-file-copy-line"></i></button>
                            </div>

                            <label class="form-label fs-13 text-muted mb-0">Relay Server</label>
                            <div class="input-group input-group-sm mb-2">
                                <input type="text" class="form-control rd-mono" readonly value="{{ $relayServer }}">
                                <button class="btn btn-light" type="button" onclick="rdCopyPrevious(this)"><i class="ri-file-copy-line"></i></button>
                            </div>

                            <label class="form-label fs-13 text-muted mb-0">API Server</label>
                            <div class="input-group input-group-sm mb-2">
                                <input type="text" class="form-control rd-mono" readonly value="{{ $apiUrl }}">
                                <button class="btn btn-light" type="button" onclick="rdCopyPrevious(this)"><i class="ri-file-copy-line"></i></button>
                            </div>

                            <label class="form-label fs-13 text-muted mb-0">Key</label>
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
                            <h5 class="card-title mb-0">Two-Factor Authentication</h5>
                        </div>
                        <div class="card-body">
                            <form wire:submit="save">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="twoFactorRequired"
                                               wire:model="twoFactorRequired">
                                        <label class="form-check-label" for="twoFactorRequired">Require two-factor authentication for all users</label>
                                    </div>
                                    <div class="form-text">Signed-in users without 2FA are sent to the setup screen and can't use the console until they enroll.</div>
                                </div>
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="twoFactorRequiredAdmins"
                                               wire:model="twoFactorRequiredAdmins" @if($twoFactorRequired) disabled @endif>
                                        <label class="form-check-label" for="twoFactorRequiredAdmins">Require two-factor authentication for administrators</label>
                                    </div>
                                    <div class="form-text">Enforce 2FA for admin accounts only. Superseded when the all-users switch above is on.</div>
                                </div>

                                {{-- Email sign-in verification sits beside 2FA because it is the
                                     same kind of policy; the relay itself is configured on the Email tab. --}}
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="emailLoginVerification"
                                               wire:model="emailLoginVerification" @unless($mailEnabled) disabled @endunless>
                                        <label class="form-check-label" for="emailLoginVerification">Require an emailed code on new devices</label>
                                    </div>
                                    <div class="form-text">
                                        @if ($mailEnabled)
                                            Users without an authenticator app get a 6-digit code by email the first time they
                                            sign in from a browser this console has not seen. The browser stays trusted for
                                            {{ $emailTrustedDeviceDays }} days.
                                            Accounts with 2FA enrolled are unaffected — they answer the authenticator instead.
                                            @if ($usersWithoutEmail > 0)
                                                <span class="text-warning d-block mt-1">
                                                    {{ $usersWithoutEmail }} active account(s) have no email address and are let
                                                    through with a password alone. An SMTP outage does the same, deliberately, so
                                                    a broken relay cannot lock everyone out.
                                                </span>
                                            @else
                                                <span class="d-block mt-1">An account with no email address, or an SMTP outage,
                                                    lets the sign-in through on the password alone — a broken relay must not lock
                                                    everyone out.</span>
                                            @endif
                                        @else
                                            Configure and enable a mail relay on the <a href="#" wire:click.prevent="$set('tab', 'email')">Email</a>
                                            tab first — without one there is no way to deliver the code.
                                        @endif
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Remember a verified browser for (days)</label>
                                    <input type="number" class="form-control @error('emailTrustedDeviceDays') is-invalid @enderror"
                                           wire:model="emailTrustedDeviceDays" min="1" max="365" style="max-width: 140px;">
                                    @error('emailTrustedDeviceDays') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">Trust is revoked immediately by "Force logout", disabling the account, or a password change.</div>
                                </div>

                                <button type="submit" class="btn btn-primary" @disabled(! $canManageSettings)><i class="ri-save-line me-1"></i>Save Settings</button>
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
                            <h5 class="card-title mb-0">Single Sign-On (OIDC)</h5>
                            @if ($oidcEnabled)
                                <span class="badge bg-success-subtle text-success">Enabled</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Disabled</span>
                            @endif
                        </div>
                        <div class="card-body">
                            <p class="text-muted fs-13">
                                Let operators sign in with your identity provider — Keycloak, Authentik, Entra ID,
                                Okta, Google Workspace or anything else that speaks OpenID Connect.
                                Register this callback URL with your provider:
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
                                        <label class="form-check-label" for="oidcEnabled">Enable single sign-on</label>
                                    </div>
                                    <div class="form-text">Adds a sign-in button to the login page.</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Provider URL</label>
                                    <input type="text" class="form-control @error('oidcDiscoveryUrl') is-invalid @enderror"
                                           wire:model="oidcDiscoveryUrl" placeholder="https://idp.example.com/realms/main">
                                    @error('oidcDiscoveryUrl') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">
                                        The issuer URL. <code>/.well-known/openid-configuration</code> is appended
                                        automatically, or paste the full discovery URL.
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Client ID</label>
                                        <input type="text" class="form-control" wire:model="oidcClientId">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Client Secret</label>
                                        <input type="password" class="form-control" wire:model="oidcClientSecret"
                                               autocomplete="new-password"
                                               placeholder="{{ $oidcClientSecretSet ? 'Stored — leave blank to keep' : 'Required' }}">
                                        <div class="form-text">Encrypted at rest. Blank leaves the stored secret unchanged.</div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="testOidc"
                                            wire:loading.attr="disabled" wire:target="testOidc" @disabled(! $canManageSettings)>
                                        <i class="ri-plug-line me-1"></i>
                                        <span wire:loading.remove wire:target="testOidc">Test Connection</span>
                                        <span wire:loading wire:target="testOidc">Contacting provider…</span>
                                    </button>
                                    @if ($oidcTestMessage)
                                        <div class="mt-2 alert {{ $oidcTestOk ? 'alert-success' : 'alert-danger' }} py-2 mb-0 fs-13">
                                            {{ $oidcTestMessage }}
                                        </div>
                                    @endif
                                </div>

                                <hr>

                                <div class="mb-3">
                                    <label class="form-label">New users</label>
                                    <select class="form-select" wire:model="oidcNewUserPolicy">
                                        <option value="deny">Deny — only existing console accounts may sign in</option>
                                        <option value="pending">Create, pending administrator approval</option>
                                        <option value="active">Create and allow in immediately</option>
                                    </select>
                                    <div class="form-text">
                                        Applies when the provider's identity matches no existing account. An email address
                                        is only ever matched to an existing account when the provider marks it verified.
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="oidcRequireVerifiedEmail"
                                               wire:model="oidcRequireVerifiedEmail">
                                        <label class="form-check-label" for="oidcRequireVerifiedEmail">Require the provider to assert email verification</label>
                                    </div>
                                    <div class="form-text">
                                        Off by default, because the claim is optional in OpenID Connect and common providers
                                        make it meaningless — Microsoft Entra ID never sends it, and Authentik sends
                                        <code>false</code> for everyone. Accounts are matched on the provider's stable user id,
                                        not the address, so this changes little in practice. Turn it on only if you federate
                                        with a provider whose users can choose their own email address.
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Default group for new users</label>
                                        <select class="form-select" wire:model="oidcDefaultGroupId">
                                            <option value="0">None</option>
                                            @foreach ($userGroups as $group)
                                                <option value="{{ $group->id }}">{{ $group->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Allowed email domains</label>
                                        <input type="text" class="form-control" wire:model="oidcAllowedDomains"
                                               placeholder="example.com, example.org">
                                        <div class="form-text">Blank allows any domain the provider authenticates.</div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="oidcDefaultAdmin"
                                               wire:model="oidcDefaultAdmin">
                                        <label class="form-check-label" for="oidcDefaultAdmin">Make new SSO users administrators</label>
                                    </div>
                                    <div class="form-text text-warning">
                                        Leave off unless every account your provider authenticates should administer this console.
                                    </div>
                                </div>

                                <hr>

                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="oidcLogoutEnabled"
                                               wire:model="oidcLogoutEnabled">
                                        <label class="form-check-label" for="oidcLogoutEnabled">Sign out of the provider too</label>
                                    </div>
                                    <div class="form-text">Ends the provider session on logout, not just the console session.</div>
                                </div>

                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="oidcDisableLocalLogin"
                                               wire:model="oidcDisableLocalLogin">
                                        <label class="form-check-label" for="oidcDisableLocalLogin">Disable password sign-in</label>
                                    </div>
                                    <div class="form-text">
                                        Hides the password form so SSO is the only way in. It takes effect only while SSO is
                                        enabled and configured, so a provider outage restores password sign-in by itself.
                                        <code>CORTENDESK_OIDC_DISABLED=true</code> in <code>.env</code> forces it off from the shell.
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Advanced</label>
                                    <input type="text" class="form-control mb-2" wire:model="oidcScopes" placeholder="openid email profile">
                                    <div class="form-text mb-2">Scopes requested from the provider.</div>
                                    <input type="text" class="form-control mb-2" wire:model="oidcButtonLabel" placeholder="Sign in with SSO">
                                    <div class="form-text mb-2">Label for the login-page button.</div>
                                    <input type="text" class="form-control" wire:model="oidcPublicBaseUrl"
                                           placeholder="https://idp.example.com">
                                    <div class="form-text">
                                        Browser-facing provider URL. Only needed when the console reaches the provider on a
                                        different address than your users' browsers do (container networks, split DNS).
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary" @disabled(! $canManageSettings)><i class="ri-save-line me-1"></i>Save Settings</button>
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
                            <h5 class="card-title mb-0">Outbound Email (SMTP)</h5>
                            @if ($smtpEnabled)
                                <span class="badge bg-success-subtle text-success">Enabled</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Disabled</span>
                            @endif
                        </div>
                        <div class="card-body">
                            <p class="text-muted fs-13">
                                Used for user invitations and emailed sign-in codes. Any SMTP relay works — your own
                                server, a provider, or a local smarthost. Messages are sent as the console handles the
                                request, so keep the relay close and responsive.
                            </p>

                            <form wire:submit="save">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="smtpEnabled"
                                               wire:model="smtpEnabled">
                                        <label class="form-check-label" for="smtpEnabled">Enable outbound email</label>
                                    </div>
                                    <div class="form-text">Off means nothing is ever sent — invitation links must then be copied by hand.</div>
                                </div>

                                <div class="row">
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label">SMTP host</label>
                                        <input type="text" class="form-control @error('smtpHost') is-invalid @enderror"
                                               wire:model="smtpHost" placeholder="smtp.example.com">
                                        @error('smtpHost') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Port</label>
                                        <input type="number" class="form-control @error('smtpPort') is-invalid @enderror"
                                               wire:model="smtpPort" min="1" max="65535">
                                        @error('smtpPort') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Encryption</label>
                                    <select class="form-select" wire:model="smtpEncryption" style="max-width: 420px;">
                                        <option value="starttls">STARTTLS (usually port 587)</option>
                                        <option value="ssl">Implicit TLS / SSL (usually port 465)</option>
                                        <option value="none">None (STARTTLS still used if the server offers it)</option>
                                    </select>
                                    <div class="form-text">
                                        The mail library upgrades a plain connection whenever the relay advertises STARTTLS,
                                        so "None" cannot force a genuinely unencrypted session.
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Username</label>
                                        <input type="text" class="form-control @error('smtpUsername') is-invalid @enderror"
                                               wire:model="smtpUsername" autocomplete="off" placeholder="Optional">
                                        @error('smtpUsername') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Password</label>
                                        <input type="password" class="form-control @error('smtpPassword') is-invalid @enderror"
                                               wire:model="smtpPassword" autocomplete="new-password"
                                               placeholder="{{ $smtpPasswordSet ? 'Stored — leave blank to keep' : 'Optional' }}">
                                        @error('smtpPassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        <div class="form-text">Encrypted at rest. Blank leaves the stored password unchanged.</div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">From address</label>
                                        <input type="text" class="form-control @error('smtpFromAddress') is-invalid @enderror"
                                               wire:model="smtpFromAddress" placeholder="console@example.com">
                                        @error('smtpFromAddress') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        <div class="form-text">Must be an address your relay is allowed to send as (SPF/DMARC).</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">From name</label>
                                        <input type="text" class="form-control @error('smtpFromName') is-invalid @enderror"
                                               wire:model="smtpFromName" placeholder="{{ config('app.name') }}">
                                        @error('smtpFromName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary" @disabled(! $canManageSettings)><i class="ri-save-line me-1"></i>Save Settings</button>
                            </form>

                            <hr>

                            <label class="form-label">Send a test email</label>
                            <div class="row g-2 align-items-start">
                                <div class="col-sm-7">
                                    <input type="email" class="form-control" wire:model="smtpTestTo" placeholder="you@example.com">
                                </div>
                                <div class="col-sm-5 d-grid">
                                    <button type="button" class="btn btn-outline-secondary" wire:click="sendTestEmail"
                                            wire:loading.attr="disabled" wire:target="sendTestEmail" @disabled(! $canManageSettings)>
                                        <i class="ri-send-plane-line me-1"></i>
                                        <span wire:loading.remove wire:target="sendTestEmail">Send test email</span>
                                        <span wire:loading wire:target="sendTestEmail">Sending…</span>
                                    </button>
                                </div>
                            </div>
                            <div class="form-text">Uses the <strong>saved</strong> settings, not what is typed above — save first.</div>
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
                        <div class="card-header"><h5 class="card-title mb-0">Apprise notifications</h5></div>
                        <div class="card-body">
                            <p class="text-muted fs-13">Send fleet and security events through a self-hosted Apprise API. Destination credentials are encrypted and never shown again.</p>
                            <form wire:submit="save">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="appriseEnabled" wire:model="appriseEnabled">
                                    <label class="form-check-label" for="appriseEnabled">Enable notifications</label>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Apprise API endpoint</label>
                                    <input type="url" class="form-control @error('appriseEndpoint') is-invalid @enderror" wire:model="appriseEndpoint"
                                           autocomplete="off" data-1p-ignore data-lpignore="true"
                                           placeholder="{{ $appriseEndpointSet ? 'Stored — leave blank to keep' : 'https://apprise.example.com' }}">
                                    @error('appriseEndpoint')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <div class="form-text">Base URL only. CortenDesk appends <code>/notify</code>.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Delivery mode</label>
                                    <select class="form-select" wire:model.live="appriseMode">
                                        <option value="config">Saved Apprise configuration key</option>
                                        <option value="urls">Stateless Apprise URLs</option>
                                    </select>
                                </div>
                                @if ($appriseMode === 'config')
                                    <div class="mb-3"><label class="form-label">Configuration key</label><input type="password" class="form-control" wire:model="appriseConfigKey"
                                               autocomplete="new-password" data-1p-ignore data-lpignore="true"
                                               placeholder="{{ $appriseConfigKeySet ? 'Stored — leave blank to keep' : 'office-alerts' }}"></div>
                                @else
                                    <div class="mb-3"><label class="form-label">Apprise URLs</label><textarea class="form-control rd-mono" rows="4" wire:model="appriseUrls"
                                                       autocomplete="off" data-1p-ignore data-lpignore="true"
                                                       placeholder="{{ $appriseUrlsSet ? 'Stored — leave blank to keep' : 'One Apprise URL per line' }}"></textarea><div class="form-text">Write-only and encrypted at rest.</div></div>
                                @endif
                                <div class="mb-3"><label class="form-label">Cooldown (minutes)</label><input type="number" class="form-control" style="max-width: 140px" min="0" max="1440" wire:model="appriseCooldownMinutes"></div>
                                <div class="mb-3"><label class="form-label">Offline alert grace (minutes)</label><input type="number" class="form-control @error('appriseOfflineGraceMinutes') is-invalid @enderror" style="max-width: 140px" min="0" max="1440" wire:model="appriseOfflineGraceMinutes"><div class="form-text">Wait this long after a device becomes offline before sending its Apprise alert.</div>@error('appriseOfflineGraceMinutes')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="row">
                                    @foreach ($appriseEventLabels as $event => $label)
                                        @php $eventKey = str_replace('.', '_', $event); @endphp
                                        <div class="col-md-6 mb-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="event-{{ str_replace('.', '-', $event) }}" wire:model="appriseEvents.{{ $eventKey }}">
                                                <label class="form-check-label" for="event-{{ str_replace('.', '-', $event) }}">{{ $label }}</label>
                                            </div>
                                            @if (str_starts_with($event, 'device.'))
                                                <select class="form-select form-select-sm mt-2" wire:model.live="appriseScopes.{{ $eventKey }}">
                                                    <option value="all">All approved devices</option>
                                                    <option value="selected">Selected groups or devices</option>
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
                                <button class="btn btn-primary mt-2" type="submit" @disabled(! $canManageSettings)><i class="ri-save-line me-1"></i>Save settings</button>
                                <button class="btn btn-outline-secondary mt-2" type="button" wire:click="sendTestNotification" @disabled(! $canManageSettings)>Send test</button>
                                @if ($appriseTestMessage)<div class="alert {{ $appriseTestOk ? 'alert-success' : 'alert-danger' }} py-2 mt-3 mb-0">{{ $appriseTestMessage }}</div>@endif
                            </form>
                        </div>
                    </div>
                    @if (auth()->user()?->is_admin)
                        <div class="card">
                            <div class="card-header"><h5 class="card-title mb-0">Presence alert maintenance</h5></div>
                        <div class="card-body">
                            <p class="text-muted fs-13">Temporarily suppress offline and recovery notifications for one device or an entire group. Security and login alerts are not affected.</p>
                            <form wire:submit="createPresenceSnooze" class="row g-2 align-items-end">
                                <div class="col-md-3"><label class="form-label">Apply to</label><select class="form-select" wire:model.live="presenceSnoozeTargetType"><option value="device">Device</option><option value="group">Device group</option></select></div>
                                <div class="col-md-5"><label class="form-label">Target</label><select class="form-select @error('presenceSnoozeTargetId') is-invalid @enderror" wire:model="presenceSnoozeTargetId"><option value="0">Choose a target</option>@if($presenceSnoozeTargetType === 'group')@foreach($presenceSnoozeGroups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach @else @foreach($presenceSnoozeDevices as $device)<option value="{{ $device->id }}">{{ $device->alias ?: ($device->hostname ?: $device->rustdesk_id) }}</option>@endforeach @endif</select></div>
                                <div class="col-md-2"><label class="form-label">Minutes</label><input type="number" class="form-control @error('presenceSnoozeMinutes') is-invalid @enderror" min="15" max="10080" wire:model="presenceSnoozeMinutes"></div>
                                <div class="col-md-2"><button class="btn btn-outline-warning w-100" type="submit" @disabled(! $canManageSettings)>Snooze alerts</button></div>
                            </form>
                            @error('presenceSnoozeTargetId')<div class="text-danger fs-13 mt-2">{{ $message }}</div>@enderror @error('presenceSnoozeMinutes')<div class="text-danger fs-13 mt-2">{{ $message }}</div>@enderror
                            <div class="mt-3">
                                @forelse($presenceSnoozes as $snooze)
                                    @php $target = $snooze->target_type === 'group' ? $presenceSnoozeGroups->firstWhere('id', $snooze->target_id) : $presenceSnoozeDevices->firstWhere('id', $snooze->target_id); @endphp
                                    <div class="d-flex justify-content-between align-items-center border-top py-2"><div><strong>{{ $snooze->target_type === 'group' ? 'Group' : 'Device' }}: {{ $target?->name ?: $target?->alias ?: $target?->hostname ?: $target?->rustdesk_id ?: 'Deleted target' }}</strong><div class="text-muted fs-13">Alerts muted until {{ $snooze->expires_at->toDayDateTimeString() }} ({{ $snooze->expires_at->diffForHumans() }})</div></div><button type="button" class="btn btn-sm btn-outline-secondary" wire:click="clearPresenceSnooze({{ $snooze->id }})" @disabled(! $canManageSettings)>End now</button></div>
                                @empty
                                    <div class="text-muted fs-13">No active presence-alert maintenance windows.</div>
                                @endforelse
                            </div>
                        </div>
                        </div>
                    @endif
                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0">Recent deliveries</h5></div>
                        <div class="card-body">
                            @forelse ($notificationDeliveries as $delivery)
                                <div class="d-flex justify-content-between gap-3 border-bottom py-2">
                                    @php $rdDevice = $notificationDeliveryDevices[$delivery->id] ?? null; @endphp
                                    <div><strong>{{ $delivery->title }}</strong>
                                        <div class="text-muted fs-13">
                                            {{ \App\Services\AppriseNotifications::EVENTS[$delivery->event] ?? $delivery->event }}
                                            @if ($rdDevice)
                                                · <a href="{{ route('devices.show', $rdDevice->id) }}">{{ trim((string) ($rdDevice->alias ?: $rdDevice->hostname)) ?: 'Device' }} ({{ $rdDevice->rustdesk_id }})</a>
                                            @endif
                                            · <span title="{{ $delivery->created_at?->format('Y-m-d H:i:s T') }}">{{ $delivery->created_at?->diffForHumans() }}</span>
                                        </div>
                                        @if($delivery->error)<div class="text-danger fs-13 text-break">{{ $delivery->error }}</div>@endif
                                    </div>
                                    <span class="badge {{ $delivery->status === 'sent' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }}">{{ $delivery->status }}</span>
                                </div>
                            @empty
                                <p class="text-muted mb-0">No delivery attempts yet.</p>
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
                            <h5 class="card-title mb-0">Log Retention</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Log retention (days)</label>
                                <input type="number" class="form-control @error('logRetentionDays') is-invalid @enderror"
                                       wire:model="logRetentionDays" min="0" max="3650" style="max-width: 140px;">
                                @error('logRetentionDays') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">Connection, file-transfer, login and alarm logs older than this are deleted nightly. <strong>0 keeps logs forever.</strong></div>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-primary" wire:click="save" @disabled(! $canManageSettings)><i class="ri-save-line me-1"></i>Save</button>
                                <button type="button" class="btn btn-light" wire:click="pruneNow"
                                        wire:confirm="Delete all log entries older than {{ $logRetentionDays }} days now?"
                                        @if($logRetentionDays < 1 || ! $canManageSettings) disabled @endif>
                                    <i class="ri-delete-bin-6-line me-1"></i>Prune now
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
                            <h5 class="card-title mb-0">About</h5>
                        </div>
                        <div class="card-body">
                            <dl class="rd-deflist">
                                <div class="rd-def"><dt>CortenDesk</dt><dd class="rd-mono">v{{ config('cortendesk.api_version') }}</dd></div>
                                <div class="rd-def"><dt>Laravel</dt><dd class="rd-mono">{{ app()->version() }}</dd></div>
                                <div class="rd-def"><dt>PHP</dt><dd class="rd-mono">{{ PHP_VERSION }}</dd></div>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
