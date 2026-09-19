@php
    // Cache-bust built assets by mtime so a rebuild is always picked up (no hard-refresh needed).
    $rdVer = @filemtime(public_path('rdclient/app.js')) ?: time();
@endphp
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $peerId !== '' ? $peerId.' — ' : '' }}CortenDesk Web Client</title>
    <link rel="shortcut icon" href="{{ \App\Support\Asset::url('assets/images/favicon.ico') }}">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/css/icons.min.css') }}">
    <link rel="stylesheet" href="/rdclient/app.css?v={{ $rdVer }}">
    <style>
        html, body { height: 100%; margin: 0; overflow: hidden; background: #000; }
        #rd-root { height: 100vh; height: 100dvh; }
    </style>
</head>
<body>

    <div id="rd-root">
        <div id="rd-toolbar"></div>
        <div id="rd-viewport">
            <canvas id="rd-canvas"></canvas>
            <div id="rd-stats"></div>
            <div id="rd-overlay"></div>
        </div>
    </div>

    <script>
        window.__RD__ = {
            peerId: @json($peerId),
            serverKeyB64: @json($serverKeyB64),
            wsIdUrl: @json($wsIdUrl),
            wsRelayUrl: @json($wsRelayUrl),
            myId: @json($myId),
            myName: @json($myName),
            version: @json(config('cortendesk.api_version')),
            osLoginUrl: @json(route('webclient.os-login.show')),
            csrfToken: @json(csrf_token()),
            workerUrl: '/rdclient/session.worker.js?v={{ $rdVer }}'
        };
        // No ?id= given: app.js shows the connect overlay asking for peer id + password.
    </script>
    <script type="module" src="/rdclient/app.js?v={{ $rdVer }}" data-rd-worker="/rdclient/session.worker.js?v={{ $rdVer }}"></script>

</body>
</html>
