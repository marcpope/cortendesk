#!/bin/sh
# CortenDesk container bootstrap: bring up the ID server and relay, render the
# nginx config, ensure APP_KEY and database exist, migrate, cache, then hand
# off to supervisord.
set -e
cd /app

# --- the embedded ID server and relay ----------------------------------------
# hbbs and hbbr run in this container by default. Set
# CORTENDESK_EMBEDDED_SERVER=false to leave them out and point the console at
# servers you run yourself.
RD_DIR=/data/rustdesk

case "${CORTENDESK_EMBEDDED_SERVER:-true}" in
    0|false|FALSE|no|NO|off|OFF) EMBEDDED=0 ;;
    *)                           EMBEDDED=1 ;;
esac

# The public address clients use to reach this host. Everything below is
# derived from it, so a single APP_URL is enough for a working deployment.
_public_host() {
    _h="${APP_URL:-}"
    _h="${_h#*://}"      # scheme
    _h="${_h%%/*}"       # path
    case "$_h" in
        \[*\]*) echo "${_h%%\]*}]" ;;   # [2001:db8::1]:8080 -> [2001:db8::1]
        *)      echo "${_h%%:*}" ;;
    esac
}

if [ "$EMBEDDED" = 1 ]; then
    HOST="$(_public_host)"
    HOST="${HOST:-127.0.0.1}"

    mkdir -p "$RD_DIR"

    # Generate the key pair before anything reads it. hbbs would do this on its
    # own first start, but the console caches its config before supervisord runs
    # anything, so the key has to exist by now or the first boot ships an empty
    # one. Keys containing / or : are rejected: they end up in URLs, config
    # strings and command lines, and hbbs skips them for the same reason.
    if [ ! -f "$RD_DIR/id_ed25519" ]; then
        _n=0
        while [ "$_n" -lt 300 ]; do
            _pair="$(cortendesk-utils genkeypair)"
            _pub="$(echo "$_pair" | awk '/Public Key:/ {print $3}')"
            _sec="$(echo "$_pair" | awk '/Secret Key:/ {print $3}')"
            case "$_pub" in
                *[/:]*) _n=$((_n + 1)); continue ;;
            esac
            break
        done
        # No trailing newline: the key is compared byte for byte, and hbbs
        # writes these files the same way.
        printf '%s' "$_sec" > "$RD_DIR/id_ed25519"
        printf '%s' "$_pub" > "$RD_DIR/id_ed25519.pub"
        chmod 600 "$RD_DIR/id_ed25519"
        echo "[cortendesk] generated the server key pair in $RD_DIR"
    fi

    # Adopting a data directory from a separate hbbs/hbbr install: the files
    # arrive owned by root, and these processes do not run as root.
    chown -R www-data:www-data "$RD_DIR"

    # What the console tells clients, and what hbbs tells them about the relay.
    # An explicit setting always wins; these only fill in the blanks.
    export CORTENDESK_ID_SERVER="${CORTENDESK_ID_SERVER:-$HOST:21116}"
    export CORTENDESK_RELAY_SERVER="${CORTENDESK_RELAY_SERVER:-$HOST:21117}"
    export CORTENDESK_PUBLIC_KEY="${CORTENDESK_PUBLIC_KEY:-$(cat "$RD_DIR/id_ed25519.pub")}"
    export RUSTDESK_RELAY_ADVERTISED="$CORTENDESK_RELAY_SERVER"
    # The ws bridge is a loopback hop now, not a network one.
    export RUSTDESK_WS_HOST="${RUSTDESK_WS_HOST:-127.0.0.1}"

    cp /etc/cortendesk/rustdesk-server.conf /etc/supervisor.d/rustdesk-server.conf

    case "$HOST" in
        localhost|127.0.0.1|"")
            echo "[cortendesk] WARNING: APP_URL has no public hostname, so clients"
            echo "[cortendesk]          will be told the relay is at '$HOST:21117'"
            echo "[cortendesk]          and every session that needs it will hang."
            echo "[cortendesk]          Set APP_URL to the address clients reach."
            ;;
    esac
else
    rm -f /etc/supervisor.d/rustdesk-server.conf
    echo "[cortendesk] embedded ID server and relay are off (CORTENDESK_EMBEDDED_SERVER)"
fi

# --- uploaded client installers ----------------------------------------------
# System -> Client Downloads writes here. Default it into the /data volume:
# nothing is mounted at /app/storage, so a default under storage/ would silently
# lose every uploaded build the next time the container is recreated.
export CORTENDESK_DOWNLOADS_PATH="${CORTENDESK_DOWNLOADS_PATH:-/data/downloads}"
mkdir -p "$CORTENDESK_DOWNLOADS_PATH"
chown www-data:www-data "$CORTENDESK_DOWNLOADS_PATH"

# --- APP_KEY: use the env if provided, else generate once into /data --------
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -f /data/.app_key ]; then
        php artisan key:generate --show > /data/.app_key
        chown www-data:www-data /data/.app_key
        chmod 600 /data/.app_key
        echo "[cortendesk] generated APP_KEY (persisted in the /data volume)"
    fi
    APP_KEY="$(cat /data/.app_key)"
    export APP_KEY
fi

# --- database ----------------------------------------------------------------
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    [ -f "$DB_DATABASE" ] || { touch "$DB_DATABASE" && chown www-data:www-data "$DB_DATABASE"; }
fi

# Wait for an external database to accept connections (MySQL etc.).
tries=0
until php artisan migrate --force --no-interaction 2>/tmp/migrate.err; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "[cortendesk] database not reachable after 150s:" >&2
        cat /tmp/migrate.err >&2
        exit 1
    fi
    echo "[cortendesk] waiting for database... ($tries)"
    sleep 5
done

# First boot: create the admin account (no-op once any user exists).
php artisan db:seed --class=Database\\Seeders\\DockerSeeder --force --no-interaction

# --- nginx: point the ws bridge at the RustDesk server ------------------------
# RUSTDESK_WS_HOST > host part of CORTENDESK_ID_SERVER > localhost.
if [ -z "${RUSTDESK_WS_HOST:-}" ]; then
    _id_server="${CORTENDESK_ID_SERVER:-127.0.0.1}"
    case "$_id_server" in
        # [2001:db8::1]:21116 — strip the port, keep the brackets nginx needs.
        \[*\]*) RUSTDESK_WS_HOST="${_id_server%%\]*}]" ;;
        *)       RUSTDESK_WS_HOST="${_id_server%%:*}" ;;
    esac
fi
export RUSTDESK_WS_HOST

# Per-request DNS for the ws bridge: use the container's own resolver.
NGINX_RESOLVER="${NGINX_RESOLVER:-$(awk '/^nameserver/{print $2; exit}' /etc/resolv.conf)}"
NGINX_RESOLVER="${NGINX_RESOLVER:-127.0.0.11}"
# nginx wants an IPv6 resolver in square brackets and an IPv4 one without them.
# Some platforms hand the container an IPv6-only /etc/resolv.conf (Railway does),
# and passing that through verbatim aborts startup with
#   nginx: [emerg] invalid port in resolver "fd12::10"
case "$NGINX_RESOLVER" in
    \[*\]) ;;                                  # already bracketed
    *:*)   NGINX_RESOLVER="[$NGINX_RESOLVER]" ;; # bare IPv6 — only v6 has colons
esac
export NGINX_RESOLVER
envsubst '${RUSTDESK_WS_HOST} ${NGINX_RESOLVER}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

php artisan config:cache --no-interaction -q
php artisan route:cache --no-interaction -q
php artisan view:cache --no-interaction -q

if [ "$EMBEDDED" = 1 ]; then
    echo "[cortendesk] ready — console on :8080, ID server on :21116, relay on :21117"
    echo "[cortendesk] server ${CORTENDESK_SERVER_VERSION:-?}, key ${CORTENDESK_PUBLIC_KEY}"
else
    echo "[cortendesk] ready — listening on :8080 (ws bridge -> ${RUSTDESK_WS_HOST}:21118/21119)"
fi
exec supervisord -c /etc/supervisord.conf
