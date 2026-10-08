<div class="rd-sidebar">

    <a href="{{ route('overview') }}" class="rd-sidebar-logo">
        <span class="rd-logo-lg">
            <img src="{{ asset('assets/images/cortendesk-logo-light.svg') }}" alt="CortenDesk" height="24"/>
        </span>
        <span class="rd-logo-sm">
            <img src="{{ asset('assets/images/cortendesk-sm.svg') }}" alt="CortenDesk" height="52" class="mx-auto d-block"/>
        </span>
    </a>

    <div class="rd-sidebar-close" data-rd-dismiss="sidebar" role="button" aria-label="Close menu">
        <i class="ri-close-fill align-middle"></i>
    </div>

    <div class="rd-sidebar-scroll">

        @php
            // Delegated roles (PLAN D4). consoleAllows() returns true for
            // is_admin and, for a user with no role, for exactly the areas a
            // non-admin could always reach — so this sidebar renders
            // identically on an install with no roles defined.
            $u = auth()->user();
            $canDevices = $u?->consoleAllows('device');
            $canAddressBooks = $u?->consoleAllows('address_book');
            $canGroups = $u?->consoleAllows('group');
            $canUsers = $u?->consoleAllows('user');
            $canStrategies = $u?->consoleAllows('strategy');
            $canAudit = $u?->consoleAllows('audit');
            $canAuditManage = $u?->consoleAllows('audit', 'rw');
            $canSettings = $u?->consoleAllows('setting');
            $canRoles = $u?->is_admin;
        @endphp

        <ul class="rd-nav">

            <li class="rd-nav-item {{ request()->routeIs('overview') ? 'rd-current' : '' }}">
                <a href="{{ route('overview') }}" class="rd-nav-link {{ request()->routeIs('overview') ? 'active' : '' }}">
                    <i class="ri-home-4-line"></i>
                    <span> Overview </span>
                </a>
            </li>

            @if ($canDevices || $canAddressBooks || $canGroups || $canUsers || $canStrategies || $canRoles)
                <li class="rd-nav-title">Manage</li>
            @endif

            @if ($canDevices)
                <li class="rd-nav-item {{ request()->routeIs('devices') ? 'rd-current' : '' }}">
                    <a href="{{ route('devices') }}" class="rd-nav-link {{ request()->routeIs('devices') ? 'active' : '' }}">
                        <i class="ri-computer-line"></i>
                        <span> Devices </span>
                    </a>
                </li>
            @endif

            @if ($canAddressBooks)
                <li class="rd-nav-item {{ request()->routeIs('address-books') ? 'rd-current' : '' }}">
                    <a href="{{ route('address-books') }}" class="rd-nav-link {{ request()->routeIs('address-books') ? 'active' : '' }}">
                        <i class="ri-contacts-book-2-line"></i>
                        <span> Address Books </span>
                    </a>
                </li>
            @endif

            @if (config('cortendesk.native_webclient'))
                <li class="rd-nav-item">
                    <a href="{{ route('webclient') }}" target="cortendesk-webclient" rel="noopener" class="rd-nav-link">
                        <i class="ri-global-line"></i>
                        <span> Web Client </span>
                    </a>
                </li>
            @elseif (config('cortendesk.webclient_url'))
                <li class="rd-nav-item">
                    <a href="{{ config('cortendesk.webclient_url') }}" target="_blank" rel="noopener" class="rd-nav-link">
                        <i class="ri-global-line"></i>
                        <span> Web Client </span>
                    </a>
                </li>
            @endif

            @if ($canGroups)
                <li class="rd-nav-item {{ request()->routeIs('groups') ? 'rd-current' : '' }}">
                    <a href="{{ route('groups') }}" class="rd-nav-link {{ request()->routeIs('groups') ? 'active' : '' }}">
                        <i class="ri-group-line"></i>
                        <span> Groups </span>
                    </a>
                </li>
            @endif

            @if ($canUsers)
                <li class="rd-nav-item {{ request()->routeIs('users') ? 'rd-current' : '' }}">
                    <a href="{{ route('users') }}" class="rd-nav-link {{ request()->routeIs('users') ? 'active' : '' }}">
                        <i class="ri-user-settings-line"></i>
                        <span> Users </span>
                    </a>
                </li>
            @endif

            {{-- Roles are super-admin only: a delegated admin who could edit
                 roles could grant themselves anything (PLAN D4). --}}
            @if ($canRoles)
                <li class="rd-nav-item {{ request()->routeIs('roles') ? 'rd-current' : '' }}">
                    <a href="{{ route('roles') }}" class="rd-nav-link {{ request()->routeIs('roles') ? 'active' : '' }}">
                        <i class="ri-shield-user-line"></i>
                        <span> Roles </span>
                    </a>
                </li>
            @endif

            @if ($canStrategies)
                <li class="rd-nav-item {{ request()->routeIs('strategies') ? 'rd-current' : '' }}">
                    <a href="{{ route('strategies') }}" class="rd-nav-link {{ request()->routeIs('strategies') ? 'active' : '' }}">
                        <i class="ri-shield-keyhole-line"></i>
                        <span> Strategies </span>
                    </a>
                </li>
            @endif

            @if ($canAudit)
                <li class="rd-nav-title">Monitor</li>

                <li class="rd-nav-item {{ request()->routeIs('logs.*') ? 'rd-current' : '' }}">
                    <a data-bs-toggle="collapse" href="#sidebarLogs" aria-expanded="{{ request()->routeIs('logs.*') ? 'true' : 'false' }}" aria-controls="sidebarLogs" class="rd-nav-link">
                        <i class="ri-file-list-3-line"></i>
                        <span> Logs </span>
                        <span class="rd-nav-arrow"></span>
                    </a>
                    <div class="collapse {{ request()->routeIs('logs.*') ? 'show' : '' }}" id="sidebarLogs">
                        <ul class="rd-nav-sub">
                            <li class="{{ request()->routeIs('logs.connections') ? 'rd-current' : '' }}"><a href="{{ route('logs.connections') }}">Connections</a></li>
                            <li class="{{ request()->routeIs('logs.file-transfers') ? 'rd-current' : '' }}"><a href="{{ route('logs.file-transfers') }}">File Transfers</a></li>
                            <li class="{{ request()->routeIs('logs.alarms') ? 'rd-current' : '' }}"><a href="{{ route('logs.alarms') }}">Alarms</a></li>
                            @if ($canAuditManage)
                                <li class="{{ request()->routeIs('logs.logins') ? 'rd-current' : '' }}"><a href="{{ route('logs.logins') }}">Logins</a></li>
                                <li class="{{ request()->routeIs('logs.console') ? 'rd-current' : '' }}"><a href="{{ route('logs.console') }}">Console</a></li>
                            @endif
                        </ul>
                    </div>
                </li>
            @endif

            @if ($canSettings)
                <li class="rd-nav-title">System</li>

                <li class="rd-nav-item {{ request()->routeIs('settings') ? 'rd-current' : '' }}">
                    <a href="{{ route('settings') }}" class="rd-nav-link {{ request()->routeIs('settings') ? 'active' : '' }}">
                        <i class="ri-settings-3-line"></i>
                        <span> Settings </span>
                    </a>
                </li>

                <li class="rd-nav-item {{ request()->routeIs('diagnostics*') ? 'rd-current' : '' }}">
                    <a href="{{ route('diagnostics') }}" class="rd-nav-link {{ request()->routeIs('diagnostics*') ? 'active' : '' }}">
                        <i class="ri-pulse-line"></i>
                        <span> Diagnostics </span>
                    </a>
                </li>

                <li class="rd-nav-item {{ request()->routeIs('client-downloads') ? 'rd-current' : '' }}">
                    <a href="{{ route('client-downloads') }}" class="rd-nav-link {{ request()->routeIs('client-downloads') ? 'active' : '' }}">
                        <i class="ri-download-cloud-line"></i>
                        <span> Client Downloads </span>
                    </a>
                </li>

                @php
                    $rdgenUrl = \App\Models\Setting::get('rdgen_url', config('cortendesk.rdgen_url'));
                @endphp
                @if ($rdgenUrl)
                    <li class="rd-nav-item">
                        <a href="{{ $rdgenUrl }}" target="_blank" rel="noopener" class="rd-nav-link">
                            <i class="ri-install-line"></i>
                            <span> Build Installers </span>
                        </a>
                    </li>
                @endif
            @endif
        </ul>

    </div>

    @if ($canSettings)
        @php
            $rdUpgrade = \App\Support\UpdateChecker::upgradeAvailable();
            // The console has no health probe of its own, so the status line
            // reports the one signal that is real — the release check — and
            // says so rather than claiming anything about the servers.
            $rdChecked = \App\Support\UpdateChecker::latestVersion() !== null;
        @endphp
        <div class="rd-sidebar-version">
            {{-- With the update check off there is nothing to report. --}}
            @if (\App\Support\UpdateChecker::enabled())
            <span class="rd-sidebar-status {{ $rdUpgrade ? 'rd-sidebar-status-warn' : ($rdChecked ? 'rd-sidebar-status-ok' : 'rd-sidebar-status-unknown') }}"
                  title="CortenDesk compares the running version against the latest published release.">
                <i class="rd-dot rd-dot-lg"></i>
                <span class="rd-sidebar-status-label">
                    @if ($rdUpgrade)
                        Update available
                    @elseif ($rdChecked)
                        Running the latest release
                    @else
                        Release check unavailable
                    @endif
                </span>
            </span>
            @endif
            <span class="rd-sidebar-version-num">v{{ config('cortendesk.api_version') }}</span>
            {{-- The badge only appears when it carries something the status
                 line above does not: the way to act on an upgrade. --}}
            @if ($rdUpgrade)
                <a href="{{ \App\Support\UpdateChecker::releaseNotesUrl($rdUpgrade) }}" target="_blank" rel="noopener"
                   class="rd-shell-badge"
                   title="Version {{ $rdUpgrade }} is available. Opens the release notes."><i class="ri-download-cloud-2-line"></i>Update to v{{ $rdUpgrade }}</a>
            @endif
        </div>
    @endif
</div>
