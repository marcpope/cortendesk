@component('mail.layout')
    <p style="margin:0 0 12px;font-size:17px;font-weight:600;">{{ __('mail.your_sign_in_code') }}</p>

    <p style="margin:0 0 12px;">{{ __('mail.someone_signed_in_as') }}
        <strong style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $username }}</strong>
        {{ __('mail.from_browser_this_console_has') }}</p>

    <p style="margin:20px 0;font-size:32px;font-weight:700;letter-spacing:8px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">
        {{ $code }}
    </p>

    <p style="margin:0 0 12px;font-size:13px;color:#7b8794;">
        {{ __('mail.code_expires_in_minutes_minutes', ['minutes' => $minutes]) }}
        @if ($ip)
            {{ __('mail.request_came_from_ip', ['ip' => $ip]) }}
        @endif
    </p>

    <p style="margin:0;font-size:13px;color:#7b8794;">
        {{ __('mail.if_this_was_not_you') }}
    </p>
@endcomponent
