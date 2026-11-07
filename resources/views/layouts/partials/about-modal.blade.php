{{-- About dialog, opened from the version in the sidebar footer. Only the
     people who see that version line get it. --}}
@if (auth()->user()?->consoleAllows('setting'))
    @php
        $aboutVersion = config('cortendesk.api_version');
        $aboutReleased = config('cortendesk.released_at');
        $aboutUpgrade = \App\Support\UpdateChecker::upgradeAvailable();
        $aboutChecked = \App\Support\UpdateChecker::latestVersion() !== null;
    @endphp
    <div class="modal fade" id="rd-about" tabindex="-1" aria-labelledby="rd-about-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered rd-about-dialog">
            <div class="modal-content rd-about">
                <button type="button" class="btn-close rd-about-close" data-bs-dismiss="modal" aria-label="{{ __('common.close') }}"></button>

                <img src="{{ \App\Support\Asset::url('assets/images/cortendesk-sm.svg') }}" alt="" width="64" height="64" class="rd-about-logo">
                <h5 class="rd-about-title" id="rd-about-title">CortenDesk</h5>
                <div class="rd-about-version">{{ __('nav.about_version', ['version' => $aboutVersion]) }}</div>
                @if ($aboutReleased)
                    <div class="rd-about-released">{{ __('nav.about_released', ['date' => \Illuminate\Support\Carbon::parse($aboutReleased)->locale(app()->getLocale())->isoFormat('LL')]) }}</div>
                @endif

                @if ($aboutUpgrade)
                    <div class="rd-about-status rd-about-status-new">
                        <i class="ri-download-cloud-2-line"></i>
                        <span>{{ __('nav.about_new_version', ['version' => $aboutUpgrade]) }}</span>
                    </div>
                    <a href="{{ \App\Support\UpdateChecker::releaseNotesUrl($aboutUpgrade) }}" target="_blank" rel="noopener" class="btn btn-primary btn-sm rd-about-notes">
                        {{ __('nav.about_release_notes_for', ['version' => $aboutUpgrade]) }} <i class="ri-arrow-right-up-line"></i>
                    </a>
                @else
                    @if (\App\Support\UpdateChecker::enabled())
                        <div class="rd-about-status {{ $aboutChecked ? 'rd-about-status-ok' : 'rd-about-status-unknown' }}">
                            @if ($aboutChecked)
                                <i class="ri-check-line"></i><span>{{ __('nav.up_to_date') }}</span>
                            @else
                                <i class="rd-dot rd-dot-lg"></i><span>{{ __('nav.release_check_unavailable') }}</span>
                            @endif
                        </div>
                    @endif
                    <a href="{{ \App\Support\UpdateChecker::releaseNotesUrl($aboutVersion) }}" target="_blank" rel="noopener" class="btn btn-light btn-sm rd-about-notes">
                        {{ __('nav.about_release_notes_for', ['version' => $aboutVersion]) }} <i class="ri-arrow-right-up-line"></i>
                    </a>
                @endif

                <div class="rd-about-foot">
                    <a href="https://github.com/marcpope/cortendesk/blob/main/LICENSE" target="_blank" rel="noopener">{{ __('nav.about_license') }}</a>
                    <p>{!! __('nav.about_copyright', ['year' => now()->year, 'author' => '<a href="https://github.com/marcpope" target="_blank" rel="noopener">Marc Pope</a>']) !!}</p>
                </div>
            </div>
        </div>
    </div>
@endif
