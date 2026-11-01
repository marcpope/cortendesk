@component('mail.layout')
    <p style="margin:0 0 12px;font-size:17px;font-weight:600;">{{ __('mail.you_have_been_invited_to_2', ['app' => config('app.name')]) }}</p>

    <p style="margin:0 0 12px;">{{ __('mail.name_invited_you_to_remote', ['name' => $invitedBy]) }}
        <strong style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $invitation->username }}</strong>.
        {{ __('mail.choose_password_to_finish') }}</p>

    <p style="margin:20px 0;">
        <a href="{{ $acceptUrl }}"
           style="display:inline-block;background:#e2652e;color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:6px;font-weight:600;">
            {{ __('mail.accept_invitation') }}
        </a>
    </p>

    <p style="margin:0 0 12px;font-size:13px;color:#7b8794;">{{ __('mail.if_button_does_not_work') }}</p>
    <p style="margin:0 0 16px;font-size:12px;word-break:break-all;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#26303e;">
        {{ $acceptUrl }}
    </p>

    <p style="margin:0;font-size:13px;color:#7b8794;">
        {{ __('mail.link_works_once_and_expires', ['when' => $invitation->expires_at->diffForHumans(), 'date' => $invitation->expires_at->format('Y-m-d H:i')]) }}
    </p>
@endcomponent
