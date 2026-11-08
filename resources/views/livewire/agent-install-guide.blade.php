{{-- Add agent: token, install per OS, wait for the first report. --}}
@php
    $osTabs = ['windows' => ['ri-windows-fill', 'Windows'], 'macos' => ['ri-apple-fill', 'macOS'], 'linux' => ['ri-ubuntu-fill', 'Linux']];
    $requires = ['windows' => __('monitoring.requires_windows'), 'macos' => __('monitoring.requires_macos'), 'linux' => __('monitoring.requires_linux')];
    $hints = ['windows' => __('monitoring.install_hint_windows'), 'macos' => __('monitoring.install_hint_macos'), 'linux' => __('monitoring.install_hint_linux')];
    $bin = ['windows' => '& \'C:\Program Files\CortenDesk Agent\cortendesk-agent.exe\'', 'macos' => 'sudo \'/Library/CortenDesk Agent/cortendesk-agent\'', 'linux' => 'sudo cortendesk-agent'];
@endphp
<div @if ($selected) wire:poll.5s @endif>

    {{-- ============================ CONNECTION ============================ --}}
    <div class="card">
        <div class="card-body d-flex flex-wrap align-items-center gap-2">
            <i class="ri-links-line fs-18 text-muted"></i>
            <span>{{ __('monitoring.agents_connect_to') }}</span>
            <code class="rd-mono">{{ $server }}</code>
            @if ($canSettings)
                <a href="{{ route('settings', ['tab' => 'monitoring']) }}" class="fs-13 ms-sm-auto">{{ __('monitoring.change_agent_url') }}<i class="ri-arrow-right-s-line"></i></a>
            @endif
            @if (! $serverWorks)
                <div class="alert alert-warning py-2 fs-13 mb-0 w-100 mt-2">{{ __('monitoring.agent_url_http_warning') }}</div>
            @elseif ($serverPlain)
                <div class="fs-13 text-muted w-100">{{ __('monitoring.agent_url_http_note') }}</div>
            @endif
        </div>
    </div>

    {{-- ============================ 1. TOKEN ============================ --}}
    <div class="card">
        <div class="card-header"><h5 class="card-title mb-0"><span class="rd-step">1</span>{{ __('monitoring.step_token') }}</h5></div>
        <div class="card-body">
            @if ($creating)
                <form wire:submit="create" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label" for="guideTokenName">{{ __('common.name') }}</label>
                        <input type="text" id="guideTokenName" class="form-control @error('name') is-invalid @enderror" wire:model="name" maxlength="100"
                               placeholder="{{ __('monitoring.token_name_placeholder') }}" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="guideTokenGroup">{{ __('monitoring.device_group') }}</label>
                        <select id="guideTokenGroup" class="form-select @error('groupId') is-invalid @enderror" wire:model="groupId">
                            <option value="0">{{ __('monitoring.no_group') }}</option>
                            @foreach ($groups as $g)
                                <option value="{{ $g->id }}">{{ $g->name }}</option>
                            @endforeach
                        </select>
                        @error('groupId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="guideTokenExpires">{{ __('monitoring.expires_in_days') }}</label>
                        <input type="number" id="guideTokenExpires" class="form-control @error('expiresDays') is-invalid @enderror" wire:model="expiresDays" min="1" placeholder="{{ __('monitoring.never') }}">
                        @error('expiresDays') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="guideTokenUses">{{ __('monitoring.max_uses') }}</label>
                        <input type="number" id="guideTokenUses" class="form-control @error('maxUses') is-invalid @enderror" wire:model="maxUses" min="1" placeholder="{{ __('monitoring.unlimited') }}">
                        @error('maxUses') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="ri-key-2-line me-1"></i>{{ __('monitoring.create_token') }}</button>
                        @if ($tokens->isNotEmpty())
                            <button type="button" class="btn btn-light" wire:click="cancelCreate">{{ __('common.cancel') }}</button>
                        @endif
                    </div>
                </form>
                <div class="form-text mt-2">{{ __('monitoring.token_help') }}</div>
            @else
                <div class="d-flex flex-wrap align-items-end gap-2">
                    <div class="flex-grow-1" style="max-width: 560px;">
                        <label class="form-label" for="guideToken">{{ __('monitoring.enrollment_token') }}</label>
                        <select id="guideToken" class="form-select" wire:model.live="tokenId">
                            @foreach ($tokens as $t)
                                <option value="{{ $t->id }}">{{ $t->name }} · {{ $t->group?->name ?? __('monitoring.no_group') }} · {{ $t->token_prefix }}…</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" class="btn btn-light" wire:click="startCreate"><i class="ri-add-line me-1"></i>{{ __('monitoring.new_token') }}</button>
                </div>
                @if ($selected)
                    <div class="fs-13 text-muted mt-2">
                        {{ $selected->max_uses ? __('monitoring.token_uses_of', ['used' => $selected->uses, 'max' => $selected->max_uses]) : __('monitoring.token_uses', ['used' => $selected->uses]) }}
                        · {{ $selected->expires_at ? __('monitoring.token_expires_on', ['date' => $selected->expires_at->format('Y-m-d')]) : __('monitoring.token_never_expires') }}
                    </div>
                @endif
            @endif
        </div>
    </div>

    {{-- ============================ 2. INSTALL ============================ --}}
    <div class="card">
        <div class="card-header"><h5 class="card-title mb-0"><span class="rd-step">2</span>{{ __('monitoring.step_install') }}</h5></div>
        <div class="card-body">
            <ul class="nav nav-tabs nav-bordered rd-tabbar mb-3">
                @foreach ($osTabs as $key => [$icon, $label])
                    <li class="nav-item">
                        <a href="#" wire:click.prevent="setOs('{{ $key }}')" class="nav-link {{ $os === $key ? 'active' : '' }}"><i class="{{ $icon }} me-1"></i><span>{{ $label }}</span></a>
                    </li>
                @endforeach
            </ul>

            <p class="fs-13 text-muted mb-3">{{ $requires[$os] }}</p>

            @if (($downloads[$os] ?? []) === [])
                <div class="alert alert-info py-2 fs-13">{{ __('monitoring.no_build_published', ['version' => $version]) }}</div>
            @endif

            <h6 class="mb-1">{{ __('monitoring.install_one_command') }}</h6>
            <p class="fs-13 text-muted mb-2">{{ $hints[$os] }}</p>
            <div class="rd-agent-cmd mb-3">
                <textarea class="form-control rd-mono fs-13" rows="3" readonly aria-label="{{ __('monitoring.install_command') }}">{{ $commands[$os] }}</textarea>
                <button type="button" class="btn btn-sm btn-light" onclick="rdCopyPrevious(this)" @disabled(! $selected)><i class="ri-file-copy-line me-1"></i>{{ __('monitoring.copy') }}</button>
            </div>
            @if (! $selected)
                <p class="fs-13 text-warning mb-3">{{ __('monitoring.token_first') }}</p>
            @endif

            <details class="rd-mon-details mb-3">
                <summary>{{ __('monitoring.install_manual') }}</summary>
                <div class="pt-2">
                    @if ($downloads[$os] ?? [])
                        <ul class="list-unstyled mb-2">
                            @foreach ($downloads[$os] as $file)
                                <li class="mb-2">
                                    <a href="{{ $file['url'] }}" class="fw-medium" rel="noopener"><i class="ri-download-2-line me-1"></i>{{ $file['name'] }}</a>
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $file['arch'] }}</span>
                                    <div class="fs-12 text-muted rd-mono text-break">sha256 {{ $file['sha256'] }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <p class="fs-13 text-muted mb-2">{{ $os === 'macos' ? __('monitoring.manual_hint_macos') : __('monitoring.manual_hint_arm') }}</p>
                    <div class="rd-agent-cmd">
                        <textarea class="form-control rd-mono fs-13" rows="3" readonly aria-label="{{ __('monitoring.install_command') }}">{{ $manual[$os] }}</textarea>
                        <button type="button" class="btn btn-sm btn-light" onclick="rdCopyPrevious(this)" @disabled(! $selected)><i class="ri-file-copy-line me-1"></i>{{ __('monitoring.copy') }}</button>
                    </div>
                </div>
            </details>

            <h6 class="mb-2">{{ __('monitoring.install_options') }}</h6>
            <dl class="rd-mon-opts fs-13 mb-3">
                <dt class="rd-mono">--no-commands</dt><dd>{{ __('monitoring.option_no_commands') }}</dd>
                <dt class="rd-mono">--ca-file &lt;pem&gt;</dt><dd>{{ __('monitoring.option_ca_file') }}</dd>
            </dl>

            <h6 class="mb-2">{{ __('monitoring.check_and_remove') }}</h6>
            <div class="fs-13">
                <div class="mb-1"><span class="text-muted">{{ __('monitoring.check_status') }}</span> <code class="rd-mono">{{ $bin[$os] }} status</code></div>
                <div><span class="text-muted">{{ __('monitoring.remove_agent') }}</span> <code class="rd-mono">{{ $bin[$os] }} uninstall</code></div>
            </div>
        </div>
    </div>

    {{-- ============================ 3. CHECK ============================ --}}
    <div class="card">
        <div class="card-header"><h5 class="card-title mb-0"><span class="rd-step">3</span>{{ __('monitoring.step_check') }}</h5></div>
        <div class="card-body">
            @if ($newAgents->isEmpty())
                <div class="d-flex align-items-center gap-2 text-muted">
                    @if ($selected)<span class="spinner-grow spinner-grow-sm text-primary" role="status" aria-hidden="true"></span>@endif
                    <span>{{ $selected ? __('monitoring.waiting_for_agent') : __('monitoring.check_after_install') }}</span>
                </div>
            @else
                <ul class="list-unstyled mb-0">
                    @foreach ($newAgents as $agent)
                        <li class="d-flex align-items-center gap-2 py-1" wire:key="new-{{ $agent->id }}">
                            <x-platform-icon :platform="$agent->platform()" size="fs-18"/>
                            <a href="{{ $agent->url() }}" class="fw-medium">{{ $agent->displayName() }}</a>
                            @include('monitoring.partials.agent-state', ['agent' => $agent])
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
