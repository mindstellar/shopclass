#!/bin/sh
# Container health probe. The app answers on / with 200 once installed and a 3xx
# redirect otherwise (installer / canonical URL) — either means the web stack
# (nginx + php-fpm) is up and serving. A database outage surfaces as a 5xx from
# the app, and a down web stack as a connection failure; both fail the check.
#
# Override the probed path with HEALTHCHECK_PATH if a deployment prefers a
# specific URL.
set -eu

url="http://127.0.0.1${HEALTHCHECK_PATH:-/}"
code=$(curl -sS -o /dev/null -w '%{http_code}' "$url" 2>/dev/null || echo 000)

case "$code" in
    200 | 301 | 302 | 303 | 307 | 308)
        exit 0
        ;;
    *)
        echo "healthcheck: $url returned $code" >&2
        exit 1
        ;;
esac
