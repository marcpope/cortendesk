<div class="rd-stat-grid" wire:poll.15s>

    {{-- Devices ------------------------------------------------------------ --}}
    <a href="{{ route('devices') }}" class="card text-reset card-hover" title="{{ __('overview.view_all_devices') }}">
        <div class="card-body">
            <div class="rd-stat rd-tone-blue">
                <div class="rd-stat-head">
                    <span class="rd-stat-icon"><i class="ri-computer-line"></i></span>
                    <div class="min-width-0">
                        <div class="rd-stat-label">{{ __('common.devices') }}</div>
                        <div class="rd-stat-value">{{ $devices }}</div>
                    </div>
                </div>
                @if ($deviceTrend > 0)
                    <div class="rd-stat-foot rd-trend-up">
                        <i class="ri-arrow-up-line"></i>{{ __('overview.value_vs_last_14_days', ['value' => $deviceTrend]) }}
                    </div>
                @elseif ($deviceTrend < 0)
                    <div class="rd-stat-foot rd-trend-down">
                        <i class="ri-arrow-down-line"></i>{{ __('overview.value_vs_last_14_days', ['value' => abs($deviceTrend)]) }}
                    </div>
                @else
                    <div class="rd-stat-foot">{{ __('overview.no_change_vs_last_14') }}</div>
                @endif
            </div>
        </div>
    </a>

    {{-- Online now --------------------------------------------------------- --}}
    <a href="{{ route('devices', ['status' => 'online']) }}" class="card text-reset card-hover" title="{{ __('overview.view_online_devices') }}">
        <div class="card-body">
            <div class="rd-stat rd-tone-green">
                <div class="rd-stat-head">
                    <span class="rd-stat-icon"><i class="ri-pulse-line"></i></span>
                    <div class="min-width-0">
                        <div class="rd-stat-label">{{ __('overview.online_now') }}</div>
                        <div class="rd-stat-value">{{ $online }}</div>
                    </div>
                </div>
                <div class="rd-stat-foot">
                    @if ($onlinePct === null)
                        {{ __('overview.no_devices_enrolled_yet') }}
                    @else
                        <i class="rd-dot text-success"></i>{{ __('overview.pct_of_fleet', ['pct' => $onlinePct]) }}
                    @endif
                </div>
            </div>
        </div>
    </a>

    {{-- Users (admin only) -------------------------------------------------- --}}
    @if ($admin)
        <a href="{{ route('users') }}" class="card text-reset card-hover" title="{{ __('overview.manage_users') }}">
            <div class="card-body">
                <div class="rd-stat rd-tone-purple">
                    <div class="rd-stat-head">
                        <span class="rd-stat-icon"><i class="ri-group-line"></i></span>
                        <div class="min-width-0">
                            <div class="rd-stat-label">{{ __('common.users') }}</div>
                            <div class="rd-stat-value">{{ $users }}</div>
                        </div>
                    </div>
                    <div class="rd-stat-foot">{{ __('overview.count_signed_in_today', ['count' => $usersToday]) }}</div>
                </div>
            </div>
        </a>
    @endif

    @if ($canAudit)
    {{-- Audit-derived tiles ------------------------------------------------- --}}
    {{-- Active sessions ----------------------------------------------------- --}}
    <a href="{{ route('logs.connections') }}" class="card text-reset card-hover" title="{{ __('overview.view_connection_log') }}">
        <div class="card-body">
            <div class="rd-stat rd-tone-blue">
                <div class="rd-stat-head">
                    <span class="rd-stat-icon"><i class="ri-broadcast-line"></i></span>
                    <div class="min-width-0">
                        <div class="rd-stat-label">{{ __('common.sessions') }}</div>
                        <div class="rd-stat-value">{{ $sessions }}</div>
                    </div>
                </div>
                <div class="rd-stat-foot">
                    @if ($sessions > 0)
                        <i class="rd-dot text-success"></i>{{ __('overview.live_now') }}
                    @else
                        {{ __('overview.none_in_progress') }}
                    @endif
                </div>
            </div>
        </div>
    </a>

    {{-- Connections today ---------------------------------------------------- --}}
    {{-- Label kept short so it survives the six-across breakpoint; "vs yesterday"
         in the footer is what pins the value to today. --}}
    <a href="{{ route('logs.connections') }}" class="card text-reset card-hover" title="{{ __('overview.connections_started_today_view_connectio') }}">
        <div class="card-body">
            <div class="rd-stat rd-tone-teal">
                <div class="rd-stat-head">
                    <span class="rd-stat-icon"><i class="ri-links-line"></i></span>
                    <div class="min-width-0">
                        <div class="rd-stat-label">{{ __('common.connections') }}</div>
                        <div class="rd-stat-value">{{ $connectionsToday }}</div>
                    </div>
                </div>
                @if ($connectionTrend > 0)
                    <div class="rd-stat-foot rd-trend-up">
                        <i class="ri-arrow-up-line"></i>{{ __('overview.value_vs_yesterday', ['value' => $connectionTrend]) }}
                    </div>
                @elseif ($connectionTrend < 0)
                    <div class="rd-stat-foot rd-trend-down">
                        <i class="ri-arrow-down-line"></i>{{ __('overview.value_vs_yesterday', ['value' => abs($connectionTrend)]) }}
                    </div>
                @else
                    <div class="rd-stat-foot">{{ __('overview.same_as_yesterday') }}</div>
                @endif
            </div>
        </div>
    </a>

    {{-- Alarms --------------------------------------------------------------- --}}
    <a href="{{ route('logs.alarms') }}" class="card text-reset card-hover" title="{{ __('overview.view_alarm_log') }}">
        <div class="card-body">
            <div class="rd-stat {{ $alarms24h > 0 ? 'rd-tone-red' : 'rd-tone-amber' }}">
                <div class="rd-stat-head">
                    <span class="rd-stat-icon"><i class="ri-alarm-warning-line"></i></span>
                    <div class="min-width-0">
                        <div class="rd-stat-label">{{ __('overview.alarms_24h') }}</div>
                        <div class="rd-stat-value">{{ $alarms24h }}</div>
                    </div>
                </div>
                <div class="rd-stat-foot">
                    @if ($alarms24h > 0)
                        {{ __('overview.latest_when', ['when' => $lastAlarmAt?->diffForHumans(short: true)]) }}
                    @else
                        <i class="rd-dot text-success"></i>{{ __('overview.all_clear') }}
                    @endif
                </div>
            </div>
        </div>
    </a>
    @endif
</div>
