<!-- ========== Left Sidebar Start ========== -->
<div class="leftside-menu">

    <a href="{{ route('overview') }}" class="logo logo-light">
        <span class="logo-lg">
            <img src="{{ asset('assets/images/cortendesk-logo-light.svg') }}" alt="CortenDesk" height="24"/>
        </span>
        <span class="logo-sm">
            <img src="{{ asset('assets/images/cortendesk-sm.svg') }}" alt="CortenDesk" height="52" class="mx-auto d-block"/>
        </span>
    </a>

    <a href="{{ route('overview') }}" class="logo logo-dark">
        <span class="logo-lg">
            <img src="{{ asset('assets/images/cortendesk-logo-dark.svg') }}" alt="CortenDesk" height="24"/>
        </span>
        <span class="logo-sm">
            <img src="{{ asset('assets/images/cortendesk-sm.svg') }}" alt="CortenDesk" height="52" class="mx-auto d-block"/>
        </span>
    </a>

    <div class="button-sm-hover" data-bs-toggle="tooltip" data-bs-placement="right" title="Show Full Sidebar">
        <i class="ri-checkbox-blank-circle-line align-middle"></i>
    </div>

    <div class="button-close-fullsidebar">
        <i class="ri-close-fill align-middle"></i>
    </div>

    <div class="h-100" id="leftside-menu-container" data-simplebar>

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

        <ul class="side-nav">

            <li class="side-nav-item {{ request()->routeIs('overview') ? 'menuitem-active' : '' }}">
                <a href="{{ route('overview') }}" class="side-nav-link {{ request()->routeIs('overview') ? 'active' : '' }}">
                    <i class="ri-home-4-line"></i>
                    <span> Overview </span>
                </a>
            </li>

            @if ($canDevices || $canAddressBooks || $canGroups || $canUsers || $canStrategies || $canRoles)
                <li class="side-nav-title">Manage</li>
            @endif

            @if ($canDevices)
                <li class="side-nav-item {{ request()->routeIs('devices') ? 'menuitem-active' : '' }}">
                    <a href="{{ route('devices') }}" class="side-nav-link {{ request()->routeIs('devices') ? 'active' : '' }}">
                        <i class="ri-computer-line"></i>
                        <span> Devices </span>
                    </a>
                </li>
            @endif

            @if ($canAddressBooks)
                <li class="side-nav-item {{ request()->routeIs('address-books') ? 'menuitem-active' : '' }}">
                    <a href="{{ route('address-books') }}" class="side-nav-link {{ request()->routeIs('address-books') ? 'active' : '' }}">
                        <i class="ri-contacts-book-2-line"></i>
                        <span> Address Books </span>
                    </a>
                </li>
            @endif

            @if (config('cortendesk.native_webclient'))
                <li class="side-nav-item">
                    <a href="{{ route('webclient') }}" target="cortendesk-webclient" rel="noopener" class="side-nav-link">
                        <i class="ri-global-line"></i>
                        <span> Web Client </span>
                    </a>
                </li>
            @elseif (config('cortendesk.webclient_url'))
                <li class="side-nav-item">
                    <a href="{{ config('cortendesk.webclient_url') }}" target="_blank" rel="noopener" class="side-nav-link">
                        <i class="ri-global-line"></i>
                        <span> Web Client </span>
                    </a>
                </li>
            @endif

            @if ($canGroups)
                <li class="side-nav-item {{ request()->routeIs('groups') ? 'menuitem-active' : '' }}">
                    <a href="{{ route('groups') }}" class="side-nav-link {{ request()->routeIs('groups') ? 'active' : '' }}">
                        <i class="ri-group-line"></i>
                        <span> Groups </span>
                    </a>
                </li>
            @endif

            @if ($canUsers)
                <li class="side-nav-item {{ request()->routeIs('users') ? 'menuitem-active' : '' }}">
                    <a href="{{ route('users') }}" class="side-nav-link {{ request()->routeIs('users') ? 'active' : '' }}">
                        <i class="ri-user-settings-line"></i>
                        <span> Users </span>
                    </a>
                </li>
            @endif

            {{-- Roles are super-admin only: a delegated admin who could edit
                 roles could grant themselves anything (PLAN D4). --}}
            @if ($canRoles)
                <li class="side-nav-item {{ request()->routeIs('roles') ? 'menuitem-active' : '' }}">
                    <a href="{{ route('roles') }}" class="side-nav-link {{ request()->routeIs('roles') ? 'active' : '' }}">
                        <i class="ri-shield-user-line"></i>
                        <span> Roles </span>
                    </a>
                </li>
            @endif

            @if ($canStrategies)
                <li class="side-nav-item {{ request()->routeIs('strategies') ? 'menuitem-active' : '' }}">
                    <a href="{{ route('strategies') }}" class="side-nav-link {{ request()->routeIs('strategies') ? 'active' : '' }}">
                        <i class="ri-shield-keyhole-line"></i>
                        <span> Strategies </span>
                    </a>
                </li>
            @endif

            @if ($canAudit)
                <li class="side-nav-title">Monitor</li>

                <li class="side-nav-item {{ request()->routeIs('logs.*') ? 'menuitem-active' : '' }}">
                    <a data-bs-toggle="collapse" href="#sidebarLogs" aria-expanded="{{ request()->routeIs('logs.*') ? 'true' : 'false' }}" aria-controls="sidebarLogs" class="side-nav-link">
                        <i class="ri-file-list-3-line"></i>
                        <span> Logs </span>
                        <span class="menu-arrow"></span>
                    </a>
                    <div class="collapse {{ request()->routeIs('logs.*') ? 'show' : '' }}" id="sidebarLogs">
                        <ul class="side-nav-second-level">
                            <li class="{{ request()->routeIs('logs.connections') ? 'menuitem-active' : '' }}"><a href="{{ route('logs.connections') }}">Connections</a></li>
                            <li class="{{ request()->routeIs('logs.file-transfers') ? 'menuitem-active' : '' }}"><a href="{{ route('logs.file-transfers') }}">File Transfers</a></li>
                            <li class="{{ request()->routeIs('logs.alarms') ? 'menuitem-active' : '' }}"><a href="{{ route('logs.alarms') }}">Alarms</a></li>
                            @if ($canAuditManage)
                                <li class="{{ request()->routeIs('logs.logins') ? 'menuitem-active' : '' }}"><a href="{{ route('logs.logins') }}">Logins</a></li>
                                <li class="{{ request()->routeIs('logs.console') ? 'menuitem-active' : '' }}"><a href="{{ route('logs.console') }}">Console</a></li>
                            @endif
                        </ul>
                    </div>
                </li>
            @endif

            @if ($canSettings)
                <li class="side-nav-title">System</li>

                <li class="side-nav-item {{ request()->routeIs('settings') ? 'menuitem-active' : '' }}">
                    <a href="{{ route('settings') }}" class="side-nav-link {{ request()->routeIs('settings') ? 'active' : '' }}">
                        <i class="ri-settings-3-line"></i>
                        <span> Settings </span>
                    </a>
                </li>

                <li class="side-nav-item {{ request()->routeIs('client-downloads') ? 'menuitem-active' : '' }}">
                    <a href="{{ route('client-downloads') }}" class="side-nav-link {{ request()->routeIs('client-downloads') ? 'active' : '' }}">
                        <i class="ri-download-cloud-line"></i>
                        <span> Client Downloads </span>
                    </a>
                </li>

                @php
                    $rdgenUrl = \App\Models\Setting::get('rdgen_url', config('cortendesk.rdgen_url'));
                @endphp
                @if ($rdgenUrl)
                    <li class="side-nav-item">
                        <a href="{{ $rdgenUrl }}" target="_blank" rel="noopener" class="side-nav-link">
                            <i class="ri-install-line"></i>
                            <span> Build Installers </span>
                        </a>
                    </li>
                @endif
            @endif
        </ul>

        <div class="clearfix"></div>
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
            <span class="rd-sidebar-version-num">v{{ config('cortendesk.api_version') }}</span>
            {{-- The badge only appears when it carries something the status
                 line above does not: the way to act on an upgrade. --}}
            @if ($rdUpgrade)
                <a href="{{ \App\Support\UpdateChecker::UPGRADE_DOC }}" target="_blank" rel="noopener"
                   class="rd-shell-badge"
                   title="Version {{ $rdUpgrade }} is available">Upgrade Available</a>
            @endif
        </div>
    @endif
</div>
<!-- ========== Left Sidebar End ========== -->
