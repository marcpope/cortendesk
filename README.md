# CortenDesk

**A complete, self-hosted drop-in replacement for the open-source RustDesk server — ID server, relay and professional console in one image, with a fully native in-browser remote desktop client.**

<img width="100%" alt="CortenDesk - A Rust Desk Pro Console Alternative made entriely for the Open Source Rust Desk Server, API Relay and Client hbbs/hbbr" src="https://github.com/user-attachments/assets/7eb7ad86-48f5-42ea-b2d6-662a2a8a1dab" />

### CortenDesk is everything a self-hosted RustDesk deployment needs, in a single container: the ID/rendezvous server (`hbbs`), the relay (`hbbr`), and a clean, professional console — device fleet management, users and scoped access, address books, audit logs, and a web client that can view, control, and transfer files to your devices straight from the browser. No installer, no Electron, no paid tier. Stock RustDesk clients connect to it unchanged.

**It replaces the open-source server, and fixes what that server gets wrong.** The bundled `hbbs`/`hbbr` are [CortenDesk Server](https://github.com/marcpope/cortendesk-server), our AGPL fork of `rustdesk-server`. The headline fix: the open-source `hbbs` never completes the signalling key exchange that RustDesk clients 1.4.1 and newer start whenever they are signed in to a console, so **every connection from a signed-in client fails** with `Failed to secure tcp: deadline has elapsed` — which breaks the address book, the main reason to sign in at all. Upstream treats that as out of scope. We implemented the missing half.

Already running your own `hbbs`/`hbbr`? Set `CORTENDESK_EMBEDDED_SERVER=false` and CortenDesk is the console alone, exactly as before.

Built on Laravel + Livewire with precompiled assets: **there is no frontend build step**. Clone, configure, migrate, serve.

## Features

**Console**
- **Devices** — live fleet with presence, platform icons, aliases, device groups ("folders"), pre-registration, and a recycle bin. One-click connect via `rustdesk://` deep links or the built-in web client. Pick your own table columns (CPU, memory, IP, UUID and more — saved per user), export the current view to CSV, and multi-select rows to bulk-delete or add devices to an address book. Each device has a detail page with its connections, file transfers, notifications and address books.
- **Server access control** — with the embedded ID server (or CortenDesk Server 1.1.0+), limit the server to devices you approved, and mark devices incoming only so they can be controlled but cannot start sessions. The ID server enforces both. It also reports each device's LAN address seen during connection setup, shown as an optional LAN IP column. See [CortenDesk Server](https://github.com/marcpope/cortendesk-server#device-policy-from-a-cortendesk-console).
- **Users & access scoping** — a detail page per user shows their devices, address books, sign-ins, console actions and connections. Admins see everything; regular users see only their own devices plus device groups granted to them or their user groups. The RustDesk client API is scoped with the same rules.
- **Address books** — full support for the modern multi-address-book API *and* the legacy API: shared books, share rules (everyone / user / group), tags with colors.
- **Audit logs** — connections (with the note RustDesk clients can leave at the end of a session), file transfers, console logins, and security alarms (brute-force/blocked-access events); filterable, exportable to CSV, with configurable retention and automatic nightly pruning.
- **Single sign-on (OIDC)** — sign in with Keycloak, Authentik, Entra ID, Okta, Google Workspace or any OpenID Connect provider. Authorization-code flow with PKCE, verified ID tokens, just-in-time account creation with optional approval, an email-domain allowlist, and optional provider sign-out. Password sign-in can be switched off — and returns by itself if SSO is disabled or left incompletely configured. For a provider that is unreachable while still configured, `CORTENDESK_OIDC_DISABLED=true` forces it off and brings the password form back.
- **Device policies (strategies)** — push client settings to devices from the console: permissions, security and password rules, capture options. Assign to a device, a user or a device group, with the most specific assignment winning. Optionally enforced, so a local change is reverted on the next heartbeat.
- **Two-factor authentication** — TOTP with single-use recovery codes, optionally required for everyone or for administrators only, with an administrator reset and a break-glass command.
- **Delegated administration** — roles with a permission matrix over each console area, so you can grant someone the users screen without handing them the whole console.
- **Automation API** — scoped bearer tokens and a REST API for users, devices, groups, address books and audit logs, plus support for the RustDesk client's `--assign` flag for unattended deployment.
- **Email** — SMTP settings with a test send, user invitations by email, self-service password reset, and an optional emailed code when signing in from a new browser.
- **Dashboard** — live stat tiles, active sessions, 14-day connection charts, platform and version breakdowns.
- **Importer** — one artisan command migrates everything (users with passwords intact, devices, address books, audit history) from a `lejianwen/rustdesk-api` database.
- **Mobile-first** — every screen works on a phone; wide tables degrade to card lists. Dark and light themes.

**Client API**
- Implements the RustDesk client HTTP API: login/tokens, heartbeat and sysinfo presence, address books, group tab, audit ingestion. Point stock RustDesk clients at CortenDesk as their **API Server** — no client patches needed.

**Native web client**
- A from-scratch TypeScript implementation of the RustDesk wire protocol (rendezvous → relay → NaCl handshake → login), running entirely in the browser over WebSocket relays. Not a WASM port — readable, auditable source.
- Hardware-accelerated video via WebCodecs (VP8/VP9/H.264/H.265/AV1 as supported), remote audio, two-way voice calls, text chat, clipboard both ways, multi-monitor switching, Ctrl+Alt+Del, session stats.
- **File transfer** — an in-session dual-pane manager: browse the remote filesystem, send/receive files and folders with progress, resume-aware digests, conflict prompts, and drag-and-drop. Uses the File System Access API on Chromium; falls back to picker/Downloads elsewhere.
- Saved passwords (hashed, never plaintext) with auto-login per device.
- Best experienced in Chrome/Edge; the desktop stream requires WebCodecs. Voice calls need Chrome or Edge on HTTPS (or `http://localhost`) plus microphone permission.
- **HTTPS recommended, not required.** This is about video quality, not whether it works: over HTTPS the client uses WebCodecs for hardware-accelerated VP8/VP9/H.264/H.265/AV1, and over plain `http://` it falls back to H.264 through Media Source Extensions, which is not restricted to secure contexts. The fallback is automatic and needs no configuration; it is limited to H.264 and reports no per-frame statistics. `http://localhost` counts as secure. Signalling follows `APP_URL` either way — set it to the address browsers actually use, or override `CORTENDESK_WS_ID_URL` / `CORTENDESK_WS_RELAY_URL` when your WebSocket endpoints live somewhere else.

## Requirements

- PHP **8.4+** with Composer
- MySQL/MariaDB (SQLite works for evaluation)
- nginx + php-fpm (or any Laravel-capable web server)
- A RustDesk server (`hbbs`/`hbbr`) — **included in the Docker image**; only needed separately for a manual install
- For the web client: a proxy bridging WebSockets to hbbs/hbbr ports 21118/21119 — `wss://` over HTTPS, or `ws://` if you serve the console over plain HTTP — sample config below. The Docker image already does this internally.

## Quick start with Docker

One image, one command. It brings up the console, the ID server and the relay,
generates the server key pair on first boot, and wires all three together:

```bash
docker run -d --name cortendesk \
  -e APP_URL=https://rd.example.com \
  -p 8080:8080 -p 21115-21119:21115-21119 -p 21116:21116/udp \
  -v cortendesk-data:/data \
  ghcr.io/marcpope/cortendesk:1.10.1
```

`APP_URL` is the only setting that matters: it is the address your clients and
browsers reach, and the ID server, the relay address handed to clients, and the
web client's WebSocket URLs are all derived from it. Leave it as `localhost` and
sessions that need the relay will hang.

First boot creates **admin / changeme** (override with `CORTENDESK_ADMIN_USER`
/ `CORTENDESK_ADMIN_PASSWORD`) and uses SQLite in the `/data` volume — see
`docker-compose.yml` for a MySQL setup. Read the generated public key off the
Settings screen, or from `/data/rustdesk/id_ed25519.pub`; it is what you put in
each client's **Key** field.

Ports: 8080 console and client API; 21115 NAT test; 21116 signalling, TCP **and
UDP**; 21117 relay; 21118/21119 the WebSocket pair (only needed if something
outside the container talks to them directly — nginx here already bridges
`/ws/id` and `/ws/relay` over loopback). Put a TLS reverse proxy in front of
8080 and the web client works with no further configuration.

**Bringing your own server.** Point CortenDesk at `hbbs`/`hbbr` you already run:

```bash
-e CORTENDESK_EMBEDDED_SERVER=false \
-e CORTENDESK_ID_SERVER=hbbs.example.com:21116 \
-e CORTENDESK_RELAY_SERVER=hbbs.example.com:21117 \
-e CORTENDESK_PUBLIC_KEY="<contents of id_ed25519.pub>"
```

To enforce approved-only access and incoming-only devices there, run
CortenDesk Server 1.1.0 or later and give both sides the same secret:
`CORTENDESK_SERVER_SECRET` on the console, and `CORTENDESK_SERVER_SECRET` plus
`CORTENDESK_CONSOLE_URL` on `hbbs`. The embedded server needs none of this.
See [CortenDesk Server](https://github.com/marcpope/cortendesk-server#device-policy-from-a-cortendesk-console).

**Client installer uploads.** System → Client Downloads accepts installers up
to 512 MB. Change that with `-e CORTENDESK_DOWNLOADS_MAX_MB=1024`; the container
applies it to nginx and PHP at boot, so nothing else needs editing. A reverse
proxy in front of the container has its own body limit (nginx defaults to 1 MB)
and must allow the same size.

**Coming from separate hbbs/hbbr containers.** Stop them, then mount their data
directory at `/data/rustdesk`. The key pair and peer database are adopted as
they are, so every device keeps its ID and needs no reconfiguring:

```bash
-v /path/to/your/rustdesk/data:/data/rustdesk
```

## Manual installation

There is no installer — setup is a standard Laravel deployment:

```bash
git clone https://github.com/marcpope/cortendesk.git
cd cortendesk
composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
```

Edit `.env` — the CortenDesk-specific settings:

```ini
APP_NAME=CortenDesk
APP_URL=https://console.example.com

DB_CONNECTION=mysql
DB_DATABASE=cortendesk
DB_USERNAME=cortendesk
DB_PASSWORD=********

# Your RustDesk server
CORTENDESK_ID_SERVER=hbbs.example.com:21116
CORTENDESK_RELAY_SERVER=hbbs.example.com:21117
CORTENDESK_PUBLIC_KEY=<contents of your id_ed25519.pub>
# Shared with hbbs (CortenDesk Server 1.1.0+) for device access control,
# see the CortenDesk Server README. Any long random string.
CORTENDESK_SERVER_SECRET=

# Native web client (wss endpoints your proxy exposes, see below)
CORTENDESK_NATIVE_WEBCLIENT=true
CORTENDESK_WS_ID_URL=wss://console.example.com/ws/id
CORTENDESK_WS_RELAY_URL=wss://console.example.com/ws/relay
```

Then migrate and cache:

```bash
php artisan migrate --seed
php artisan config:cache route:cache view:cache
```

Serve `public/` with nginx + php-fpm as usual for Laravel. Log in as **admin / changeme** and change the password immediately.

Client Downloads accepts installers up to `CORTENDESK_DOWNLOADS_MAX_MB` (default
512). nginx and PHP reject anything larger than their own limits before the
console sees it, so raise them to at least that size plus a few MB:

```nginx
client_max_body_size 528m;
fastcgi_read_timeout 300s;   # in the PHP location: copying and hashing a big file takes a while
```

```ini
; php.ini, or a file in PHP's conf.d; restart php-fpm afterwards
upload_max_filesize = 528M
post_max_size = 528M
```

Add the Laravel scheduler to cron (log retention and other maintenance run through it; the Docker image does this automatically):

```
* * * * * cd /path/to/cortendesk && php artisan schedule:run >> /dev/null 2>&1
```

### Behind a reverse proxy (TLS termination)

CortenDesk honors `X-Forwarded-*` headers, so it works out of the box behind a
TLS-terminating proxy (Traefik, Caddy, nginx-proxy-manager, Cloudflare, …) that
forwards to the container/app over plain HTTP. Make sure your proxy passes
`X-Forwarded-Proto` (all of the above do by default) and set `APP_URL` to your
public https URL. The session cookie carries the Secure flag on every HTTPS
request, so the same console still accepts sign-in over plain HTTP on the LAN.
Set `SESSION_SECURE_COOKIE=true` only to refuse HTTP sign-in outright. No
mixed-content issues — assets are generated with the correct scheme from the
forwarded headers.

Forwarded headers are trusted only from private/loopback addresses (Docker
networks, a same-host proxy) so that clients reaching the app directly cannot
forge their IP in the audit logs. If your proxy connects from a public
address, list it explicitly: `TRUSTED_PROXIES=203.0.113.7` (comma-separated,
CIDRs allowed).

Getting this wrong is worth more than a wrong column in a log: every request
then appears to come from the proxy, so devices all record the same
`last_online_ip` **and** the per-address sign-in limiter treats every user as
one address, which can lock real users out.

### WebSocket bridge for the web client

Browsers can't open raw TCP to hbbs/hbbr, so the web client speaks WebSocket.

**Running the Docker image?** You do not need the block below. The container
already bridges `/ws/id` and `/ws/relay` to hbbs/hbbr itself — point your proxy
at the container on 8080 for *all* paths and make sure it forwards WebSocket
upgrade headers. The snippet below is for a **manual/VM install**, where hbbs
and hbbr are reachable on the host. Full examples for Caddy, Traefik and nginx:
[Reverse proxy and TLS](https://github.com/marcpope/cortendesk/wiki/Reverse-Proxy-and-TLS).

For a manual install, add to your TLS server block (adjust the upstream host if hbbs runs elsewhere):

```nginx
location = /ws/id {
    proxy_pass http://127.0.0.1:21118/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 3600s;
}
location = /ws/relay {
    proxy_pass http://127.0.0.1:21119/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 3600s;
}
```

### Pointing RustDesk clients at CortenDesk

In each RustDesk client (or via a mass-deployed config): **Settings → Network** — set ID Server, Relay Server, Key, and **API Server** = your console URL. Devices then appear in the console within a heartbeat (~15 s). The Settings screen shows copy-paste values for all four fields.

### Migrating from lejianwen/rustdesk-api

```bash
php artisan cortendesk:import-lejianwen /path/to/rustdeskapi.db --dry-run   # preview
php artisan cortendesk:import-lejianwen /path/to/rustdeskapi.db             # import
```

Users (original bcrypt passwords), devices (deduplicated), address books, share rules, and audit history come across. Go-encrypted address-book entry passwords cannot be decrypted and must be re-saved by users.

### Rebuilding the web client (optional)

The browser client ships prebuilt in `public/rdclient/`. To hack on it:

```bash
cd webclient
npm install
npm run build        # or: npm run typecheck
```

## License

CortenDesk is licensed under the **AGPL-3.0-only** (see `LICENSE`).

The bundled admin theme (files under `public/assets/`) is a commercial product licensed separately and is **not** covered by the AGPL — see `NOTICE`. The vendored RustDesk protocol definitions (`webclient/protos/`) are AGPL, consistent with this repository.

CortenDesk is an independent project and is not affiliated with or endorsed by RustDesk / Purslane Ltd.
