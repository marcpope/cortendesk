<div>
    <div class="card"><div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-4"><label class="form-label" for="report-from">{{ __('report.date_from') }}</label><input id="report-from" type="date" class="form-control" wire:model.live="dateFrom">@error('dateFrom')<span class="text-danger">{{ $message }}</span>@enderror</div>
            <div class="col-md-4"><label class="form-label" for="report-to">{{ __('report.date_to') }}</label><input id="report-to" type="date" class="form-control" wire:model.live="dateTo">@error('dateTo')<span class="text-danger">{{ $message }}</span>@enderror</div>
            <div class="col-md-4 d-flex align-items-end gap-2"><button class="btn btn-outline-primary" wire:click="period('this')">{{ __('report.this_month') }}</button><button class="btn btn-outline-primary" wire:click="period('last')">{{ __('report.last_month') }}</button></div>
        </div>
        <div class="row g-3">
        @foreach (['source' => 'from', 'target' => 'to'] as $side => $label)
            <fieldset class="col-md-6"><legend class="fs-5">{{ __('report.'.$label) }}</legend><div class="row">
                @foreach (['device_group_id' => 'device_group', 'user_id' => 'user', 'user_group_id' => 'user_group', 'rustdesk_id' => 'device'] as $field => $caption)
                    <div class="col-sm-6"><label class="form-label mt-2" for="{{ $side }}-{{ $field }}">{{ __('report.'.$caption) }}</label>
                    <select id="{{ $side }}-{{ $field }}" class="form-select" wire:model.live="{{ $side }}.{{ $field }}">
                        <option value="">{{ __('report.all') }}</option>
                        @if ($field === 'device_group_id')
                            @foreach ($devices->pluck('group')->filter()->unique('id')->sortBy('name') as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach
                        @elseif ($field === 'user_id')
                            @foreach ($devices->pluck('user')->filter()->unique('id')->sortBy('username') as $user)<option value="{{ $user->id }}">{{ $user->username }}</option>@endforeach
                        @elseif ($field === 'user_group_id')
                            @foreach ($devices->pluck('user')->filter()->flatMap(fn ($user) => $user->groups)->unique('id')->sortBy('name') as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach
                        @else
                            @foreach ($devices->sortBy('rustdesk_id') as $device)<option value="{{ $device->rustdesk_id }}">{{ $device->alias ?: $device->hostname ?: $device->rustdesk_id }} ({{ $device->rustdesk_id }})</option>@endforeach
                        @endif
                    </select></div>
                @endforeach
            </div></fieldset>
        @endforeach
        </div>
        <details class="text-muted mt-3 mb-3"><summary>{{ __('report.method') }}</summary><p class="mt-2">{{ __('report.help', ['timezone' => config('app.timezone')]) }}</p>
        <p>{{ __('report.attribution') }}</p></details>
        <button class="btn btn-primary" wire:click="export" wire:loading.attr="disabled">{{ __('report.export') }}</button>
        <button class="btn btn-outline-primary ms-2" wire:click="exportXlsx" wire:loading.attr="disabled">{{ __('report.export_xlsx') }}</button>
    </div></div>
    <div class="row g-3 mb-3">
        @foreach (['sessions' => $count, 'duration' => \App\Support\SessionTime::format($total), 'unique' => \App\Support\SessionTime::format($unique), 'active' => $active] as $label => $value)
            <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="text-muted">{{ __('report.'.$label) }}</div><div class="fs-4">{{ $value }}</div></div></div></div>
        @endforeach
    </div>
    <div class="card"><div class="card-body"><h5>{{ __('report.customers') }}</h5>
        <div class="table-responsive"><table class="table"><thead><tr><th>{{ __('report.device_group') }}</th><th>{{ __('report.sessions') }}</th><th>{{ __('report.duration') }}</th></tr></thead><tbody>
        @forelse ($breakdown as $group)<tr><td>{{ $group['name'] }}</td><td>{{ $group['count'] }}</td><td>{{ \App\Support\SessionTime::format($group['seconds']) }}</td></tr>@empty<tr><td colspan="3">{{ __('report.empty') }}</td></tr>@endforelse
        </tbody></table></div>
    </div></div>
    <div class="card"><div class="card-body"><h5>{{ __('report.operators') }}</h5><div class="table-responsive"><table class="table"><thead><tr><th>{{ __('report.from') }}</th><th>{{ __('report.sessions') }}</th><th>{{ __('report.duration') }}</th></tr></thead><tbody>
        @forelse ($operators as $operator)<tr><td>{{ $operator['name'] }}</td><td>{{ $operator['count'] }}</td><td>{{ \App\Support\SessionTime::format($operator['seconds']) }}</td></tr>@empty<tr><td colspan="3">{{ __('report.empty') }}</td></tr>@endforelse
    </tbody></table></div></div></div>
    <div class="card"><div class="card-body"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>{{ __('report.from') }}</th><th>{{ __('report.to') }}</th><th>{{ __('report.start') }}</th><th>{{ __('report.end') }}</th><th>{{ __('report.seconds') }}</th><th>{{ __('report.note') }}</th></tr></thead><tbody>
        @forelse ($rows as $row)
            <tr wire:key="report-{{ $row->id }}"><td>{{ $row->from_peer }}<br><small>{{ $row->from_name }}</small></td><td>{{ $row->rustdesk_id }}<br><small>{{ $devices->get($row->rustdesk_id)?->alias }}</small></td><td>{{ $row->created_at }}</td><td>{{ $row->closed_at ?? '—' }}</td><td>{{ $row->closed_at ? \App\Support\SessionTime::format(max(0, min($end->timestamp, $row->closed_at->timestamp) - max($start->timestamp, $row->created_at->timestamp))) : '—' }}</td><td>{{ $row->note }}</td></tr>
        @empty<tr><td colspan="6">{{ __('report.empty') }}</td></tr>@endforelse
    </tbody></table></div>{{ $rows->links() }}</div></div>
</div>
