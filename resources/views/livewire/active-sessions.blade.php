<div wire:poll.15s>
    @php
        // Stable tint per controlling peer, so the same operator keeps the same
        // colour between polls. Purely an aid to scanning the list.
        $tones = ['rd-tone-blue', 'rd-tone-purple', 'rd-tone-teal', 'rd-tone-amber', 'rd-tone-accent'];
    @endphp
    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <div class="min-width-0">
                <h5 class="card-title">{{ __('overview.active_sessions') }}</h5>
                <div class="rd-card-sub">{{ __('overview.connections_open_right_now') }}</div>
            </div>
            @if ($activeCount > 0)
                <div class="rd-card-actions">
                    <span class="badge bg-success-subtle text-success"><i class="rd-dot"></i>{{ __('overview.count_live', ['count' => $activeCount]) }}</span>
                </div>
            @endif
        </div>
        <div class="card-body">
            @if ($sessions->isEmpty())
                <div class="rd-empty">
                    <div class="rd-empty-icon"><i class="ri-broadcast-line"></i></div>
                    <p class="rd-empty-title">{{ __('overview.no_active_sessions') }}</p>
                    <p class="rd-empty-text">{{ __('overview.sessions_appear_here_moment_client') }}</p>
                    <a href="{{ route('logs.connections') }}" class="btn btn-sm btn-outline-light">{{ __('overview.view_connection_log_2') }}</a>
                </div>
            @else
                {{-- Desktop table (md and up) --}}
                <div class="table-responsive d-none d-md-block">
                    <table class="table table-hover table-centered align-middle mb-0">
                        <thead>
                        <tr>
                            <th>{{ __('common.from') }}</th>
                            <th>{{ __('common.to') }}</th>
                            <th>{{ __('common.type') }}</th>
                            <th>{{ __('overview.started') }}</th>
                            @if ($canDisconnect)
                                <th class="text-end">{{ __('common.action') }}</th>
                            @endif
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($sessions as $s)
                            @php
                                $who = $s->from_name ?: $s->from_peer;
                                $tone = $tones[crc32((string) $s->from_peer) % count($tones)];
                            @endphp
                            <tr wire:key="s{{ $s->id }}">
                                <td>
                                    <div class="rd-cell">
                                        <span class="rd-avatar {{ $tone }}">{{ mb_substr($who, 0, 1) }}</span>
                                        <div class="min-width-0">
                                            <span class="rd-cell-title text-truncate">{{ $who }}</span>
                                            <span class="rd-cell-sub text-truncate">{{ $s->from_peer }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <a href="{{ \App\Support\ClientLink::to($s->rustdesk_id) }}" title="{{ __('common.connect_with_rustdesk') }}" class="fs-13">{{ $s->rustdesk_id }}</a>
                                </td>
                                <td>
                                    @switch((int) $s->conn_type)
                                        @case(1)<span class="badge bg-primary-subtle text-primary">File</span>@break
                                        @case(2)<span class="badge bg-warning-subtle text-warning">Port Fwd</span>@break
                                        @default<span class="badge bg-info-subtle text-info">{{ \App\Models\AuditConnection::typeLabel((int) $s->conn_type) }}</span>
                                    @endswitch
                                </td>
                                <td>
                                    <span class="rd-cell-title fw-normal"><i class="rd-dot text-success"></i><span
                                            title="{{ $s->created_at }}">{{ $s->created_at->diffForHumans(short: true) }}</span></span>
                                </td>
                                @if ($canDisconnect)
                                    <td class="text-end">
                                        @if ($s->isDisconnecting())
                                            {{-- The console cannot close the session itself; it waits for
                                                 the device's next heartbeat to carry the instruction. --}}
                                            <span class="badge bg-warning-subtle text-warning" title="{{ __('overview.sent_on_devices_next_heartbeat') }}">{{ __('overview.disconnecting') }}</span>
                                        @else
                                            <a href="#" class="text-danger fs-13" wire:click.prevent="disconnect({{ $s->id }})"
                                               wire:confirm="{{ __('overview.end_this_session_on_id', ['id' => $s->rustdesk_id]) }}">{{ __('overview.disconnect') }}</a>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Mobile card list (below md). Same .rd-cardlist / .rd-mini object
                     as every other list screen, so it inherits the phone wrap guards;
                     p-0 because this list sits inside a card body rather than flush
                     against the panel edges. --}}
                <div class="d-md-none rd-cardlist p-0">
                    @foreach ($sessions as $s)
                        @php
                            $who = $s->from_name ?: $s->from_peer;
                            $tone = $tones[crc32((string) $s->from_peer) % count($tones)];
                        @endphp
                        <div class="rd-mini" wire:key="ms{{ $s->id }}">
                            <div class="rd-mini-head">
                                <div class="rd-cell">
                                    <span class="rd-avatar {{ $tone }}">{{ mb_substr($who, 0, 1) }}</span>
                                    <div class="min-width-0">
                                        <span class="rd-mini-title text-truncate">{{ $who }}</span>
                                        <span class="rd-mini-sub text-truncate">{{ $s->from_peer }}</span>
                                    </div>
                                </div>
                                @switch((int) $s->conn_type)
                                    @case(1)<span class="badge bg-primary-subtle text-primary">File</span>@break
                                    @case(2)<span class="badge bg-warning-subtle text-warning">Port Fwd</span>@break
                                    @default<span class="badge bg-info-subtle text-info">{{ \App\Models\AuditConnection::typeLabel((int) $s->conn_type) }}</span>
                                @endswitch
                            </div>
                            @if ($canDisconnect)
                                <div class="text-end">
                                    @if ($s->isDisconnecting())
                                        <span class="badge bg-warning-subtle text-warning">{{ __('overview.disconnecting') }}</span>
                                    @else
                                        <a href="#" class="text-danger fs-13" wire:click.prevent="disconnect({{ $s->id }})"
                                           wire:confirm="{{ __('overview.end_this_session_on_id', ['id' => $s->rustdesk_id]) }}">{{ __('overview.disconnect') }}</a>
                                    @endif
                                </div>
                            @endif
                            <div class="rd-mini-foot">
                                <a href="{{ \App\Support\ClientLink::to($s->rustdesk_id) }}" title="{{ __('common.connect_with_rustdesk') }}"
                                   class="fs-13 text-truncate">{{ $s->rustdesk_id }}</a>
                                <span class="rd-mini-sub text-nowrap"><i class="rd-dot text-success"></i><span
                                        title="{{ $s->created_at }}">{{ $s->created_at->diffForHumans(short: true) }}</span></span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
