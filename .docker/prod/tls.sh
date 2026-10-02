#!/bin/sh
# Built-in HTTPS for the production image, on when OSC_TLS_DOMAIN is set.
#
#   tls.sh conf     write the nginx listen/redirect files for the certificate on disk
#   tls.sh run      get the certificate, then renew it (run by supervisord)
#   tls.sh reload   write the files and reload nginx (acme.sh calls this after a renewal)
#
# Let's Encrypt checks the domain over plain HTTP, so nginx serves the site on port 80
# until the first certificate arrives, then sends port 80 to HTTPS.
set -eu

HOME_DIR=/var/lib/shopclass-tls
LIVE="$HOME_DIR/live"
WEBROOT=/var/lib/acme-webroot
DOMAIN="${OSC_TLS_DOMAIN:-}"
ALIASES=$(printf '%s' "${OSC_TLS_REDIRECT_FROM:-}" | tr ',' ' ')

valid_name() {
    printf '%s' "$1" | grep -Eq '^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$' \
        && [ "$(printf '%s' "$1" | wc -l)" -eq 0 ]
}

check_names() {
    [ -n "$DOMAIN" ] || return 0
    valid_name "$DOMAIN" || { echo "tls: OSC_TLS_DOMAIN is not a valid domain" >&2; exit 1; }
    for a in $ALIASES; do
        valid_name "$a" || { echo "tls: OSC_TLS_REDIRECT_FROM has an invalid name: $a" >&2; exit 1; }
    done
}

# A certificate counts only if it was issued for the domain set now.
have_cert() {
    [ -n "$DOMAIN" ] && [ -s "$LIVE/fullchain.pem" ] && [ -s "$LIVE/key.pem" ] \
        && [ "$(head -n 1 "$LIVE/names" 2>/dev/null)" = "$DOMAIN" ]
}

write_conf() {
    listen=/etc/nginx/listen.conf
    extra=/etc/nginx/tls_http.conf
    if [ -n "$DOMAIN" ]; then
        printf 'map $https $fcgi_https {\n    default off;\n    on      on;\n}\n' >/etc/nginx/https_map.conf
    else
        printf 'map $http_x_forwarded_proto $fcgi_https {\n    default off;\n    https   on;\n}\n' >/etc/nginx/https_map.conf
    fi
    if ! have_cert; then
        printf 'listen 80 default_server;\nlisten [::]:80 default_server;\n' >"$listen"
        : >"$extra"
        return 0
    fi
    ssl="ssl_certificate $LIVE/fullchain.pem;
ssl_certificate_key $LIVE/key.pem;
ssl_protocols TLSv1.2 TLSv1.3;
ssl_session_cache shared:TLS:10m;"
    # Port 80 on loopback still reaches the site, for the health check and cache purges.
    printf 'listen 127.0.0.1:80;\nlisten [::1]:80;\nlisten 443 ssl default_server;\nlisten [::]:443 ssl default_server;\nhttp2 on;\n%s\n' "$ssl" >"$listen"
    {
        printf 'server {\n    listen 80 default_server;\n    listen [::]:80 default_server;\n'
        printf '    location ^~ /.well-known/acme-challenge/ {\n        root %s;\n        default_type text/plain;\n    }\n' "$WEBROOT"
        printf '    location / {\n        return 301 https://%s$request_uri;\n    }\n}\n' "$DOMAIN"
        tail -n +2 "$LIVE/names" | while IFS= read -r a; do
            [ -n "$a" ] || continue
            printf 'server {\n    listen 443 ssl;\n    listen [::]:443 ssl;\n    server_name %s;\n%s\n' "$a" "$ssl"
            printf '    return 301 https://%s$request_uri;\n}\n' "$DOMAIN"
        done
    } >"$extra"
}

acme() {
    acme.sh --home "$HOME_DIR/acme" --config-home "$HOME_DIR/acme" "$@"
}

# issue NAME... : get one certificate for all the names.
issue() {
    args=""
    for n in "$@"; do args="$args -d $n"; done
    email="${OSC_TLS_EMAIL:-${OSC_ADMIN_EMAIL:-}}"
    # shellcheck disable=SC2086
    acme --issue $args -w "$WEBROOT" --server "${OSC_TLS_ACME_SERVER:-letsencrypt}" \
        ${email:+--accountemail "$email"} --force >/dev/null 2>"$HOME_DIR/last-error.log" || return 1
    mkdir -p "$LIVE"
    printf '%s\n' "$@" >"$LIVE/names"
    # Copies the certificate into $LIVE and runs the reload, now and after each renewal.
    acme --install-cert -d "$1" --key-file "$LIVE/key.pem" --fullchain-file "$LIVE/fullchain.pem" \
        --reloadcmd "/application/.docker/prod/tls.sh reload" >/dev/null
}

reload_nginx() {
    write_conf
    nginx -t -q && nginx -s reload
}

run() {
    [ -n "$DOMAIN" ] || exit 0
    check_names
    mkdir -p "$HOME_DIR/acme" "$WEBROOT/.well-known/acme-challenge"
    chmod 700 "$HOME_DIR"

    # Wait for nginx to answer on port 80.
    until curl -s -o /dev/null http://127.0.0.1/; do sleep 2; done

    while ! have_cert; do
        echo "tls: getting a certificate for $DOMAIN${ALIASES:+ and $ALIASES}..."
        # shellcheck disable=SC2086
        if issue "$DOMAIN" $ALIASES; then
            break
        fi
        if [ -n "$ALIASES" ] && issue "$DOMAIN"; then
            echo "tls: $ALIASES failed the check, so only $DOMAIN has HTTPS. Point $ALIASES at this server, then restart." >&2
            break
        fi
        echo "tls: could not get a certificate for $DOMAIN. Check that its DNS points here and port 80 is open. Trying again in 1 hour." >&2
        tail -n 5 "$HOME_DIR/last-error.log" >&2 || true
        sleep 3600
    done
    # A redirect name added later needs a new certificate. If it fails, keep the one we have.
    if [ -n "$ALIASES" ] && [ "$(cat "$LIVE/names")" != "$(printf '%s\n' "$DOMAIN" $ALIASES)" ]; then
        echo "tls: adding $ALIASES to the certificate..."
        # shellcheck disable=SC2086
        issue "$DOMAIN" $ALIASES || echo "tls: could not add $ALIASES; keeping the current certificate." >&2
    fi
    echo "tls: HTTPS is on for $DOMAIN."

    # acme.sh renews a certificate 30 days before it runs out, then calls the reload.
    while :; do
        sleep 43200
        acme --cron >/dev/null 2>&1 || echo "tls: renewal check failed; it runs again in 12 hours." >&2
    done
}

case "${1:-}" in
    conf) check_names; write_conf ;;
    run) run ;;
    reload) reload_nginx ;;
    *) echo "usage: tls.sh conf|run|reload" >&2; exit 2 ;;
esac
