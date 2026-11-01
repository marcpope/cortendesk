@component('mail.layout')
    <p style="margin:0 0 12px;font-size:17px;font-weight:600;">{{ __('mail.reset_your_password') }}</p>

    <p style="margin:0 0 12px;">{{ __('mail.someone_asked_to_reset_password') }}
        <strong style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $user->username }}</strong>
        {{ __('mail.on_app_use_link_below', ['app' => config('app.name')]) }}</p>

    <p style="margin:20px 0;">
        <a href="{{ $resetUrl }}"
           style="display:inline-block;padding:11px 20px;background:#f26c23;color:#ffffff;text-decoration:none;border-radius:5px;font-weight:600;">
            {{ __('mail.choose_new_password') }}
        </a>
    </p>

    <p style="margin:0 0 12px;font-size:13px;color:#7b8794;">
        {{ __('mail.link_expires_in_minutes_minutes', ['minutes' => $ttlMinutes]) }}
        @if ($requestedIp)
            {{ __('mail.request_came_from_ip', ['ip' => $requestedIp]) }}
        @endif
    </p>

    <p style="margin:0 0 12px;font-size:13px;color:#7b8794;">
        {{ __('mail.if_button_does_not_work_2') }}<br>
        <span style="word-break:break-all;">{{ $resetUrl }}</span>
    </p>

    <p style="margin:0;font-size:13px;color:#7b8794;">
        {{ __('mail.if_you_did_not_ask') }}
    </p>
@endcomponent
