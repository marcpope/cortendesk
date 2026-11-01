{{-- Plain HTML with inline styles: mail clients strip <link> and most <style>
     blocks, and there is no build step to inline anything for us. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#26303e;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="max-width:560px;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e3e6ea;">
                <tr>
                    <td style="background:#141c2b;border-bottom:3px solid #e2652e;padding:18px 24px;">
                        <span style="color:#ffffff;font-size:18px;font-weight:600;letter-spacing:.2px;">{{ config('app.name') }}</span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px;font-size:15px;line-height:1.55;">
                        {{ $slot }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:14px 24px;background:#fafbfc;border-top:1px solid #e3e6ea;font-size:12px;color:#7b8794;">
                        {{ __('mail.sent_by_app_at_url', ['app' => config('app.name'), 'url' => rtrim((string) config('app.url'), '/')]) }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
