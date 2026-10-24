#!/bin/sh
# Idempotent first-boot bootstrap for the Shopclass production container.
#
# Waits for the database, then self-provisions: installs on first boot from the
# environment, or applies pending migrations on a restart / after pulling a newer
# image. Both steps are no-ops when nothing is needed, so this is safe to run on
# every container start. On success it execs the CMD (supervisord: php-fpm+nginx).
set -eu

CLI="/application/oc-cli.php"

# Restore the real client IP from a trusted proxy so REMOTE_ADDR is the visitor,
# not the proxy — login throttling and abuse keying depend on it. Off unless
# OSC_REAL_IP_HEADER is set (e.g. "CF-Connecting-IP" behind a Cloudflare tunnel,
# or "X-Forwarded-For" behind a load balancer). OSC_REAL_IP_TRUSTED is the
# comma-separated CIDR allowlist of proxies to trust; the default trusts any peer,
# which is correct only when the sole ingress is that proxy (e.g. a tunnel with no
# published port). Narrow it if the container is directly reachable.
write_real_ip_conf() {
    conf=/etc/nginx/real_ip.conf
    header="${OSC_REAL_IP_HEADER:-}"
    if [ -z "$header" ]; then
        : > "$conf"
        return 0
    fi
    if [ -z "${OSC_REAL_IP_TRUSTED:-}" ]; then
        echo "entrypoint: warning: OSC_REAL_IP_HEADER is set without OSC_REAL_IP_TRUSTED, so any client can fake its address. Set it to your proxy's address range, e.g. 172.16.0.0/12." >&2
    fi
    {
        printf '%s\n' "${OSC_REAL_IP_TRUSTED:-0.0.0.0/0,::/0}" | tr ',' '\n' | while IFS= read -r cidr; do
            cidr=$(printf '%s' "$cidr" | tr -d ' ')
            [ -n "$cidr" ] && printf 'set_real_ip_from %s;\n' "$cidr"
        done
        printf 'real_ip_header %s;\n' "$header"
        printf 'real_ip_recursive off;\n'
    } > "$conf"
    echo "entrypoint: real-IP restoration on ($header)."
}
write_real_ip_conf

# The public-page micro-cache and the request rate limit. Both are off unless asked
# for, so an existing deployment upgrading to this image behaves exactly as before.
#
#   OSC_MICROCACHE       set to 1/on/true to cache public pages in nginx.
#   OSC_RATE_LIMIT       requests per second per client IP, e.g. "10r/s". Unset = off.
#   OSC_RATE_LIMIT_BURST burst allowance, default 20.
#
# The cache stores nothing the application has not already marked cacheable: no
# fastcgi_ignore_headers is set, so a response carrying `private, no-store` — which
# is what a session-starting page emits — is passed through and never stored. What
# nginx decides on its own is only whether a request may be SERVED from cache, and
# that is keyed on core's own personalization cookies. Third-party analytics and ads
# cookies are ignored on purpose: matching any cookie at all would make every real
# visitor a miss, which is how a micro-cache ends up doing nothing. Keep the four
# names in step with .docker/nginx/microcache.conf, which documents the contract.
write_microcache_conf() {
    http_conf=/etc/nginx/microcache_http.conf
    php_conf=/etc/nginx/microcache_php.conf
    server_conf=/etc/nginx/microcache_server.conf
    : > "$http_conf"
    : > "$php_conf"
    : > "$server_conf"

    case "${OSC_MICROCACHE:-}" in
        1|on|true|yes|On|TRUE|YES)
            mkdir -p /var/cache/nginx/microcache
            chown -R nginx:nginx /var/cache/nginx 2>/dev/null || true
            cat >> "$http_conf" <<'CONF'
fastcgi_cache_path /var/cache/nginx/microcache levels=1:2 keys_zone=MICROCACHE:10m
                   max_size=500m inactive=1d use_temp_path=off;
map $http_cookie $mc_private {
    default 0;
    "~(^|;\s*)(oc_cache_bypass|oc_userLocale|oc_theme|osclass|PHPSESSID)=" 1;
}
map "$mc_private:$arg_page" $mc_inm {
    default "";
    "1:ajax" $http_if_none_match;
}
CONF
            cat >> "$php_conf" <<'CONF'
fastcgi_cache            MICROCACHE;
fastcgi_cache_key        "$request_method$host$request_uri";
fastcgi_cache_bypass     $mc_private;
fastcgi_no_cache         $mc_private;
fastcgi_cache_lock       on;
fastcgi_cache_use_stale  updating error timeout http_500 http_503;
fastcgi_cache_background_update on;
fastcgi_param            HTTP_IF_NONE_MATCH $mc_inm if_not_empty;
add_header X-Cache $upstream_cache_status always;
CONF
            # A PURGE here clears the whole cache; core sends one after a site-wide
            # change (theme, settings, plugins). Its own server on the loopback only, so
            # nothing outside the container can reach it, whatever the real-IP settings.
            # set_real_ip_from is replaced by an address no client has, so a forwarded
            # header cannot rewrite the address the allow list checks either.
            cat >> "$http_conf" <<'CONF'
server {
    listen 127.0.0.1:8089;
    listen [::1]:8089;
    set_real_ip_from 0.0.0.0/32;
    access_log off;
    location / {
        if ($request_method != PURGE) {
            return 405;
        }
        fastcgi_cache MICROCACHE;
        fastcgi_cache_key "$request_method$host$request_uri";
        fastcgi_cache_purge PURGE purge_all from 127.0.0.1 ::1;
    }
}
CONF
            page_cache_purge_url=http://127.0.0.1:8089/
            # Where an entry can be removed before its window is up. Purging is what
            # makes a window longer than thirty seconds defensible, so the location
            # is written whenever the cache is: the nginx-cache plugin finds it
            # already there, and without the plugin it is an endpoint nothing calls.
            #
            # $1 is the path being purged and $is_args$args its query, so the key
            # built here is the one the block above filed the page under. They must
            # stay identical: a purge that computes a different key answers 412 and
            # deletes nothing, which is also what a page that was never cached
            # answers, so the mistake reports nothing.
            #
            # No credentials, so the allow list is the whole of the access control.
            # php-fpm runs in this container and reaches nginx over the loopback.
            # With OSC_REAL_IP_HEADER set, a client in OSC_REAL_IP_TRUSTED can name
            # itself 127.0.0.1 in that header, so keep that list to the real proxy.
            cat >> "$server_conf" <<'CONF'
location ~ ^/purge(/.*)$ {
    allow 127.0.0.1;
    allow ::1;
    deny all;
    fastcgi_cache_purge MICROCACHE "$request_method$host$1$is_args$args";
}
CONF
            echo "entrypoint: public-page micro-cache on, purge location at /purge."
            ;;
    esac

    rate="${OSC_RATE_LIMIT:-}"
    if [ -n "$rate" ]; then
        burst="${OSC_RATE_LIMIT_BURST:-20}"
        # $binary_remote_addr is the real client only because real_ip.conf was
        # written first; behind a proxy without it every visitor shares one key and
        # the whole site throttles as though it were a single caller.
        printf 'limit_req_zone $binary_remote_addr zone=shopclass:10m rate=%s;\n' "$rate" >> "$http_conf"
        # nodelay: let a burst through immediately rather than queueing it. Queued
        # requests hold php-fpm workers, which is the 502 this is meant to prevent.
        printf 'limit_req zone=shopclass burst=%s nodelay;\n' "$burst" >> "$php_conf"
        echo "entrypoint: rate limit on ($rate, burst $burst)."
    fi
}
write_microcache_conf

# Live messages through nchan, on with OSC_LIVE=nchan (the image default); any other value
# leaves both files empty and pages poll. $1 is the nginx directory (tests/docker-live-conf.sh).
write_live_conf() {
    live_dir="${1:-/etc/nginx}"
    live_http="$live_dir/live_http.conf"
    live_server="$live_dir/live_server.conf"
    : > "$live_http"
    : > "$live_server"
    live_publish_url=
    if [ "${OSC_LIVE:-}" != "nchan" ]; then
        echo "entrypoint: live messages off."
        return 0
    fi

    live_via=
    echo 'nchan_shared_memory_size 32m;' >> "$live_http"
    if [ "${OSC_CACHE:-}" = "redis" ] && [ -n "${OSC_CACHE_HOST:-}" ]; then
        r_host="$OSC_CACHE_HOST"
        r_port="${OSC_CACHE_PORT:-6379}"
        r_db="${OSC_CACHE_DB:-0}"
        r_user="${OSC_CACHE_USERNAME:-}"
        r_pass="${OSC_CACHE_PASSWORD:-}"
        # Values go into nginx inside double quotes, so a quote, backslash or line break is refused.
        if printf '%s' "$r_host" | grep -Eq '^[A-Za-z0-9._-]+$' \
            && printf '%s' "$r_port" | grep -Eq '^[0-9]+$' \
            && printf '%s' "$r_db" | grep -Eq '^[0-9]+$' \
            && ! printf '%s' "$r_user$r_pass" | grep -q '["\\]' \
            && [ "$(printf '%s' "$r_user$r_pass" | wc -l)" -eq 0 ]; then
            {
                echo 'upstream shopclass_live {'
                printf '    nchan_redis_server "redis://%s:%s/%s";\n' "$r_host" "$r_port" "$r_db"
                [ -z "$r_user" ] || printf '    nchan_redis_username "%s";\n' "$r_user"
                [ -z "$r_pass" ] || printf '    nchan_redis_password "%s";\n' "$r_pass"
                echo '}'
                # Sites sharing one Valkey must not share channels, and pub/sub ignores the db number.
                printf 'nchan_redis_namespace shopclass:live:%s;\n' "$(printf '%s' "${WEB_PATH:-}" | md5sum | cut -c1-12)"
            } >> "$live_http"
            chmod 600 "$live_http"
            live_via=", Valkey"
        else
            echo "entrypoint: warning: an OSC_CACHE_* value has a character nchan cannot take; live messages stay in this container only." >&2
        fi
    fi
    # nchan_redis_pass is not inherited, so each location names the upstream itself.
    live_pass() {
        [ -z "$live_via" ] || printf '%s    nchan_redis_pass shopclass_live;\n' "$1"
    }
    {
        cat <<'CONF'
server {
    listen 127.0.0.1:8090;
    listen [::1]:8090;
    set_real_ip_from 0.0.0.0/32;
    access_log off;
    location ~ ^/live/pub/(user-\d+)$ {
        nchan_publisher http;
        nchan_channel_id $1;
        nchan_message_buffer_length 10;
        nchan_message_timeout 60s;
CONF
        live_pass '    '
        printf '    }\n    location / {\n        return 404;\n    }\n}\n'
        # Runs messages_live_auth for /live/auth below: nchan's authorise call crashes the
        # worker when it goes straight to fastcgi_pass. Params are listed by hand, as
        # "include fastcgi_params" plus an override sends a name twice.
        cat <<'CONF'
server {
    listen 127.0.0.1:8091;
    set_real_ip_from 0.0.0.0/32;
    access_log off;
    root /application;
    location = /live/auth {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_pass_request_body off;
        fastcgi_param GATEWAY_INTERFACE CGI/1.1;
        fastcgi_param SERVER_SOFTWARE nginx;
        fastcgi_param SERVER_PROTOCOL $server_protocol;
        fastcgi_param SERVER_NAME $host;
        fastcgi_param SERVER_PORT $http_x_osc_server_port;
        fastcgi_param DOCUMENT_ROOT $document_root;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param REQUEST_URI /index.php?page=ajax&action=messages_live_auth;
        fastcgi_param QUERY_STRING page=ajax&action=messages_live_auth;
        fastcgi_param REQUEST_METHOD GET;
        fastcgi_param CONTENT_TYPE "";
        fastcgi_param CONTENT_LENGTH "";
        fastcgi_param REDIRECT_STATUS 200;
        fastcgi_param REMOTE_ADDR $http_x_osc_remote_addr;
        fastcgi_param HTTPS $http_x_osc_https;
        fastcgi_param HTTP_X_OSC_REMOTE_ADDR "" if_not_empty;
        fastcgi_param HTTP_X_OSC_HTTPS "" if_not_empty;
        fastcgi_param HTTP_X_OSC_SERVER_PORT "" if_not_empty;
    }
    # Only the authorise call; no static files from the app tree.
    location / {
        return 404;
    }
}
CONF
    } >> "$live_http"
    # The channel comes from the URL; proxy_set_header replaces any X-Channel-Id or X-Osc-*
    # header the client sent. The authorise call has no client connection, hence the set lines.
    {
        cat <<'CONF'
location ~ ^/live/sub/(?<osc_live_channel>user-\d+)$ {
    nchan_subscriber eventsource;
    nchan_channel_id $osc_live_channel;
    nchan_authorize_request /live/auth;
    nchan_subscriber_first_message newest;
    # Same origin only: no cross-origin reads from a sibling subdomain.
    nchan_access_control_allow_origin "http://$http_host https://$http_host";
    gzip off;
    set $osc_live_addr $remote_addr;
    set $osc_live_https $fcgi_https;
    set $osc_live_port $server_port;
CONF
        live_pass ''
        echo '}'
        cat <<'CONF'
location = /live/auth {
    internal;
    proxy_pass http://127.0.0.1:8091;
    proxy_pass_request_body off;
    proxy_set_header Content-Length "";
    proxy_set_header Host $http_host;
    proxy_set_header X-Channel-Id $osc_live_channel;
    proxy_set_header X-Osc-Remote-Addr $osc_live_addr;
    proxy_set_header X-Osc-Https $osc_live_https;
    proxy_set_header X-Osc-Server-Port $osc_live_port;
}
CONF
    } >> "$live_server"
    live_publish_url=http://127.0.0.1:8090/live/pub/
    echo "entrypoint: live messages on (nchan$live_via)."
}
write_live_conf

# PHP worker pool: php-fpm's own default of 5 workers leaves a multi-core host idle.
# OSC_FPM_MAX_CHILDREN sets it; unset, it is three per CPU, at least 5, at most memory / 64 MB.
write_fpm_pool_conf() {
    conf=/usr/local/etc/php-fpm.d/zz-osc-pool.conf
    max="${OSC_FPM_MAX_CHILDREN:-}"
    if [ -z "$max" ]; then
        cpus=$(nproc 2>/dev/null || echo 1)
        if read -r quota period < /sys/fs/cgroup/cpu.max 2>/dev/null && [ "$quota" != "max" ]; then
            cpus=$(( (quota + period - 1) / period ))
        fi
        max=$(( cpus * 3 ))
        mem_kb=$(awk '/^MemTotal:/{print $2}' /proc/meminfo 2>/dev/null || echo 0)
        if read -r limit < /sys/fs/cgroup/memory.max 2>/dev/null && [ "$limit" != "max" ]; then
            mem_kb=$(( limit / 1024 ))
        fi
        by_mem=$(( (mem_kb / 1024 - 256) / 64 ))
        [ "$by_mem" -gt 0 ] && [ "$max" -gt "$by_mem" ] && max=$by_mem
        [ "$max" -lt 5 ] && max=5
    fi
    case "$max" in
        ''|*[!0-9]*|0) echo "entrypoint: OSC_FPM_MAX_CHILDREN must be a whole number above 0; keeping php-fpm's default." >&2; return 0 ;;
    esac
    spare=$(( (max + 3) / 4 ))
    {
        echo "; Rendered by entrypoint from the environment; do not edit."
        echo "[www]"
        echo "pm = dynamic"
        echo "pm.max_children = $max"
        echo "pm.start_servers = $spare"
        echo "pm.min_spare_servers = $spare"
        echo "pm.max_spare_servers = $(( (max + 1) / 2 ))"
    } > "$conf"
    echo "entrypoint: php-fpm runs up to $max workers."
}
write_fpm_pool_conf

# Built-in HTTPS: serve the certificate already on the volume from the first request.
/application/.docker/prod/tls.sh conf

# Outbound mail. The image bundles no MTA; msmtp is a send-only client that relays
# to a smarthost. So mail works when a relay is configured, and — crucially — is
# LOUD rather than silent when it is not: PHP mail() otherwise hands off to a
# sendmail that does not exist in this image and drops the message without a trace.
#
#   SMTP_HOST      relay host (required to send) — a provider submission host like
#                  smtp-relay.gmail.com, or a relay sidecar's service name.
#   SMTP_PORT      default 25. 587/465 (or SMTP_TLS=1) enables STARTTLS/TLS.
#   SMTP_USER      set to enable SMTP AUTH; omit for an unauthenticated sidecar.
#   SMTP_PASSWORD  password for SMTP_USER.
#   SMTP_FROM      default envelope From. Defaults to root@<hostname>. "Name <a@b.c>"
#                  is taken as a@b.c; the display name comes from Settings -> Mail.
#   SMTP_STARTTLS  on/off (also 1/0, true/false). Defaults to on, and off for port 465,
#                  which starts TLS at once.
configure_mail() {
    ini=/usr/local/etc/php/conf.d/zz-mail.ini   # loads after 99-shopclass.ini
    host="${SMTP_HOST:-}"
    if [ -z "$host" ]; then
        cat > /usr/local/bin/sendmail-unconfigured <<'SH'
#!/bin/sh
# No mail relay configured: consume the message and say so loudly, rather than
# failing silently. Set SMTP_HOST (+ SMTP_PORT/USER/PASSWORD) to actually send.
cat >/dev/null
echo "mail: no relay configured (set SMTP_HOST) — outgoing message dropped" >&2
exit 0
SH
        chmod +x /usr/local/bin/sendmail-unconfigured
        printf 'sendmail_path = "/usr/local/bin/sendmail-unconfigured"\n' > "$ini"
        echo "entrypoint: no mail relay configured; mail() will log and drop. Set SMTP_HOST to send."
        return 0
    fi
    port="${SMTP_PORT:-25}"
    from="${SMTP_FROM:-root@$(hostname)}"
    # msmtp wants a bare address here; it is the envelope sender, not the From: header.
    case "$from" in
        *"<"*">"*) from="${from##*<}"; from="${from%%>*}" ;;
    esac
    starttls="${SMTP_STARTTLS:-}"
    [ -z "$starttls" ] && { [ "$port" = "465" ] && starttls=off || starttls=on; }
    case "$starttls" in
        0|off|OFF|Off|false|FALSE|False|no|NO|No) starttls=off ;;
        *) starttls=on ;;
    esac
    {
        echo "# Rendered by entrypoint from the environment; do not edit."
        echo "defaults"
        echo "logfile -"
        if [ "${SMTP_TLS:-}" = "1" ] || [ "$port" = "587" ] || [ "$port" = "465" ]; then
            echo "tls on"
            echo "tls_starttls $starttls"
            echo "tls_trust_file /etc/ssl/certs/ca-certificates.crt"
        else
            echo "tls off"
        fi
        echo ""
        echo "account default"
        echo "host $host"
        echo "port $port"
        echo "from $from"
        if [ -n "${SMTP_USER:-}" ]; then
            echo "auth on"
            echo "user $SMTP_USER"
            echo "password ${SMTP_PASSWORD:-}"
        else
            echo "auth off"
        fi
    } > /etc/msmtprc
    # Readable by the php-fpm worker (www-data), not only root: sendmail_path is
    # "msmtp -t" with no -C, so a web request reads /etc/msmtprc as www-data. Left at
    # 0600 root, every WEB-context email (contact, registration, password reset, and
    # theme features like the IP-complaint notices) failed with "no configuration file
    # available", while cron mail — run as root — worked and hid it. Group-read only,
    # never world: this file holds the SMTP password when SMTP_USER is set.
    chown root:www-data /etc/msmtprc
    chmod 640 /etc/msmtprc
    printf 'sendmail_path = "/usr/bin/msmtp -t"\n' > "$ini"
    echo "entrypoint: mail relay -> ${host}:${port} (auth: ${SMTP_USER:+on}${SMTP_USER:-off})."
}
configure_mail

# Wait for the database server to accept connections. The DB may start after the
# app (compose depends_on notwithstanding, the server can still be initialising).
wait_for_db() {
    tries=0
    max=${DB_WAIT_RETRIES:-60}
    until php -r '
        mysqli_report(MYSQLI_REPORT_OFF);
        $host = getenv("DB_HOST") ?: "localhost";
        $port = getenv("DB_PORT") ?: null;
        if (strpos($host, ":") !== false) { [$host, $p] = explode(":", $host, 2); $port = $port ?: $p; }
        $conn = @mysqli_connect($host, getenv("DB_USER"), getenv("DB_PASSWORD"), "", (int) ($port ?: 3306));
        exit($conn ? 0 : 1);
    ' 2>/dev/null; do
        tries=$((tries + 1))
        if [ "$tries" -ge "$max" ]; then
            echo "entrypoint: database not reachable after ${max} attempts; aborting" >&2
            return 1
        fi
        echo "entrypoint: waiting for database (${tries}/${max})..."
        sleep 2
    done
}

wait_for_db

# Install if needed; a no-op once the install sentinel is present (and, crucially,
# does not require the admin/site env on a restart — it returns before validating
# them once installed). Runs before package:reconcile/db:upgrade below: both need
# the fully booted app (oc-load.php), which refuses to load until installed.
echo "entrypoint: ensuring Shopclass is installed..."
if ! php "$CLI" install --unattended; then
    echo "entrypoint: install failed — check DB_*/WEB_PATH/OSC_ADMIN_* environment" >&2
    exit 1
fi
# A language the install downloaded was written as root; the site updates and deletes it as www-data.
chown -R www-data:www-data /application/oc-content/languages 2>/dev/null || true

# Reconcile bundled plugins/themes onto the persistent oc-content volume: install
# ones this image bundles that the volume is missing, refresh ones the image
# ships a newer version of, and never touch anything installed through the
# market (see PackageReconciler). A no-op unless OSC_BUNDLED_CONTENT_PATH points
# at the pristine copy this image bakes outside the volume.
echo "entrypoint: reconciling bundled plugins/themes..."
if ! php "$CLI" package:reconcile; then
    echo "entrypoint: package:reconcile failed" >&2
    exit 1
fi

# Apply any pending schema migrations (e.g. after pulling a newer image). No-op on
# a fresh install (migrations were baselined) and on an up-to-date install.
echo "entrypoint: applying database migrations..."
if ! php "$CLI" db:upgrade; then
    echo "entrypoint: db:upgrade failed" >&2
    exit 1
fi

echo "entrypoint: startup checks complete; starting web stack."
# Exported only now: nginx is not running during the CLI steps above.
if [ -n "${page_cache_purge_url:-}" ]; then
    export OSC_PAGE_CACHE_PURGE_URL="$page_cache_purge_url"
fi
if [ -n "${live_publish_url:-}" ] && [ -z "${OSC_LIVE_PUBLISH_URL:-}" ]; then
    export OSC_LIVE_PUBLISH_URL="$live_publish_url"
fi
exec "$@"
