@php
    $rdVer = @filemtime(public_path('rdclient/app.js')) ?: time();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="dark" data-rd-theme-pref="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('shares.page_title') }} — CortenDesk</title>
    <link rel="shortcut icon" href="{{ \App\Support\Asset::url('assets/images/cortendesk-sm.svg') }}">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/vendor/remixicon/remixicon.css') }}">
    <link rel="stylesheet" href="/rdclient/app.css?v={{ $rdVer }}">
    <style>
        html, body { height: 100%; margin: 0; overflow: hidden; background: #000; }
        #rd-root { height: 100vh; height: 100dvh; }
        #cd-share-error { display: none; position: fixed; inset: 0; align-items: center; justify-content: center;
            color: #e8e8e8; font: 16px/1.5 system-ui, sans-serif; text-align: center; padding: 24px; }
        #cd-share-error.on { display: flex; }
        #cd-share-error img { display: block; margin: 0 auto 16px; }
    </style>
</head>
<body>
    <div id="cd-share-error" role="alert">
        <div>
            <img src="/rdclient/logo.png" alt="" width="48" height="48">
            <p id="cd-share-error-text">{{ __('shares.link_invalid') }}</p>
        </div>
    </div>
    <div id="rd-root">
        <div id="rd-toolbar"></div>
        <div id="rd-viewport">
            <canvas id="rd-canvas"></canvas>
            <div id="rd-stats"></div>
            <div id="rd-overlay"></div>
        </div>
    </div>

    <script>
        (function () {
            var token = (location.hash || '').replace(/^#/, '');
            var csrf = document.querySelector('meta[name="csrf-token"]').content;
            var fail = function (message) {
                if (message) document.getElementById('cd-share-error-text').textContent = message;
                document.getElementById('rd-root').style.display = 'none';
                document.getElementById('cd-share-error').classList.add('on');
            };
            if (!/^[a-f0-9]{64}$/.test(token)) { fail(); return; }
            fetch(@json(route('share.start', [], false)), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ token: token })
            }).then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (body) {
                    return r.ok ? body : Promise.reject(body.error || '');
                });
            }).then(function (info) {
                var c = info.client;
                window.__RD__ = {
                    peerId: c.peerId,
                    serverKeyB64: c.serverKeyB64,
                    wsIdUrl: c.wsIdUrl,
                    wsRelayUrl: c.wsRelayUrl,
                    myId: c.myId,
                    myName: c.myName,
                    signalToken: c.signalToken || '',
                    webrtc: c.webrtc || null,
                    version: @json(config('cortendesk.api_version')),
                    messages: @json($messages),
                    workerUrl: '/rdclient/session.worker.js?v={{ $rdVer }}',
                    share: {
                        token: token,
                        session: info.session,
                        label: info.label,
                        deviceName: info.device_name,
                        expiresAt: info.expires_at,
                        pingSeconds: info.ping_seconds,
                        pingUrl: @json(route('share.ping', [], false)),
                        endUrl: @json(route('share.end', [], false)),
                        csrfToken: csrf
                    }
                };
                document.title = (info.device_name || info.label) + ' — CortenDesk';
                return import('/rdclient/app.js?v={{ $rdVer }}');
            }).catch(function (message) { fail(typeof message === 'string' ? message : ''); });
        })();
    </script>
</body>
</html>
