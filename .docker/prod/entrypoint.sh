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
# them once installed).
echo "entrypoint: ensuring Shopclass is installed..."
if ! php "$CLI" install --unattended; then
    echo "entrypoint: install failed — check DB_*/WEB_PATH/OSC_ADMIN_* environment" >&2
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
exec "$@"
