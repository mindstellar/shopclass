#!/usr/bin/env bash
#
# This file is part of Shopclass (Mindstellar).
# Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
#
# Distributed under the GNU General Public License v3.0 or later. See LICENSE.
#
# SPDX-License-Identifier: GPL-3.0-or-later
#
## Checks the nginx config the prod entrypoint writes for live messages (nchan).
## Runs write_live_conf from .docker/prod/entrypoint.sh against a temp directory with
## OSC_LIVE and OSC_CACHE set different ways. Shell only, no Docker.
##
## Usage:  bash tests/docker-live-conf.sh

set -u

ROOT=$(cd "$(dirname "$0")/.." && pwd)
ENTRY="$ROOT/.docker/prod/entrypoint.sh"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# From the definition to the line before the call: the heredocs have their own "}" lines.
FN=$(sed -n '/^write_live_conf() {/,/^write_live_conf$/p' "$ENTRY" | sed '$d')
pass=0
fail=0

ok() {
    if [ "$1" = 0 ]; then
        pass=$((pass + 1))
    else
        fail=$((fail + 1))
        echo "FAIL: $2"
    fi
}

# run NAME [VAR=value ...]: writes the two files into $TMP/NAME, the log into
# $TMP/NAME/log and the exported publish URL into $TMP/NAME/url.
run() {
    local dir=$1
    shift
    mkdir -p "$TMP/$dir"
    env -i PATH="$PATH" "$@" sh -c "set -eu; $FN
write_live_conf \"\$1\" > \"\$1/log\" 2>&1
printf '%s' \"\$live_publish_url\" > \"\$1/url\"" sh "$TMP/$dir"
    ok $? "$dir: write_live_conf exited non-zero"
}

has() { grep -Eq -- "$2" "$TMP/$1"; }

[ -n "$FN" ]
ok $? "write_live_conf() is in the entrypoint"

# Off: unset, off, and anything that is not exactly nchan.
for case in unset:'' off:off upper:NCHAN one:1; do
    name=${case%%:*}
    value=${case#*:}
    if [ "$name" = unset ]; then run "off-$name"; else run "off-$name" OSC_LIVE="$value"; fi
    [ ! -s "$TMP/off-$name/live_http.conf" ] && [ ! -s "$TMP/off-$name/live_server.conf" ]
    ok $? "off-$name: both files empty"
    ! cat "$TMP/off-$name"/live_*.conf | grep -q nchan
    ok $? "off-$name: no nchan directive"
    [ "$(cat "$TMP/off-$name/log")" = "entrypoint: live messages off." ]
    ok $? "off-$name: one 'off' log line"
    [ ! -s "$TMP/off-$name/url" ]
    ok $? "off-$name: no publish URL"
done

# Off after on: a restart with OSC_LIVE=off empties files left by an earlier start.
run reset OSC_LIVE=nchan
env -i PATH="$PATH" OSC_LIVE=off sh -c "set -eu; $FN
write_live_conf \"\$1\" >/dev/null" sh "$TMP/reset"
[ ! -s "$TMP/reset/live_http.conf" ] && [ ! -s "$TMP/reset/live_server.conf" ]
ok $? "reset: OSC_LIVE=off empties files written by an earlier start"

# On, no cache server.
run on OSC_LIVE=nchan
has on/live_http.conf '^nchan_shared_memory_size 32m;$'
ok $? "on: shared memory size"
[ "$(grep -c 'listen ' "$TMP/on/live_http.conf")" = 3 ] \
    && ! grep 'listen ' "$TMP/on/live_http.conf" | grep -Evq 'listen (127\.0\.0\.1:809[01]|\[::1\]:8090);'
ok $? "on: the publisher (8090) and the authorise server (8091) listen on the loopback only"
[ "$(grep -c 'set_real_ip_from 0\.0\.0\.0/32;' "$TMP/on/live_http.conf")" = 2 ]
ok $? "on: a forwarded header cannot rewrite either loopback server's client address"
has on/live_http.conf 'location ~ \^/live/pub/\(user-\\d\+\)\$ \{' && has on/live_http.conf 'nchan_publisher http;'
ok $? "on: publisher location takes user-<id> channels only"
has on/live_http.conf 'nchan_message_buffer_length 10;' && has on/live_http.conf 'nchan_message_timeout 60s;'
ok $? "on: message buffer 10 / 60 s"
! cat "$TMP/on"/live_*.conf | grep -q redis
ok $? "on: no Valkey without OSC_CACHE=redis"
! has on/live_server.conf 'listen|nchan_publisher'
ok $? "on: no publisher in the public server"
has on/live_server.conf 'location ~ \^/live/sub/\(\?<osc_live_channel>user-\\d\+\)\$ \{'
ok $? "on: subscriber location takes user-<id> channels only"
has on/live_server.conf 'nchan_subscriber eventsource;' && has on/live_server.conf 'nchan_channel_id \$osc_live_channel;'
ok $? "on: eventsource subscriber on the URL's channel"
has on/live_server.conf '^    nchan_authorize_request /live/auth;$'
ok $? "on: nchan_authorize_request /live/auth"
has on/live_server.conf 'nchan_subscriber_first_message newest;' && has on/live_server.conf 'gzip off;'
ok $? "on: first message newest, gzip off"
has on/live_server.conf '^    set \$osc_live_addr \$remote_addr;$' \
    && has on/live_server.conf '^    set \$osc_live_https \$fcgi_https;$' \
    && has on/live_server.conf '^    set \$osc_live_port \$server_port;$'
ok $? "on: client address, HTTPS flag and port kept for the authorise call"
auth=$(sed -n '/^location = \/live\/auth {/,/^}/p' "$TMP/on/live_server.conf")
printf '%s\n' "$auth" | grep -q '^    internal;$'
ok $? "on: /live/auth is internal"
printf '%s\n' "$auth" | grep -q '^    proxy_pass http://127\.0\.0\.1:8091;$'
ok $? "on: /live/auth goes to the loopback authorise server"
printf '%s\n' "$auth" | grep -q '^    proxy_set_header X-Channel-Id \$osc_live_channel;$'
ok $? "on: the channel comes from the URL, not the client's header"
printf '%s\n' "$auth" | grep -q 'proxy_set_header X-Osc-Remote-Addr \$osc_live_addr;' \
    && printf '%s\n' "$auth" | grep -q 'proxy_set_header X-Osc-Https \$osc_live_https;' \
    && printf '%s\n' "$auth" | grep -q 'proxy_set_header X-Osc-Server-Port \$osc_live_port;' \
    && printf '%s\n' "$auth" | grep -q 'proxy_set_header Host \$http_host;'
ok $? "on: client address, HTTPS flag, port and host passed on"
printf '%s\n' "$auth" | grep -q 'proxy_pass_request_body off;' \
    && printf '%s\n' "$auth" | grep -q 'proxy_set_header Content-Length "";'
ok $? "on: no request body to the authorise server"
fcgi=$(sed -n '/^    listen 127\.0\.0\.1:8091;$/,/^}/p' "$TMP/on/live_http.conf")
printf '%s\n' "$fcgi" | grep -q '^    location = /live/auth {$'
ok $? "on: the authorise server answers /live/auth only"
printf '%s\n' "$fcgi" | grep -q 'fastcgi_param QUERY_STRING page=ajax&action=messages_live_auth;' \
    && printf '%s\n' "$fcgi" | grep -q 'fastcgi_param REQUEST_URI /index.php?page=ajax&action=messages_live_auth;' \
    && printf '%s\n' "$fcgi" | grep -q 'fastcgi_param REQUEST_METHOD GET;' \
    && printf '%s\n' "$fcgi" | grep -q 'fastcgi_param SCRIPT_FILENAME \$document_root/index.php;'
ok $? "on: the authorise server runs index.php?page=ajax&action=messages_live_auth as GET"
printf '%s\n' "$fcgi" | grep -q 'fastcgi_param REMOTE_ADDR \$http_x_osc_remote_addr;' \
    && printf '%s\n' "$fcgi" | grep -q 'fastcgi_param HTTPS \$http_x_osc_https;' \
    && printf '%s\n' "$fcgi" | grep -q 'fastcgi_param SERVER_PORT \$http_x_osc_server_port;'
ok $? "on: PHP sees the visitor's address, HTTPS flag and port"
[ "$(printf '%s\n' "$fcgi" | grep -c 'fastcgi_param HTTP_X_OSC_[A-Z_]* "" if_not_empty;')" = 3 ]
ok $? "on: the X-Osc-* carrier headers are not passed to PHP"
! printf '%s\n' "$fcgi" | grep -q 'include' && ! printf '%s\n' "$fcgi" | grep -q 'HTTP_X_CHANNEL_ID'
ok $? "on: no fastcgi_params include, X-Channel-Id left as /live/auth set it"
printf '%s\n' "$fcgi" | grep -q 'fastcgi_pass_request_body off;'
ok $? "on: no request body to PHP"
[ -z "$(printf '%s\n' "$fcgi" | awk '$1 == "fastcgi_param" {print $2}' | sort | uniq -d)" ]
ok $? "on: no fastcgi_param name twice"
[ "$(cat "$TMP/on/log")" = "entrypoint: live messages on (nchan)." ]
ok $? "on: one 'on (nchan)' log line"
[ "$(cat "$TMP/on/url")" = "http://127.0.0.1:8090/live/pub/" ]
ok $? "on: publish URL is the loopback publisher"

# On, with the Valkey fast lane.
run valkey OSC_LIVE=nchan OSC_CACHE=redis OSC_CACHE_HOST=valkey OSC_CACHE_PASSWORD=shopclass
has valkey/live_http.conf '^    nchan_redis_server "redis://valkey:6379/0";$'
ok $? "valkey: upstream with the default port and db"
has valkey/live_http.conf '^    nchan_redis_password "shopclass";$' && ! has valkey/live_http.conf 'nchan_redis_username'
ok $? "valkey: password, no username"
[ "$(grep -c '^ *nchan_redis_pass shopclass_live;$' "$TMP/valkey/live_http.conf")" = 1 ] \
    && [ "$(grep -c '^ *nchan_redis_pass shopclass_live;$' "$TMP/valkey/live_server.conf")" = 1 ]
ok $? "valkey: publisher and subscriber locations both use the upstream"
has valkey/live_http.conf '^nchan_redis_namespace shopclass:live;$'
ok $? "valkey: own namespace"
! has valkey/live_http.conf 'nchan_use_redis|nchan_redis_url'
ok $? "valkey: no deprecated nchan_use_redis / nchan_redis_url"
[ "$(stat -c %a "$TMP/valkey/live_http.conf")" = 600 ]
ok $? "valkey: file holding the password is mode 600"
[ "$(cat "$TMP/valkey/log")" = "entrypoint: live messages on (nchan, Valkey)." ]
ok $? "valkey: one 'on (nchan, Valkey)' log line"

run valkey-acl OSC_LIVE=nchan OSC_CACHE=redis OSC_CACHE_HOST=cache.internal OSC_CACHE_PORT=6380 \
    OSC_CACHE_DB=2 OSC_CACHE_USERNAME=app OSC_CACHE_PASSWORD='p:ss@w;rd'
has valkey-acl/live_http.conf '^    nchan_redis_server "redis://cache\.internal:6380/2";$' \
    && has valkey-acl/live_http.conf '^    nchan_redis_username "app";$' \
    && has valkey-acl/live_http.conf '^    nchan_redis_password "p:ss@w;rd";$'
ok $? "valkey-acl: user, password, port and db"

run valkey-open OSC_LIVE=nchan OSC_CACHE=redis OSC_CACHE_HOST=valkey
has valkey-open/live_http.conf '^    nchan_redis_server "redis://valkey:6379/0";$' \
    && ! has valkey-open/live_http.conf 'nchan_redis_password|nchan_redis_username'
ok $? "valkey-open: no credentials"

run memcached OSC_LIVE=nchan OSC_CACHE=memcached OSC_CACHE_HOST=mc
! has memcached/live_http.conf 'redis'
ok $? "memcached: no Valkey"

run nohost OSC_LIVE=nchan OSC_CACHE=redis
! has nohost/live_http.conf 'redis'
ok $? "redis without OSC_CACHE_HOST: no Valkey"

run badpass OSC_LIVE=nchan OSC_CACHE=redis OSC_CACHE_HOST=valkey OSC_CACHE_PASSWORD='se"cret'
! has badpass/live_http.conf 'redis' && has badpass/live_http.conf 'nchan_publisher http;'
ok $? "badpass: a password nchan cannot parse keeps live on without Valkey"
grep -q 'warning' "$TMP/badpass/log" && ! grep -q 'se"cret' "$TMP/badpass/log"
ok $? "badpass: warns without printing the password"

run badhost OSC_LIVE=nchan OSC_CACHE=redis OSC_CACHE_HOST='valkey;evil' OSC_CACHE_PASSWORD=x
! has badhost/live_http.conf 'redis|evil'
ok $? "badhost: a host with ';' is not written into nginx"

# The parts outside write_live_conf.
grep -q '^write_live_conf$' "$ENTRY"
ok $? "entrypoint calls write_live_conf"
grep -q 'if \[ -n "${live_publish_url:-}" \] && \[ -z "${OSC_LIVE_PUBLISH_URL:-}" \]; then' "$ENTRY"
ok $? "entrypoint exports OSC_LIVE_PUBLISH_URL only when unset"
grep -q '^    include /etc/nginx/live_http.conf;$' "$ROOT/.docker/prod/nginx.conf" \
    && grep -q '^        include /etc/nginx/live_server.conf;$' "$ROOT/.docker/prod/nginx.conf"
ok $? "nginx.conf includes live_http.conf in http{} and live_server.conf in the server"
tr '\n' '~' < "$ROOT/.docker/prod/nginx.conf" \
    | grep -Fq '        location /live/sub/ {~            return 404;~        }~        location /live/pub/ {~            return 404;'
ok $? "nginx.conf answers 404 for /live/sub/ and /live/pub/ the include leaves"
grep -q ': > /etc/nginx/live_http.conf' "$ROOT/Dockerfile" && grep -q ': > /etc/nginx/live_server.conf' "$ROOT/Dockerfile"
ok $? "Dockerfile creates both files empty"
grep -q '^    OSC_LIVE=nchan$' "$ROOT/Dockerfile"
ok $? "Dockerfile sets OSC_LIVE=nchan"
grep -q '\[ "$have" = "$built" \] || { echo "nchan was built for nginx $built, the image has $have" >&2; exit 1; }' "$ROOT/Dockerfile"
ok $? "Dockerfile fails when nchan was built for another nginx"
grep -q 'sha256sum -c -' "$ROOT/Dockerfile"
ok $? "Dockerfile checks the nchan tarball's sha256"

echo "docker-live-conf: $pass passed, $fail failed"
[ "$fail" = 0 ]
