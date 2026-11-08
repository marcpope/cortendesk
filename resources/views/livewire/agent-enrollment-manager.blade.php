<div>
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">{{ __('monitoring.new_enrollment_token') }}</h5>
        </div>
        <div class="card-body">
            <form wire:submit="create" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="agentTokenName">{{ __('common.name') }}</label>
                    <input type="text" id="agentTokenName" class="form-control @error('name') is-invalid @enderror" wire:model="name" maxlength="100"
                           placeholder="{{ __('monitoring.token_name_placeholder') }}">
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="agentTokenGroup">{{ __('monitoring.device_group') }}</label>
                    <select id="agentTokenGroup" class="form-select @error('groupId') is-invalid @enderror" wire:model="groupId">
                        <option value="0">{{ __('monitoring.no_group') }}</option>
                        @foreach ($groups as $g)
                            <option value="{{ $g->id }}">{{ $g->name }}</option>
                        @endforeach
                    </select>
                    @error('groupId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="agentTokenExpires">{{ __('monitoring.expires_in_days') }}</label>
                    <input type="number" id="agentTokenExpires" class="form-control @error('expiresDays') is-invalid @enderror" wire:model="expiresDays" min="1" placeholder="{{ __('monitoring.never') }}">
                    @error('expiresDays') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="agentTokenUses">{{ __('monitoring.max_uses') }}</label>
                    <input type="number" id="agentTokenUses" class="form-control @error('maxUses') is-invalid @enderror" wire:model="maxUses" min="1" placeholder="{{ __('monitoring.unlimited') }}">
                    @error('maxUses') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary"><i class="ri-key-2-line me-1"></i>{{ __('monitoring.create_token') }}</button>
                </div>
            </form>
            <div class="form-text mt-2">{{ __('monitoring.token_help') }}</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">{{ __('monitoring.enrollment_tokens') }}</h5>
        </div>

        <div class="table-responsive d-none d-md-block">
            <table class="table table-hover table-centered mb-0">
                <thead>
                <tr>
                    <th>{{ __('common.name') }}</th>
                    <th>{{ __('monitoring.token') }}</th>
                    <th>{{ __('monitoring.device_group') }}</th>
                    <th>{{ __('monitoring.agents') }}</th>
                    <th>{{ __('monitoring.uses') }}</th>
                    <th>{{ __('monitoring.expires') }}</th>
                    <th>{{ __('monitoring.last_used') }}</th>
                    <th>{{ __('common.status') }}</th>
                    <th class="text-end">{{ __('common.action') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($tokens as $token)
                    @php $state = $token->state(); @endphp
                    <tr wire:key="t{{ $token->id }}">
                        <td>{{ $token->name }}<div class="fs-12 text-muted">{{ $token->creator?->username }}</div></td>
                        <td class="rd-mono fs-13">{{ $token->token_prefix }}…</td>
                        <td>{{ $token->group?->name ?? '—' }}</td>
                        <td>{{ $token->agents_count }}</td>
                        <td>{{ $token->uses }}@if ($token->max_uses) / {{ $token->max_uses }}@endif</td>
                        <td>{{ $token->expires_at?->format('Y-m-d') ?? __('monitoring.never') }}</td>
                        <td>{{ $token->last_used_at?->diffForHumans() ?? __('common.never') }}</td>
                        <td>@include('monitoring.partials.token-state', ['state' => $state])</td>
                        <td class="text-end">
                            @if ($state === 'active' && $token->token_encrypted !== null)
                                <a href="{{ route('monitoring.add', ['token' => $token->id]) }}" class="btn btn-sm btn-light"><i class="ri-download-2-line me-1"></i>{{ __('monitoring.install') }}</a>
                            @endif
                            @if ($state === 'active')
                                <button type="button" class="btn btn-sm btn-light text-danger" wire:click="revoke({{ $token->id }})"
                                        wire:confirm="{{ __('monitoring.revoke_confirm', ['name' => $token->name]) }}">{{ __('monitoring.revoke') }}</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">{{ __('monitoring.no_tokens') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="d-md-none rd-cardlist">
            @forelse ($tokens as $token)
                @php $state = $token->state(); @endphp
                <div class="rd-mini" wire:key="tm{{ $token->id }}">
                    <div class="rd-mini-head">
                        <div class="min-width-0">
                            <span class="rd-mini-title text-truncate">{{ $token->name }}</span>
                            <span class="rd-mini-sub rd-mono">{{ $token->token_prefix }}…</span>
                        </div>
                        @include('monitoring.partials.token-state', ['state' => $state])
                    </div>
                    <div class="rd-mini-foot">
                        <span class="rd-mini-sub min-width-0">
                            {{ $token->group?->name ?? __('monitoring.no_group') }} · {{ trans_choice('monitoring.agent_count', $token->agents_count, ['count' => $token->agents_count]) }}
                        </span>
                        @if ($state === 'active')
                            <div class="rd-mini-acts">
                                @if ($token->token_encrypted !== null)
                                    <a href="{{ route('monitoring.add', ['token' => $token->id]) }}" class="btn btn-sm btn-light">{{ __('monitoring.install') }}</a>
                                @endif
                                <button type="button" class="btn btn-sm btn-light text-danger" wire:click="revoke({{ $token->id }})"
                                        wire:confirm="{{ __('monitoring.revoke_confirm', ['name' => $token->name]) }}">{{ __('monitoring.revoke') }}</button>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-center text-muted py-4 mb-0">{{ __('monitoring.no_tokens') }}</p>
            @endforelse
        </div>
    </div>
</div>
