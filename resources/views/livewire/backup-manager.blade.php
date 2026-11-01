<div>
    @if ($notice)
        <div class="alert alert-{{ $notice['tone'] }} py-2 mb-3" style="white-space: pre-line;">{{ $notice['text'] }}</div>
    @endif
    @if ($saved)
        <div class="alert alert-success py-2 mb-3">{{ __('backups.backup_settings_saved') }}</div>
    @endif

    <div class="row">
        <div class="col-lg-7">
            <div class="card">
                <div class="rd-toolbar">
                    <div>
                        <h4 class="header-title">{{ __('backups.backups') }}</h4>
                        <p class="rd-card-sub mb-0">
                            {{ __('backups.each_archive_holds_whole_database') }}
                            @if ($includesServerFiles) {{ __('backups.and_id_servers_key_pair') }} @endif
                            {{ __('backups.everything_needed_to_rebuild_this') }}
                        </p>
                    </div>
                    <div class="rd-toolbar-actions">
                        <button type="button" class="btn btn-primary" wire:click="backupNow" wire:loading.attr="disabled" wire:target="backupNow" @disabled(! $canManage)>
                            <span wire:loading.remove wire:target="backupNow"><i class="ri-archive-line me-1"></i>{{ __('backups.back_up_now') }}</span>
                            <span wire:loading wire:target="backupNow"><i class="ri-loader-4-line me-1"></i>{{ __('backups.backing_up') }}</span>
                        </button>
                    </div>
                </div>

                @if ($directoryError)
                    <div class="alert alert-danger py-2 mx-3">{{ $directoryError }}</div>
                @endif

                {{-- Desktop table (md and up) --}}
                <div class="table-responsive d-none d-md-block">
                    <table class="table table-hover table-centered mb-0">
                        <thead>
                        <tr>
                            <th>{{ __('backups.taken') }}</th>
                            <th>{{ __('common.size') }}</th>
                            <th>{{ __('common.version') }}</th>
                            <th>{{ __('backups.trigger') }}</th>
                            <th class="text-end">{{ __('common.action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($backups as $backup)
                            <tr wire:key="bk-{{ $backup['name'] }}">
                                <td>
                                    <span class="fw-semibold">{{ $backup['created_at']->format('Y-m-d H:i') }}</span>
                                    <span class="d-block rd-mono fs-13 text-muted">{{ $backup['name'] }}</span>
                                </td>
                                <td>{{ \App\Services\Backups\BackupService::humanSize($backup['size']) }}</td>
                                <td class="rd-mono">{{ $backup['manifest']['app_version'] ?? '—' }}</td>
                                <td><span class="badge bg-secondary-subtle text-secondary">{{ $backup['manifest']['trigger'] ?? __('common.unknown') }}</span></td>
                                <td class="text-end text-nowrap">
                                    @if ($canManage)
                                        <a class="btn btn-sm btn-light" href="{{ route('settings.backups.download', $backup['name']) }}" title="{{ __('common.download') }}"><i class="ri-download-2-line"></i></a>
                                        <button type="button" class="btn btn-sm btn-light text-warning" title="{{ __('common.restore') }}"
                                                wire:click="restore('{{ $backup['name'] }}')" wire:loading.attr="disabled"
                                                wire:confirm="{{ __('backups.restore_name_n_nthis_overwrites', ['name' => $backup['name']]) }}">
                                            <i class="ri-history-line"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-light text-danger" title="{{ __('common.delete') }}"
                                                wire:click="delete('{{ $backup['name'] }}')"
                                                wire:confirm="{{ __('backups.delete_name', ['name' => $backup['name']]) }}">
                                            <i class="ri-delete-bin-6-line"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">{{ __('backups.no_backups_yet') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Mobile cards --}}
                <div class="d-md-none rd-cardlist">
                    @forelse ($backups as $backup)
                        <div class="rd-mini" wire:key="mbk-{{ $backup['name'] }}">
                            <div class="rd-mini-head">
                                <div class="min-width-0">
                                    <span class="rd-mini-title">{{ $backup['created_at']->format('Y-m-d H:i') }}</span>
                                    <span class="rd-mini-sub">{{ \App\Services\Backups\BackupService::humanSize($backup['size']) }} · v{{ $backup['manifest']['app_version'] ?? '?' }} · {{ $backup['manifest']['trigger'] ?? __('common.unknown') }}</span>
                                </div>
                            </div>
                            @if ($canManage)
                                <div class="d-flex gap-2 mt-2">
                                    <a class="btn btn-sm btn-light" href="{{ route('settings.backups.download', $backup['name']) }}"><i class="ri-download-2-line me-1"></i>{{ __('common.download') }}</a>
                                    <button type="button" class="btn btn-sm btn-light text-warning"
                                            wire:click="restore('{{ $backup['name'] }}')"
                                            wire:confirm="{{ __('backups.restore_name_n_nthis_overwrites', ['name' => $backup['name']]) }}">
                                        <i class="ri-history-line me-1"></i>{{ __('common.restore') }}
                                    </button>
                                    <button type="button" class="btn btn-sm btn-light text-danger"
                                            wire:click="delete('{{ $backup['name'] }}')"
                                            wire:confirm="{{ __('backups.delete_name', ['name' => $backup['name']]) }}">
                                        <i class="ri-delete-bin-6-line"></i>
                                    </button>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-muted text-center py-4 mb-0">{{ __('backups.no_backups_yet') }}</p>
                    @endforelse
                </div>

                <div class="card-body border-top">
                    @if ($lastResult)
                        <p class="fs-13 mb-2 {{ ($lastResult['ok'] ?? false) ? 'text-muted' : 'text-danger' }}">
                            <i class="{{ ($lastResult['ok'] ?? false) ? 'ri-checkbox-circle-line' : 'ri-error-warning-line' }} me-1"></i>
                            {{ match ($lastResult['trigger'] ?? '') { 'scheduled' => __('backups.last_scheduled_run'), 'manual' => __('backups.last_manual_run'), default => __('backups.last_trigger_run', ['trigger' => $lastResult['trigger'] ?? '']) } }}{{ $lastResult['at'] ? ' '.$lastResult['at']->diffForHumans() : '' }}: {{ $lastResult['message'] ?? '' }}
                        </p>
                    @endif
                    <p class="fs-13 text-muted mb-0">{{ __('backups.stored_in') }} <span class="rd-mono">{{ $directory }}</span>.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">{{ __('backups.restore_from_file') }}</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted fs-13">{{ __('backups.upload_archive_from_another_install') }}</p>
                    <div class="alert alert-warning py-2 fs-13">
                        <i class="ri-alert-line me-1"></i><strong>{{ __('backups.restoring_overwrites_all_your_settings') }}</strong>{{ __('backups.users_devices_address_books_and') }}
                    </div>
                    <form wire:submit="restoreUpload">
                        <div class="mb-3">
                            <input type="file" class="form-control @error('upload') is-invalid @enderror" wire:model="upload" accept=".tar" @disabled(! $canManage)>
                            @error('upload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div wire:loading wire:target="upload" class="form-text">{{ __('common.uploading') }}</div>
                        </div>
                        <button type="submit" class="btn btn-warning" wire:loading.attr="disabled"
                                wire:confirm="{{ __('backups.restore_from_uploaded_file_n') }}"
                                @disabled(! $canManage || ! $upload)>
                            <i class="ri-history-line me-1"></i>{{ __('backups.restore_from_file_2') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <form wire:submit="save">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">{{ __('backups.schedule') }}</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="backupEnabled" wire:model="enabled" @disabled(! $canManage)>
                                <label class="form-check-label" for="backupEnabled">{{ __('backups.automatic_backups') }}</label>
                            </div>
                            <div class="form-text">{{ __('backups.runs_every_day_at_03') }}</div>
                        </div>
                        <div class="row g-3">
                            @foreach ([__('backups.keep_daily') => 'keepDaily', __('backups.keep_weekly') => 'keepWeekly', __('backups.keep_monthly') => 'keepMonthly'] as $label => $field)
                                <div class="col-4">
                                    <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                                    <input type="number" id="{{ $field }}" class="form-control @error($field) is-invalid @enderror" wire:model="{{ $field }}" min="0" max="{{ \App\Services\Backups\BackupService::MAX_KEEP }}" @disabled(! $canManage)>
                                    @error($field) <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @endforeach
                        </div>
                        <div class="form-text mt-2">{{ __('backups.all_three_apply_at_once', ['daily' => $keepDaily, 'weekly' => $keepWeekly, 'monthly' => $keepMonthly]) }}</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">{{ __('backups.off_site_copy') }}</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="backupTarget">{{ __('backups.copy_each_backup_to') }}</label>
                            <select id="backupTarget" class="form-select @error('target') is-invalid @enderror" wire:model.live="target" @disabled(! $canManage)>
                                @foreach ($targets as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('target') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text">{{ __('backups.mirrors_list_on_this_server') }}</div>
                        </div>

                        @if ($target === 'ftp')
                            <div class="row g-3">
                                <div class="col-8">
                                    <label class="form-label">{{ __('backups.host') }}</label>
                                    <input type="text" class="form-control @error('ftpHost') is-invalid @enderror" wire:model="ftpHost" placeholder="ftp.example.com" @disabled(! $canManage)>
                                    @error('ftpHost') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-4">
                                    <label class="form-label">{{ __('common.port') }}</label>
                                    <input type="number" class="form-control @error('ftpPort') is-invalid @enderror" wire:model="ftpPort" min="1" max="65535" @disabled(! $canManage)>
                                    @error('ftpPort') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">{{ __('common.username') }}</label>
                                    <input type="text" class="form-control" wire:model="ftpUsername" autocomplete="off" @disabled(! $canManage)>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">{{ __('common.password') }}</label>
                                    <input type="password" class="form-control" wire:model="ftpPassword" autocomplete="new-password"
                                           placeholder="{{ $ftpPasswordSet ? '•••••••• (stored, blank keeps it)' : '' }}" @disabled(! $canManage)>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">{{ __('backups.directory') }}</label>
                                    <input type="text" class="form-control" wire:model="ftpPath" placeholder="backups/cortendesk" @disabled(! $canManage)>
                                    <div class="form-text">{{ __('backups.created_if_missing_relative_to') }}</div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="ftpTls" wire:model="ftpTls" @disabled(! $canManage)>
                                        <label class="form-check-label" for="ftpTls">{{ __('backups.use_tls_explicit_ftps') }}</label>
                                    </div>
                                    <div class="form-text">{{ __('backups.plain_ftp_sends_password_in') }}</div>
                                </div>
                            </div>
                        @elseif ($target === 's3')
                            <div class="row g-3">
                                <div class="col-sm-7">
                                    <label class="form-label">{{ __('backups.bucket') }}</label>
                                    <input type="text" class="form-control @error('s3Bucket') is-invalid @enderror" wire:model="s3Bucket" @disabled(! $canManage)>
                                    @error('s3Bucket') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-sm-5">
                                    <label class="form-label">{{ __('backups.region') }}</label>
                                    <input type="text" class="form-control" wire:model="s3Region" placeholder="us-east-1" @disabled(! $canManage)>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">{{ __('backups.endpoint') }}</label>
                                    <input type="text" class="form-control @error('s3Endpoint') is-invalid @enderror" wire:model="s3Endpoint" placeholder="https://s3.us-east-1.amazonaws.com" @disabled(! $canManage)>
                                    @error('s3Endpoint') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">{{ __('backups.leave_empty_for_aws_minio') }}</div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">{{ __('backups.folder_prefix') }}</label>
                                    <input type="text" class="form-control" wire:model="s3Prefix" placeholder="cortendesk" @disabled(! $canManage)>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">{{ __('backups.access_key_id') }}</label>
                                    <input type="text" class="form-control rd-mono" wire:model="s3AccessKey" autocomplete="off" @disabled(! $canManage)>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">{{ __('backups.secret_access_key') }}</label>
                                    <input type="password" class="form-control" wire:model="s3SecretKey" autocomplete="new-password"
                                           placeholder="{{ $s3SecretKeySet ? '•••••••• (stored, blank keeps it)' : '' }}" @disabled(! $canManage)>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="s3PathStyle" wire:model="s3PathStyle" @disabled(! $canManage)>
                                        <label class="form-check-label" for="s3PathStyle">{{ __('backups.path_style_addressing') }}</label>
                                    </div>
                                    <div class="form-text">{{ __('backups.bucket_in_path_instead_of') }}</div>
                                </div>
                            </div>
                        @endif

                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-primary" @disabled(! $canManage)><i class="ri-save-line me-1"></i>{{ __('common.save') }}</button>
                            @if ($target !== 'none')
                                <button type="button" class="btn btn-light" wire:click="testTarget" wire:loading.attr="disabled" wire:target="testTarget" @disabled(! $canManage)>
                                    <span wire:loading.remove wire:target="testTarget"><i class="ri-plug-line me-1"></i>{{ __('backups.test_connection') }}</span>
                                    <span wire:loading wire:target="testTarget"><i class="ri-loader-4-line me-1"></i>{{ __('backups.testing') }}</span>
                                </button>
                            @endif
                        </div>
                        @if ($testResult && $target !== 'none')
                            <div class="alert alert-{{ $testResult['tone'] }} py-2 mt-3 mb-0" role="status" style="white-space: pre-line;"
                                 wire:key="test-result-{{ $testRuns }}" x-init="$el.scrollIntoView({ block: 'nearest', behavior: 'smooth' })">{{ $testResult['text'] }}</div>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
