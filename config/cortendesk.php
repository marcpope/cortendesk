<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reported API version
    |--------------------------------------------------------------------------
    | Returned by GET /api/version. Clients may use this for capability hints.
    */
    // Single source of truth is the VERSION file at the repo root (also used
    // by release tags and the Docker image); env can still override.
    'api_version' => env('CORTENDESK_API_VERSION', trim((string) @file_get_contents(base_path('VERSION'))) ?: '0.0.0'),

    /*
    |--------------------------------------------------------------------------
    | RustDesk infrastructure this console fronts
    |--------------------------------------------------------------------------
    | The hbbs (ID/rendezvous) and hbbr (relay) servers, and the server's
    | public key (contents of id_ed25519.pub). Used for web-client bootstrap
    | and shown on the Settings screen.
    */
    'id_server' => env('CORTENDESK_ID_SERVER', ''),
    'relay_server' => env('CORTENDESK_RELAY_SERVER', ''),
    // Trimmed: id_ed25519.pub is written without a trailing newline, so a key
    // that arrives with surrounding whitespace picked it up in transit (an
    // editor, a copy-paste, a heredoc in a compose file). The comparison is
    // exact, and the resulting failure is indistinguishable from a genuinely
    // wrong key — clients simply refuse to connect.
    'public_key' => trim((string) env('CORTENDESK_PUBLIC_KEY', '')),

    /*
    |--------------------------------------------------------------------------
    | Web client
    |--------------------------------------------------------------------------
    | Absolute URL of the static V1 web client (served by nginx, not this
    | app). Must be plain http — browsers block ws:// connections to
    | hbbs/hbbr from an https page. Empty hides the sidebar link.
    */
    'webclient_url' => env('CORTENDESK_WEBCLIENT_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Native web client (V2)
    |--------------------------------------------------------------------------
    | The in-browser client served by this app at /webclient (assets in
    | public/rdclient). WebSocket endpoints for hbbs/hbbr behind the nginx
    | TLS proxy; when empty they are derived from id_server as
    | wss://<host>/ws/id and wss://<host>/ws/relay. The flag gates the
    | "Connect" links in the device list.
    */
    'native_webclient' => env('CORTENDESK_NATIVE_WEBCLIENT', true),
    'ws_id_url' => env('CORTENDESK_WS_ID_URL', ''),
    'ws_relay_url' => env('CORTENDESK_WS_RELAY_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Presence
    |--------------------------------------------------------------------------
    | Seconds without a heartbeat before a device counts as offline.
    | Stock clients heartbeat roughly every 15 seconds.
    */
    'online_window' => env('CORTENDESK_ONLINE_WINDOW', 60),

    /*
    |--------------------------------------------------------------------------
    | Log retention
    |--------------------------------------------------------------------------
    | Days to keep audit/log rows (connections, file transfers, logins,
    | alarms). 0 = keep forever. Overridable per-install in Settings; pruned
    | nightly by cortendesk:prune-logs.
    */
    'log_retention_days' => env('CORTENDESK_LOG_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Build Installers
    |--------------------------------------------------------------------------
    | URL the sidebar "Build Installers" entry opens (an rdgen instance).
    | Overridable per-install in Settings; empty hides the menu entry.
    */
    'rdgen_url' => env('CORTENDESK_RDGEN_URL', 'https://rdgen.crayoneater.org'),

    /*
    |--------------------------------------------------------------------------
    | Client Downloads
    |--------------------------------------------------------------------------
    | Installers uploaded under System -> Client Downloads and offered on the
    | public /downloads page.
    |
    | downloads_max_kb must stay BELOW PHP's post_max_size (docker/php.ini,
    | 32M) and nginx's client_max_body_size (docker/nginx.conf.template, 32m).
    | Past those the request dies before Laravel sees it and the operator gets a
    | broken page instead of a validation error, so the three are raised
    | together or not at all.
    |
    | downloads_on_login controls only the icon row under the sign-in form; the
    | /downloads page itself is always reachable (unpublish the builds to empty
    | it).
    */
    'downloads_max_kb' => env('CORTENDESK_DOWNLOADS_MAX_KB', 30720),
    'downloads_on_login' => filter_var(env('CORTENDESK_DOWNLOADS_ON_LOGIN', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | OIDC break-glass switch
    |--------------------------------------------------------------------------
    | Set CORTENDESK_OIDC_DISABLED=true to turn single sign-on off from the
    | shell, without database access. Everything else about SSO is configured
    | in Settings → SSO; this exists purely so a misconfigured provider can
    | never permanently lock an operator out of their own console. Turning SSO
    | off also re-enables password sign-in, whatever the stored settings say.
    */
    'oidc_disabled' => filter_var(env('CORTENDESK_OIDC_DISABLED', false), FILTER_VALIDATE_BOOL),
];
