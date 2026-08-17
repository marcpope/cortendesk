<div>
    <div class="card">

        {{-- Toolbar --}}
        <div class="rd-toolbar">
            <div>
                <h4 class="header-title">Client Downloads</h4>
                <p class="rd-card-sub mb-0">
                    Installers you built with <strong>Build Installers</strong>, published on the sign-in page and at
                    <a href="{{ route('downloads.index') }}" target="_blank" rel="noopener">{{ route('downloads.index') }}</a>
                    — a link anyone can open, no console account needed.
                </p>
            </div>
            <div class="rd-toolbar-actions">
                <button type="button" class="btn btn-primary" wire:click="create" @disabled(! $canManage)>
                    <i class="ri-upload-2-line"></i>Upload Build
                </button>
            </div>
        </div>

        {{-- Desktop table (md and up) --}}
        <div class="table-responsive d-none d-md-block">
            <table class="table table-hover table-centered mb-0">
                <thead>
                <tr>
                    <th style="width: 42px;">OS</th>
                    <th>Label</th>
                    <th>File</th>
                    <th>Size</th>
                    <th>Version</th>
                    <th>Downloads</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
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
                                <span class="badge bg-danger-subtle text-danger ms-1" title="The stored file is missing from the downloads volume. Re-upload it.">file missing</span>
                            @endunless
                        </td>
                        <td>{{ $download->humanSize() }}</td>
                        <td>{{ $download->version ?: '—' }}</td>
                        <td><span class="badge bg-secondary-subtle text-secondary">{{ $download->download_count }}</span></td>
                        <td>
                            @if ($download->is_published)
                                <span class="badge bg-success-subtle text-success">Published</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Hidden</span>
                            @endif
                        </td>
                        <td class="text-end rd-rowact">
                            <a href="javascript:void(0);" class="rd-iconbtn me-1" title="Move up"
                               wire:click="move({{ $download->id }}, 'up')"><i class="ri-arrow-up-line"></i></a>
                            <a href="javascript:void(0);" class="rd-iconbtn me-2" title="Move down"
                               wire:click="move({{ $download->id }}, 'down')"><i class="ri-arrow-down-line"></i></a>
                            <a href="javascript:void(0);" class="rd-act me-2"
                               wire:click="togglePublished({{ $download->id }})">{{ $download->is_published ? 'Hide' : 'Publish' }}</a>
                            <a href="javascript:void(0);" class="rd-act me-2" wire:click="edit({{ $download->id }})">Edit</a>
                            <a href="javascript:void(0);" class="text-danger"
                               wire:click="deleteDownload({{ $download->id }})"
                               wire:confirm="Delete this build? The uploaded file is removed too and any link to it stops working.">Delete</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="rd-empty-cell">
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="ri-download-cloud-line"></i></div>
                                <p class="rd-empty-title">No client builds uploaded yet.</p>
                                <p class="rd-empty-text">
                                    Build an installer with <strong>Build Installers</strong>, then upload it here.
                                    CortenDesk reads the platform off the filename and shows the matching icon on the
                                    sign-in page.
                                </p>
                                <button type="button" class="btn btn-sm btn-outline-light" wire:click="create" @disabled(! $canManage)>Upload Build</button>
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
                            <span class="badge bg-success-subtle text-success">Published</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary">Hidden</span>
                        @endif
                    </div>
                    <div class="rd-mini-foot">
                        <span class="rd-mini-sub">
                            {{ $download->humanSize() }}@if ($download->version) · {{ $download->version }}@endif ·
                            {{ $download->download_count }} downloads
                        </span>
                        <div class="rd-mini-acts">
                            <a href="javascript:void(0);" class="rd-iconbtn" title="{{ $download->is_published ? 'Hide' : 'Publish' }}"
                               wire:click="togglePublished({{ $download->id }})"><i class="{{ $download->is_published ? 'ri-eye-off-line' : 'ri-eye-line' }}"></i></a>
                            <a href="javascript:void(0);" class="rd-iconbtn" title="Edit"
                               wire:click="edit({{ $download->id }})"><i class="ri-pencil-line"></i></a>
                            <a href="javascript:void(0);" class="rd-iconbtn text-danger" title="Delete"
                               wire:click="deleteDownload({{ $download->id }})"
                               wire:confirm="Delete this build? The uploaded file is removed too and any link to it stops working."><i class="ri-delete-bin-line"></i></a>
                        </div>
                    </div>
                </div>
            @empty
                <div class="rd-empty">
                    <div class="rd-empty-icon"><i class="ri-download-cloud-line"></i></div>
                    <p class="rd-empty-title">No client builds uploaded yet.</p>
                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="create" @disabled(! $canManage)>Upload Build</button>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Preview of what the sign-in page shows, so publishing is not a guess --}}
    @if ($downloads->where('is_published', true)->isNotEmpty())
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Sign-in page preview</h5>
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
                            <h5 class="modal-title">{{ $editing ? 'Edit Build' : 'Upload Build' }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">

                            <div class="mb-3">
                                <label class="form-label" for="cd-file">
                                    Installer {!! $editing ? '' : '<span class="text-danger">*</span>' !!}
                                </label>
                                <input type="file" id="cd-file" class="form-control @error('file') is-invalid @enderror"
                                       wire:model="file">
                                @error('file') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">
                                    {{ $editing ? 'Pick a file only to replace the one already stored. ' : '' }}
                                    Up to {{ round($maxKb / 1024) }} MB. The platform below is filled in from the
                                    filename — change it if the guess is wrong.
                                </div>
                                <div wire:loading wire:target="file" class="form-text">Uploading…</div>
                                {{-- Livewire validates the temporary upload at its own endpoint,
                                     before the component sees it. A rejection there (or a 413 from
                                     nginx) never re-renders anything, so without this the operator
                                     picks a too-large file and watches nothing happen. --}}
                                <div class="invalid-feedback d-block" style="display: none;" data-cd-upload-error>
                                    That file was rejected before it finished uploading — almost always
                                    because it is bigger than the {{ round($maxKb / 1024) }} MB limit.
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="cd-label">Label <span class="text-danger">*</span></label>
                                <input type="text" id="cd-label" class="form-control @error('label') is-invalid @enderror"
                                       wire:model="label" autocomplete="off" placeholder="e.g. Windows (64-bit)">
                                @error('label') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="row">
                                <div class="col-sm-6 mb-3">
                                    <label class="form-label" for="cd-platform">Platform icon</label>
                                    <select id="cd-platform" class="form-select @error('platform') is-invalid @enderror"
                                            wire:model="platform">
                                        @foreach ($platformOptions as $value => $text)
                                            <option value="{{ $value }}">{{ $text }}</option>
                                        @endforeach
                                    </select>
                                    @error('platform') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-sm-6 mb-3">
                                    <label class="form-label" for="cd-version">Version</label>
                                    <input type="text" id="cd-version" class="form-control @error('version') is-invalid @enderror"
                                           wire:model="version" placeholder="e.g. 1.4.0">
                                    @error('version') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="cd-notes">Note</label>
                                <textarea id="cd-notes" rows="2" class="form-control @error('notes') is-invalid @enderror"
                                          wire:model="notes" placeholder="Shown under the label in the console only."></textarea>
                                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="mb-0">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="cd-published"
                                           wire:model="isPublished">
                                    <label class="form-check-label" for="cd-published">Published</label>
                                </div>
                                <div class="form-text">
                                    Published builds are downloadable by <strong>anyone</strong> with the link — that is
                                    what makes them useful on a machine with no console account. Leave this off to stage
                                    a build first.
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary" wire:target="file" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="save">{{ $editing ? 'Save Changes' : 'Upload' }}</span>
                                <span wire:loading wire:target="save">Saving…</span>
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
