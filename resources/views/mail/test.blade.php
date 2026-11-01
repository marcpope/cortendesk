@component('mail.layout')
    <p style="margin:0 0 12px;font-size:17px;font-weight:600;">{{ __('mail.your_mail_settings_work') }}</p>
    <p style="margin:0 0 12px;">{{ __('mail.this_is_test_message_from', ['app' => config('app.name')]) }}</p>
    <p style="margin:0;color:#7b8794;font-size:13px;">{{ __('mail.nothing_else_to_do_you') }}</p>
@endcomponent
