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
#   SMTP_FROM      default envelope From. Defaults to root@<hostname>.
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
    {
        echo "# Rendered by entrypoint from the environment; do not edit."
        echo "defaults"
        echo "logfile -"
        if [ "${SMTP_TLS:-}" = "1" ] || [ "$port" = "587" ] || [ "$port" = "465" ]; then
            echo "tls on"
            echo "tls_starttls ${SMTP_STARTTLS:-on}"
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
    chmod 600 /etc/msmtprc
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
