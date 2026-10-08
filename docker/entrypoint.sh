#!/bin/sh
# CortenDesk container bootstrap: bring up the ID server and relay, render the
# nginx config, ensure APP_KEY and database exist, migrate, cache, then hand
# off to supervisord.
set -e
cd /app

_is_uint() { case "$1" in ''|*[!0-9]*) return 1 ;; *) return 0 ;; esac; }

# --- file descriptors --------------------------------------------------------
# Many Docker hosts start containers with a soft limit of 1024 open files and a
# much higher hard limit. 1024 is too few once a few hundred devices connect:
# nginx fails accept4() with EMFILE and hbbr runs out of sockets. Raise the soft
# limit toward the hard one here, before supervisord starts, so every process
# inherits it. Never above the hard limit (that needs CAP_SYS_RESOURCE) and
# never lower than what the container already has.
_fd_want=65536
_fd_hard="$(ulimit -Hn)"
if _is_uint "$_fd_hard" && [ "$_fd_hard" -lt "$_fd_want" ]; then
    _fd_want="$_fd_hard"
fi
_fd_soft="$(ulimit -Sn)"
if _is_uint "$_fd_soft" && [ "$_fd_soft" -lt "$_fd_want" ]; then
    ulimit -Sn "$_fd_want" 2>/dev/null || true
fi
FD_LIMIT="$(ulimit -Sn)"
if _is_uint "$FD_LIMIT" && [ "$FD_LIMIT" -lt 4096 ]; then
    echo "[cortendesk] WARNING: only $FD_LIMIT open files allowed (hard limit $_fd_hard)."
    echo "[cortendesk]          Larger fleets need more: docker run --ulimit nofile=65536:65536"
fi

# --- console port ------------------------------------------------------------
# nginx listens here inside the container. 9000 is php-fpm and 21115-21119
# belong to the ID server and relay, so those are refused. A bad value stops
# the container: falling back to 8080 would leave the console unreachable on
# the port the operator mapped, and the healthcheck would probe the wrong one.
CORTENDESK_HTTP_PORT="${CORTENDESK_HTTP_PORT:-8080}"
if ! _is_uint "$CORTENDESK_HTTP_PORT" || [ "$CORTENDESK_HTTP_PORT" -lt 1 ] \
    || [ "$CORTENDESK_HTTP_PORT" -gt 65535 ] || [ "$CORTENDESK_HTTP_PORT" -eq 9000 ] \
    || { [ "$CORTENDESK_HTTP_PORT" -ge 21115 ] && [ "$CORTENDESK_HTTP_PORT" -le 21119 ]; }; then
    echo "[cortendesk] CORTENDESK_HTTP_PORT='$CORTENDESK_HTTP_PORT' is not usable: pick 1-65535, not 9000 or 21115-21119" >&2
    exit 1
fi
export CORTENDESK_HTTP_PORT

# --- log level ---------------------------------------------------------------
# One knob for the console (LOG_LEVEL), hbbs/hbbr (RUST_LOG) and php-fpm's
# per-request access log. LOG_LEVEL or RUST_LOG set explicitly still wins.
# hbbs/hbbr only know off, error, warn, info, debug and trace, and an unknown
# word there silences them, so map onto those. Their debug is limited to the
# servers' own modules: a global debug adds HTTP client chatter every 5 seconds.
_fpm_access=/proc/self/fd/2
if [ -n "${CORTENDESK_LOG_LEVEL:-}" ]; then
    _ll="$(echo "$CORTENDESK_LOG_LEVEL" | tr 'A-Z' 'a-z')"
    case "$_ll" in
        debug)                    _rl=info,hbbs=debug,hbbr=debug,hbb_common=debug ;;
        info|notice)              _rl=info ;;
        warn|warning)             _ll=warning; _rl=warn ;;
        error|critical|alert|emergency) _rl=error ;;
        *)
            echo "[cortendesk] WARNING: CORTENDESK_LOG_LEVEL='$CORTENDESK_LOG_LEVEL' is not one of debug, info, notice, warning, error, critical; ignoring it"
            _ll="" ;;
    esac
    if [ -n "$_ll" ]; then
        export LOG_LEVEL="${LOG_LEVEL:-$_ll}"
        export RUST_LOG="${RUST_LOG:-$_rl}"
        # One line per PHP request is info-level output.
        case "$_rl" in warn|error) _fpm_access=/dev/null ;; esac
    fi
fi

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

    # The link between hbbs and the console (docs/server-link.md): hbbs pulls
    # the device policy with this secret and the console signs web client
    # tickets with it. Generated once into /data, like APP_KEY. hbbs reaches
    # the console over loopback through nginx.
    if [ -z "${CORTENDESK_SERVER_SECRET:-}" ]; then
        if [ ! -s /data/.server_secret ]; then
            php -r 'echo bin2hex(random_bytes(32));' > /data/.server_secret
            chmod 600 /data/.server_secret
            echo "[cortendesk] generated the server link secret (persisted in the /data volume)"
        fi
        CORTENDESK_SERVER_SECRET="$(cat /data/.server_secret)"
    fi
    export CORTENDESK_SERVER_SECRET
    export CORTENDESK_CONSOLE_URL="${CORTENDESK_CONSOLE_URL:-http://127.0.0.1:$CORTENDESK_HTTP_PORT}"

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
# nginx sends the files (X-Accel-Redirect); see the internal location in
# nginx.conf.template.
export CORTENDESK_DOWNLOADS_ACCEL=/_cortendesk_downloads

# Upload ceiling. One variable sizes every layer an installer upload passes
# through: the app's own limit, PHP and nginx. CORTENDESK_DOWNLOADS_MAX_KB is
# the older spelling and still honoured when the MB one is unset.
if [ -n "${CORTENDESK_DOWNLOADS_MAX_MB:-}" ]; then
    _max_mb="$CORTENDESK_DOWNLOADS_MAX_MB"
elif _is_uint "${CORTENDESK_DOWNLOADS_MAX_KB:-}"; then
    _max_mb=$(( (CORTENDESK_DOWNLOADS_MAX_KB + 1023) / 1024 ))
else
    _max_mb=512
fi
if ! _is_uint "$_max_mb" || [ "$_max_mb" -lt 1 ]; then
    echo "[cortendesk] WARNING: CORTENDESK_DOWNLOADS_MAX_MB='$_max_mb' is not a whole number of MB; using 512"
    _max_mb=512
fi
export CORTENDESK_DOWNLOADS_MAX_MB="$_max_mb"
# Headroom over the app limit: Livewire accepts 1 MB more than it so the
# operator sees CortenDesk's own message, plus the multipart framing.
export CORTENDESK_UPLOAD_BODY_MB=$((_max_mb + 16))
cat > /usr/local/etc/php/conf.d/zz-cortendesk-uploads.ini <<EOF
; Written by entrypoint.sh from CORTENDESK_DOWNLOADS_MAX_MB. Edits are lost.
upload_max_filesize = ${CORTENDESK_UPLOAD_BODY_MB}M
post_max_size = ${CORTENDESK_UPLOAD_BODY_MB}M
EOF

# --- php-fpm pool size ---------------------------------------------------------
# The stock pool is 5 workers. Every heartbeat, sysinfo upload and console page
# needs one, so a few hundred devices fill it, requests queue up in nginx and
# the console stops answering (issue #93). A worker is roughly 35-40 MB.
# Default: 24 workers, or fewer when the container has a memory limit, so that
# a full pool stays under about half of it. CORTENDESK_FPM_MAX_CHILDREN wins.
_mem_mb=""
for _f in /sys/fs/cgroup/memory.max /sys/fs/cgroup/memory/memory.limit_in_bytes; do
    if [ -r "$_f" ]; then
        _mem_mb="$(awk '$1 ~ /^[0-9]+$/ && $1 < 1099511627776 { printf "%d", $1 / 1048576 }' "$_f")"
        break
    fi
done
_fpm_default=24
if _is_uint "$_mem_mb" && [ "$_mem_mb" -gt 0 ]; then
    _fpm_default=$((_mem_mb / 2 / 40))
    [ "$_fpm_default" -gt 24 ] && _fpm_default=24
    [ "$_fpm_default" -lt 5 ] && _fpm_default=5
fi
_fpm_max="${CORTENDESK_FPM_MAX_CHILDREN:-$_fpm_default}"
if ! _is_uint "$_fpm_max" || [ "$_fpm_max" -lt 1 ]; then
    echo "[cortendesk] WARNING: CORTENDESK_FPM_MAX_CHILDREN='$_fpm_max' is not a whole number above 0; using $_fpm_default"
    _fpm_max="$_fpm_default"
fi
# Spare workers scale with the pool: 24 gives min 3, start 6, max 12.
_fpm_min_spare=$((_fpm_max / 8)); [ "$_fpm_min_spare" -lt 1 ] && _fpm_min_spare=1
_fpm_max_spare=$((_fpm_max / 2)); [ "$_fpm_max_spare" -lt "$_fpm_min_spare" ] && _fpm_max_spare="$_fpm_min_spare"
_fpm_start=$((_fpm_max / 4))
[ "$_fpm_start" -lt "$_fpm_min_spare" ] && _fpm_start="$_fpm_min_spare"
[ "$_fpm_start" -gt "$_fpm_max_spare" ] && _fpm_start="$_fpm_max_spare"
# The file sorts after www.conf and docker.conf, so these values win. The only
# later file, zz-docker.conf, sets daemonize and nothing else.
cat > /usr/local/etc/php-fpm.d/zz-cortendesk-fpm.conf <<EOF
; Written by entrypoint.sh from CORTENDESK_FPM_MAX_CHILDREN and
; CORTENDESK_LOG_LEVEL. Edits are lost.
[global]
; Let workers finish their request on a reload or stop instead of being killed.
process_control_timeout = 10s

[www]
; Only nginx in this container talks to php-fpm. The base image listens on
; every interface, which exposes FastCGI to the rest of the Docker network.
listen = 127.0.0.1:9000
; /dev/null when CORTENDESK_LOG_LEVEL is warning or quieter.
access.log = ${_fpm_access}
pm = dynamic
pm.max_children = ${_fpm_max}
pm.start_servers = ${_fpm_start}
pm.min_spare_servers = ${_fpm_min_spare}
pm.max_spare_servers = ${_fpm_max_spare}
; Recycle workers now and then so a slow leak cannot grow without bound.
pm.max_requests = 1000
EOF

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

# Connections per nginx worker. A proxied request holds two descriptors (client
# and php-fpm or the ws upstream), so each worker may open twice as many files,
# capped at what the container allows.
_ngx_conn="${CORTENDESK_NGINX_WORKER_CONNECTIONS:-4096}"
if ! _is_uint "$_ngx_conn" || [ "$_ngx_conn" -lt 64 ]; then
    echo "[cortendesk] WARNING: CORTENDESK_NGINX_WORKER_CONNECTIONS='$_ngx_conn' is not a whole number of at least 64; using 4096"
    _ngx_conn=4096
fi
_ngx_files=$((_ngx_conn * 2))
if _is_uint "$_fd_hard" && [ "$_ngx_files" -gt "$_fd_hard" ]; then
    _ngx_files="$_fd_hard"
    if [ "$_ngx_conn" -gt "$_ngx_files" ]; then
        echo "[cortendesk] WARNING: $_ngx_conn nginx connections need more open files than the hard limit ($_fd_hard); using $_ngx_files"
        _ngx_conn="$_ngx_files"
    fi
fi
export NGINX_WORKER_CONNECTIONS="$_ngx_conn" NGINX_WORKER_RLIMIT_NOFILE="$_ngx_files"

envsubst '${RUSTDESK_WS_HOST} ${NGINX_RESOLVER} ${CORTENDESK_UPLOAD_BODY_MB} ${CORTENDESK_DOWNLOADS_PATH} ${CORTENDESK_HTTP_PORT} ${NGINX_WORKER_CONNECTIONS} ${NGINX_WORKER_RLIMIT_NOFILE}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

php artisan config:cache --no-interaction -q
php artisan route:cache --no-interaction -q
php artisan view:cache --no-interaction -q

if [ "$EMBEDDED" = 1 ]; then
    echo "[cortendesk] ready — console on :$CORTENDESK_HTTP_PORT, ID server on :21116, relay on :21117"
    echo "[cortendesk] server ${CORTENDESK_SERVER_VERSION:-?}, key ${CORTENDESK_PUBLIC_KEY}"
else
    echo "[cortendesk] ready — listening on :$CORTENDESK_HTTP_PORT (ws bridge -> ${RUSTDESK_WS_HOST}:21118/21119)"
fi
echo "[cortendesk] php-fpm ${_fpm_max} workers, nginx ${NGINX_WORKER_CONNECTIONS} connections per worker, ${FD_LIMIT} open files"
exec supervisord -c /etc/supervisord.conf
