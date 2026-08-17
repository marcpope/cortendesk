@props(['downloads' => null, 'compact' => false])

@php
    // Loaded here when no collection is passed in, so a caller (the sign-in
    // page) stays a single line and needs no controller change. Same shape as
    // the sidebar reading rdgen_url out of Setting.
    $downloads = $downloads ?? \App\Models\ClientDownload::published()->ordered()->get();
@endphp

@if ($downloads->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'rd-dl' . ($compact ? ' rd-dl-compact' : '')]) }}>
        @foreach ($downloads as $download)
            <a class="rd-dl-tile" href="{{ route('downloads.show', $download) }}"
               title="{{ $download->label }}{{ $download->version ? ' · ' . $download->version : '' }} · {{ $download->humanSize() }}">
                <x-platform-icon :platform="$download->platform" size="{{ $compact ? 'fs-22' : 'fs-24' }}" class="rd-dl-icon" />
                <span class="rd-dl-text">
                    <span class="rd-dl-label">{{ $download->label }}</span>
                    <span class="rd-dl-meta">
                        {{ $download->humanSize() }}@if ($download->version) · {{ $download->version }}@endif
                    </span>
                </span>
                <i class="ri-download-2-line rd-dl-arrow"></i>
            </a>
        @endforeach
    </div>
@endif
