<div class="card" id="share" wire:poll.10s>
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="ri-share-forward-line me-1"></i>{{ __('shares.card_title') }}</h5>
        @if ($canManage && ! $creating)
            <button type="button" class="btn btn-sm btn-primary" wire:click="startCreate">
                <i class="ri-add-line me-1"></i>{{ __('shares.new_share') }}</button>
        @endif
    </div>
    <div class="card-body pt-0">
        <p class="text-muted fs-13">{{ __('shares.card_intro') }}</p>
        @if ($passwordOnly)
            <div class="alert alert-warning fs-13" role="alert"><i class="ri-error-warning-line me-1"></i>{{ __('shares.password_only', ['option' => __('labels.strategy_opt_approve_mode')]) }}</div>
        @endif

        @if ($newLink)
            <div class="alert alert-success" role="status">
                <p class="fw-semibold mb-1">{{ __('shares.created_title') }}</p>
                <p class="fs-13 mb-2">{{ __('shares.created_help') }}</p>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace fs-13" readonly value="{{ $newLink }}" onfocus="this.select()">
                    <button type="button" class="btn btn-outline-light" onclick="rdCopyPrevious(this)">
                        <i class="ri-file-copy-line me-1"></i>{{ __('shares.copy') }}</button>
                    <button type="button" class="btn btn-outline-light" wire:click="dismissLink">{{ __('shares.done') }}</button>
                </div>
            </div>
        @endif

        @if ($creating)
            <form wire:submit="create" class="border rounded p-3 mb-3">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="share-label">{{ __('shares.label') }}</label>
                        <input id="share-label" type="text" class="form-control @error('label') is-invalid @enderror" maxlength="100"
                               wire:model="label" placeholder="{{ __('shares.label_placeholder') }}" required>
                        @error('label') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="share-duration">{{ __('shares.duration') }}</label>
                        <select id="share-duration" class="form-select" wire:model="duration">
                            @foreach (\App\Models\DeviceShare::DURATIONS as $key => $minutes)
                                <option value="{{ $key }}">{{ \App\Models\DeviceShare::durationLabel($key) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="share-viewers">{{ __('shares.max_viewers') }}</label>
                        <input id="share-viewers" type="number" min="1" max="{{ \App\Models\DeviceShare::MAX_VIEWERS }}" class="form-control @error('maxViewers') is-invalid @enderror" wire:model="maxViewers">
                        @error('maxViewers') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <div class="form-text">{{ __('shares.accept_help') }}</div>
                    </div>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('shares.create') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="cancelCreate">{{ __('shares.cancel') }}</button>
                </div>
            </form>
        @endif

        @forelse ($shares as $share)
            @php
                $state = $share->revoked_at ? 'revoked' : ($share->isExpired() ? 'expired' : 'active');
            @endphp
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 border-top" wire:key="share{{ $share->id }}">
                <div class="min-width-0">
                    <span class="fw-semibold">{{ $share->label }}</span>
                    @if ($state === 'active')
                        <span class="badge bg-success-subtle text-success ms-1">{{ __('shares.active') }}</span>
                        @if ($share->live_viewers > 0)
                            <span class="badge bg-info-subtle text-info ms-1"><i class="ri-eye-line me-1"></i>{{ __('shares.viewers_live', ['count' => $share->live_viewers]) }}</span>
                        @endif
                    @else
                        <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $state === 'revoked' ? __('shares.revoked') : __('shares.expired') }}</span>
                    @endif
                    <span class="d-block fs-13 text-muted">
                        {{ trans_choice('shares.max_viewers_short', $share->max_viewers, ['count' => $share->max_viewers]) }}
                        @if ($state === 'active') · {{ __('shares.expires', ['when' => $share->expires_at->diffForHumans()]) }} @endif
                        · {{ $share->last_used_at ? __('shares.last_used', ['when' => $share->last_used_at->diffForHumans()]) : __('shares.never_used') }}
                        @if ($share->user) · {{ $share->user->name ?: $share->user->username }} @endif
                    </span>
                </div>
                @if ($canManage && $state === 'active')
                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="revoke({{ $share->id }})"
                            wire:confirm="{{ __('shares.end_confirm') }}">
                        <i class="ri-close-circle-line me-1"></i>{{ __('shares.end') }}</button>
                @endif
            </div>
        @empty
            <p class="text-muted mb-0">{{ __('shares.none') }}</p>
        @endforelse
    </div>
</div>
