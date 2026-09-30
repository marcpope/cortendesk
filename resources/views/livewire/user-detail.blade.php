<div wire:poll.30s>
    {{-- Header: who this is. --}}
    <div class="card">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <div class="rd-cell {{ $user->is_admin ? 'rd-tone-accent' : 'rd-tone-purple' }} min-width-0 me-auto">
                <span class="rd-avatar">{{ strtoupper(substr($user->username, 0, 1)) }}</span>
                <div class="min-width-0">
                    <h4 class="mb-0 text-truncate">{{ $user->displayName() }}</h4>
                    <span class="text-muted">{{ $user->username }}@if ($user->email) · {{ $user->email }}@endif</span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-1">
                @if ($user->is_admin)
                    <span class="badge bg-danger-subtle text-danger">Administrator</span>
                @elseif ($user->role)
                    <span class="badge bg-info-subtle text-info">{{ $user->role->name }}</span>
                @else
                    <span class="badge bg-secondary-subtle text-secondary">User</span>
                @endif
                @if ($user->is_active)
                    <span class="badge bg-success-subtle text-success">Active</span>
                @else
                    <span class="badge bg-warning-subtle text-warning">Disabled</span>
                @endif
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Profile</h5></div>
                <div class="card-body pt-0">
                    <dl class="rd-deflist">
                        <div class="rd-def"><dt>Username</dt><dd>{{ $user->username }}</dd></div>
                        <div class="rd-def"><dt>Name</dt><dd>{{ $user->name ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>Email</dt><dd>{{ $user->email ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>Role</dt>
                            <dd>{{ $user->is_admin ? 'Administrator' : ($user->role?->name ?? 'User') }}</dd>
                        </div>
                        <div class="rd-def"><dt>Groups</dt>
                            <dd>
                                @forelse ($user->groups as $g)
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $g->name }}</span>
                                @empty
                                    —
                                @endforelse
                            </dd>
                        </div>
                        <div class="rd-def"><dt>Two-factor</dt>
                            <dd>
                                @if ($user->totp_enabled)
                                    <span class="badge bg-info-subtle text-info"><i class="ri-shield-keyhole-line me-1"></i>Enabled</span>
                                @else
                                    Off
                                @endif
                            </dd>
                        </div>
                        <div class="rd-def"><dt>Sign-in method</dt>
                            <dd>
                                @if ($user->isSsoPending())
                                    <span class="badge bg-warning-subtle text-warning">SSO, awaiting approval</span>
                                @elseif ($user->isSsoProvisioned())
                                    SSO only
                                @elseif ($user->isSsoLinked())
                                    Password and SSO
                                @else
                                    Password
                                @endif
                            </dd>
                        </div>
                        <div class="rd-def"><dt>Created</dt>
                            <dd><span title="{{ $user->created_at }}">{{ $user->created_at?->format('Y-m-d') ?? '—' }}</span></dd>
                        </div>
                        @if ($canSignIns)
                            <div class="rd-def"><dt>Last sign-in</dt>
                                <dd>
                                    @if ($lastSignIn)
                                        <span title="{{ $lastSignIn->created_at }}">{{ $lastSignIn->created_at->diffForHumans() }}</span>
                                    @else
                                        never
                                    @endif
                                </dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </div>

            @if ($canDevices)
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Devices <span class="text-muted fw-normal fs-13">({{ $deviceCount }})</span></h5>
                        @if ($deviceCount > $devices->count() && auth()->user()?->is_admin)
                            <a href="{{ route('devices') }}?owner={{ $user->id }}" class="fs-13 text-muted">All</a>
                        @endif
                    </div>
                    <div class="card-body pt-0">
                        @forelse ($devices as $d)
                            <div class="rd-def">
                                <dt class="fw-normal min-width-0">
                                    <x-platform-icon :platform="$d->platform()" class="me-1"/>
                                    <a href="{{ route('devices.show', $d->id) }}" class="rd-cell-link">{{ $d->alias ?: $d->hostname ?: $d->rustdesk_id }}</a>
                                    <span class="text-muted">· {{ $d->rustdesk_id }}</span>
                                </dt>
                                <dd class="text-end mb-0">
                                    @if ($d->isOnline())
                                        <span class="badge bg-success-subtle text-success"><i class="rd-dot"></i>Online</span>
                                    @else
                                        <span class="text-muted">{{ $d->last_online_at?->diffForHumans() ?? 'never' }}</span>
                                    @endif
                                </dd>
                            </div>
                        @empty
                            <span class="text-muted">Owns no device you can see.</span>
                        @endforelse
                    </div>
                </div>
            @endif

            @if ($canBooks)
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Address books</h5></div>
                    <div class="card-body pt-0">
                        @forelse ($books as $book)
                            <div class="rd-def">
                                <dt class="fw-normal">
                                    <i class="ri-contacts-book-2-line me-1"></i>
                                    @if ($book->viewer_can_open)
                                        <a href="{{ route('address-books') }}?selectedBookId={{ $book->id }}" class="rd-cell-link">{{ $book->name }}</a>
                                    @else
                                        {{ $book->name }}
                                    @endif
                                    <span class="text-muted">· {{ $book->is_personal ? 'personal' : 'shared' }}</span>
                                </dt>
                                <dd class="text-end text-muted mb-0">{{ $book->entries_count }} {{ Str::plural('entry', $book->entries_count) }}</dd>
                            </div>
                        @empty
                            <span class="text-muted">Owns no address book.</span>
                        @endforelse
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-7">
            @if ($canSignIns)
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Recent sign-ins</h5>
                        <a href="{{ route('logs.logins') }}?search={{ urlencode($user->username) }}" class="fs-13 text-muted">All</a>
                    </div>
                    <div class="card-body pt-0">
                        @forelse ($signIns as $log)
                            <div class="rd-def">
                                <dt class="fw-normal">
                                    @if ($log->client === 'web')
                                        Console
                                    @elseif ($log->client === 'sso')
                                        Console (SSO)
                                    @else
                                        RustDesk {{ $log->client }}@if ($log->device_id) · {{ $log->device_id }}@endif
                                    @endif
                                    <span class="text-muted">· {{ $log->ip ?: '—' }}</span>
                                </dt>
                                <dd class="text-end text-muted mb-0 text-nowrap">
                                    <span title="{{ $log->created_at }}">{{ $log->created_at->diffForHumans() }}</span>
                                    @if ($log->successful)
                                        <span class="badge bg-success-subtle text-success ms-1">Success</span>
                                    @else
                                        <span class="badge bg-danger-subtle text-danger ms-1">Failed</span>
                                    @endif
                                </dd>
                            </div>
                        @empty
                            <span class="text-muted">No sign-ins recorded.</span>
                        @endforelse
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Recent console actions</h5>
                        <a href="{{ route('logs.console') }}?search={{ urlencode($user->username) }}" class="fs-13 text-muted">All</a>
                    </div>
                    <div class="card-body pt-0">
                        @forelse ($actions as $a)
                            <div class="rd-def">
                                <dt class="fw-normal min-width-0">
                                    <span class="badge bg-secondary-subtle text-secondary me-1">{{ $a->action }}</span>
                                    {{ $a->summary }}
                                </dt>
                                <dd class="text-end text-muted mb-0 text-nowrap"><span title="{{ $a->created_at }}">{{ $a->created_at->diffForHumans() }}</span></dd>
                            </div>
                        @empty
                            <span class="text-muted">No console actions recorded.</span>
                        @endforelse
                    </div>
                </div>
            @endif

            @if ($canConnections)
                @if ($canDevices)
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Connections to their devices</h5>
                        </div>
                        <div class="card-body pt-0">
                            @forelse ($incoming as $c)
                                <div class="rd-def">
                                    <dt class="fw-normal">
                                        <i class="{{ \App\Models\AuditConnection::typeIcon((int) $c->conn_type) }} me-1" title="{{ \App\Models\AuditConnection::typeLabel((int) $c->conn_type) }}"></i>
                                        {{ $c->from_name ?: $c->from_peer ?: 'unknown' }}
                                        <span class="text-muted">→ {{ $c->rustdesk_id }}</span>
                                    </dt>
                                    <dd class="text-end text-muted mb-0">
                                        {{ $c->created_at->diffForHumans() }}
                                        @if ($c->closed_at) · {{ $c->created_at->diffAsCarbonInterval($c->closed_at)->cascade()->forHumans(short: true) }} @endif
                                    </dd>
                                </div>
                            @empty
                                <span class="text-muted">No connections recorded.</span>
                            @endforelse
                        </div>
                    </div>
                @endif

                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Connections from devices they signed in on</h5>
                    </div>
                    <div class="card-body pt-0">
                        <p class="fs-13 text-muted">
                            Matched by the controlling device's RustDesk ID, from this user's RustDesk client sign-ins.
                            The log records the device, not the person, so anyone else using the same device shows here too.
                        </p>
                        @forelse ($outgoing as $c)
                            <div class="rd-def">
                                <dt class="fw-normal">
                                    <i class="{{ \App\Models\AuditConnection::typeIcon((int) $c->conn_type) }} me-1" title="{{ \App\Models\AuditConnection::typeLabel((int) $c->conn_type) }}"></i>
                                    {{ $c->from_peer }} <span class="text-muted">→ {{ $c->rustdesk_id }}</span>
                                </dt>
                                <dd class="text-end text-muted mb-0">
                                    {{ $c->created_at->diffForHumans() }}
                                    @if ($c->closed_at) · {{ $c->created_at->diffAsCarbonInterval($c->closed_at)->cascade()->forHumans(short: true) }} @endif
                                </dd>
                            </div>
                        @empty
                            <span class="text-muted">{{ $clientIds === [] ? 'This user has not signed in on a RustDesk client.' : 'No connections recorded.' }}</span>
                        @endforelse
                    </div>
                </div>
            @endif

            @unless ($canSignIns || $canConnections)
                <div class="card">
                    <div class="card-body text-muted">
                        Activity history needs the audit permission.
                    </div>
                </div>
            @endunless
        </div>
    </div>
</div>
