<div>
    <div class="card">

        {{-- Toolbar --}}
        <div class="rd-toolbar">
            <div>
                <h4 class="header-title">{{ __('common.client_downloads') }}</h4>
                <p class="rd-card-sub mb-0">
                    {{ __('downloads.installers_you_built_with') }} <strong>{{ __('common.build_installers') }}</strong>{{ __('downloads.published_on_sign_in_page') }}
                    <a href="{{ route('downloads.index') }}" target="_blank" rel="noopener">{{ route('downloads.index') }}</a>
                    {{ __('downloads.link_anyone_can_open_no') }}
                </p>
            </div>
            <div class="rd-toolbar-actions">
                <button type="button" class="btn btn-primary" wire:click="create" @disabled(! $canManage)>
                    <i class="ri-upload-2-line"></i>{{ __('downloads.upload_build') }}
                </button>
            </div>
        </div>

        {{-- Desktop table (md and up) --}}
        <div class="table-responsive d-none d-md-block">
            <table class="table table-hover table-centered mb-0">
                <thead>
                <tr>
                    <th style="width: 42px;">{{ __('common.os') }}</th>
                    <th>{{ __('downloads.label') }}</th>
                    <th>{{ __('downloads.file') }}</th>
                    <th>{{ __('common.size') }}</th>
                    <th>{{ __('common.version') }}</th>
                    <th>{{ __('downloads.downloads') }}</th>
                    <th>{{ __('common.status') }}</th>
                    <th class="text-end">{{ __('common.action') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($downloads as $download)
                    <tr wire:key="cd-{{ $download->id }}">
                        <td><x-platform-icon :platform="$download->platform" /></td>
                        <td class="fw-semibold">
                            {{ $download->label }}
                            @if ($download->notes)
                                <span class="d-block rd-card-sub">{{ $download->notes }}</span>
                            @endif
                        </td>
                        <td>
                            <span class="rd-mono fs-13">{{ $download->original_name }}</span>
                            @unless ($download->fileExists())
                                {{-- The bytes live in the /data volume; a row without them
                                     means the volume was replaced, not that the row is wrong. --}}
                                <span class="badge bg-danger-subtle text-danger ms-1" title="{{ __('downloads.stored_file_is_missing_from') }}">{{ __('downloads.file_missing') }}</span>
                            @endunless
                        </td>
                        <td>{{ $download->humanSize() }}</td>
                        <td>{{ $download->version ?: '—' }}</td>
                        <td><span class="badge bg-secondary-subtle text-secondary">{{ $download->download_count }}</span></td>
                        <td>
                            @if ($download->is_published)
                                <span class="badge bg-success-subtle text-success">{{ __('downloads.published') }}</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">{{ __('downloads.hidden') }}</span>
                            @endif
                        </td>
                        <td class="text-end rd-rowact">
                            <a href="javascript:void(0);" class="rd-iconbtn me-1" title="{{ __('downloads.move_up') }}"
                               wire:click="move({{ $download->id }}, 'up')"><i class="ri-arrow-up-line"></i></a>
                            <a href="javascript:void(0);" class="rd-iconbtn me-2" title="{{ __('downloads.move_down') }}"
                               wire:click="move({{ $download->id }}, 'down')"><i class="ri-arrow-down-line"></i></a>
                            <a href="javascript:void(0);" class="rd-act me-2"
                               wire:click="togglePublished({{ $download->id }})">{{ $download->is_published ? __('downloads.hide') : __('downloads.publish') }}</a>
                            <a href="javascript:void(0);" class="rd-act me-2" wire:click="edit({{ $download->id }})">{{ __('common.edit') }}</a>
                            <a href="javascript:void(0);" class="text-danger"
                               wire:click="deleteDownload({{ $download->id }})"
                               wire:confirm="{{ __('downloads.delete_this_build_uploaded_file') }}">{{ __('common.delete') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="rd-empty-cell">
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="ri-download-cloud-line"></i></div>
                                <p class="rd-empty-title">{{ __('downloads.no_client_builds_uploaded_yet') }}</p>
                                <p class="rd-empty-text">
                                    {{ __('downloads.build_installer_with') }} <strong>{{ __('common.build_installers') }}</strong>{{ __('downloads.then_upload_it_here_cortendesk') }}
                                </p>
                                <button type="button" class="btn btn-sm btn-outline-light" wire:click="create" @disabled(! $canManage)>{{ __('downloads.upload_build') }}</button>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile card list (below md) --}}
        <div class="d-md-none rd-cardlist">
            @forelse ($downloads as $download)
                <div class="rd-mini" wire:key="mcd-{{ $download->id }}">
                    <div class="rd-mini-head">
                        <div class="min-width-0">
                            <span class="rd-mini-title">
                                <x-platform-icon :platform="$download->platform" size="fs-16" class="me-1" />{{ $download->label }}
                            </span>
                            <span class="rd-mini-sub">{{ $download->original_name }}</span>
                        </div>
                        @if ($download->is_published)
                            <span class="badge bg-success-subtle text-success">{{ __('downloads.published') }}</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary">{{ __('downloads.hidden') }}</span>
                        @endif
                    </div>
                    <div class="rd-mini-foot">
                        <span class="rd-mini-sub">
                            {{ $download->humanSize() }}@if ($download->version) · {{ $download->version }}@endif ·
                            {{ __('downloads.count_downloads', ['count' => $download->download_count]) }}
                        </span>
                        <div class="rd-mini-acts">
                            <a href="javascript:void(0);" class="rd-iconbtn" title="{{ $download->is_published ? __('downloads.hide') : __('downloads.publish') }}"
                               wire:click="togglePublished({{ $download->id }})"><i class="{{ $download->is_published ? 'ri-eye-off-line' : 'ri-eye-line' }}"></i></a>
                            <a href="javascript:void(0);" class="rd-iconbtn" title="{{ __('common.edit') }}"
                               wire:click="edit({{ $download->id }})"><i class="ri-pencil-line"></i></a>
                            <a href="javascript:void(0);" class="rd-iconbtn text-danger" title="{{ __('common.delete') }}"
                               wire:click="deleteDownload({{ $download->id }})"
                               wire:confirm="{{ __('downloads.delete_this_build_uploaded_file') }}"><i class="ri-delete-bin-line"></i></a>
                        </div>
                    </div>
                </div>
            @empty
                <div class="rd-empty">
                    <div class="rd-empty-icon"><i class="ri-download-cloud-line"></i></div>
                    <p class="rd-empty-title">{{ __('downloads.no_client_builds_uploaded_yet') }}</p>
                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="create" @disabled(! $canManage)>{{ __('downloads.upload_build') }}</button>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Preview of what the sign-in page shows, so publishing is not a guess --}}
    @if ($downloads->where('is_published', true)->isNotEmpty())
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">{{ __('downloads.sign_in_page_preview') }}</h5>
            </div>
            <div class="card-body">
                <x-client-download-links :downloads="$downloads->where('is_published', true)->values()" compact />
            </div>
        </div>
    @endif

    {{-- Upload / edit modal (plain Bootstrap markup, toggled by Livewire) --}}
    @if ($showModal)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form wire:submit="save">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $editing ? __('downloads.edit_build') : __('downloads.upload_build') }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="{{ __('common.close') }}"></button>
                        </div>
                        <div class="modal-body">

                            <div class="mb-3">
                                <label class="form-label" for="cd-file">
                                    {{ __('downloads.installer') }} {!! $editing ? '' : '<span class="text-danger">*</span>' !!}
                                </label>
                                <input type="file" id="cd-file" class="form-control @error('file') is-invalid @enderror"
                                       wire:model="file">
                                @error('file') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">
                                    {{ $editing ? __('downloads.pick_file_only_to_replace') : '' }}
                                    {{ __('downloads.up_to_mb_mb_platform', ['mb' => round($maxKb / 1024)]) }}
                                </div>
                                <div wire:loading wire:target="file" class="form-text">{{ __('common.uploading') }}</div>
                                {{-- Livewire validates the temporary upload at its own endpoint,
                                     before the component sees it. A rejection there (or a 413 from
                                     nginx) never re-renders anything, so without this the operator
                                     picks a too-large file and watches nothing happen. --}}
                                <div class="invalid-feedback d-block" style="display: none;" data-cd-upload-error>
                                    {{ __('downloads.that_file_was_rejected_before', ['mb' => round($maxKb / 1024)]) }}
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="cd-label">{{ __('downloads.label') }} <span class="text-danger">*</span></label>
                                <input type="text" id="cd-label" class="form-control @error('label') is-invalid @enderror"
                                       wire:model="label" autocomplete="off" placeholder="{{ __('downloads.e_g_windows_64_bit') }}">
                                @error('label') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="row">
                                <div class="col-sm-6 mb-3">
                                    <label class="form-label" for="cd-platform">{{ __('downloads.platform_icon') }}</label>
                                    <select id="cd-platform" class="form-select @error('platform') is-invalid @enderror"
                                            wire:model="platform">
                                        @foreach ($platformOptions as $value => $text)
                                            <option value="{{ $value }}">{{ $text }}</option>
                                        @endforeach
                                    </select>
                                    @error('platform') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-sm-6 mb-3">
                                    <label class="form-label" for="cd-version">{{ __('common.version') }}</label>
                                    <input type="text" id="cd-version" class="form-control @error('version') is-invalid @enderror"
                                           wire:model="version" placeholder="{{ __('downloads.e_g_1_4_0') }}">
                                    @error('version') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="cd-notes">{{ __('common.note') }}</label>
                                <textarea id="cd-notes" rows="2" class="form-control @error('notes') is-invalid @enderror"
                                          wire:model="notes" placeholder="{{ __('downloads.shown_under_label_in_console') }}"></textarea>
                                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="mb-0">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="cd-published"
                                           wire:model="isPublished">
                                    <label class="form-check-label" for="cd-published">{{ __('downloads.published') }}</label>
                                </div>
                                <div class="form-text">
                                    {{ __('downloads.published_builds_are_downloadable_by') }} <strong>{{ __('downloads.anyone') }}</strong> {{ __('downloads.with_link_that_is_what') }}
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">{{ __('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary" wire:target="file" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="save">{{ $editing ? __('account.save_changes') : __('downloads.upload') }}</span>
                                <span wire:loading wire:target="save">{{ __('common.saving') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif

    @script
    <script>
        // Registered once per component, on document, so it still fires for the
        // modal markup Livewire adds and removes on each open/close.
        const cdUploadError = (show) => {
            const box = document.querySelector('[data-cd-upload-error]');
            if (box) {
                box.style.display = show ? 'block' : 'none';
            }
        };

        document.addEventListener('livewire-upload-start', () => cdUploadError(false));
        document.addEventListener('livewire-upload-error', () => cdUploadError(true));
    </script>
    @endscript
</div>
