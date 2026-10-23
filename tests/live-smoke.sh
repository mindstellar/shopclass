#!/usr/bin/env bash
#
# This file is part of Shopclass (Mindstellar).
# Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
#
# Distributed under the GNU General Public License v3.0 or later. See LICENSE.
#
# SPDX-License-Identifier: GPL-3.0-or-later
#
## End-to-end check of live messages on the prod image. Needs a running stack, for
## example `docker compose -f docker-compose.prod.yml up -d --build`. It switches the API
## and messaging on, adds two users and a listing, then:
##   1. live on:  the seller opens /live/sub/user-<id>, the buyer writes through the API,
##                and the note must reach the seller in under 2 seconds
##   2. live off: the stack is restarted with OSC_LIVE=off; the stream URL must answer 404,
##                the inbox must print no live URL, and polling (messages_check, ETag, 304)
##                must still see the next message
## The stack is put back with live on at the end. Use a throwaway stack: it changes settings.
##
## Usage:  bash tests/live-smoke.sh [base url]       (default http://localhost:8080)
## Env:    COMPOSE  the compose command, default "docker compose -f docker-compose.prod.yml"
##         APP      the app service name, default "app"
##         LIVE_MAX seconds a note may take, default 2

set -u

BASE=${1:-http://localhost:8080}
BASE=${BASE%/}
COMPOSE=${COMPOSE:-docker compose -f docker-compose.prod.yml}
APP=${APP:-app}
LIVE_MAX=${LIVE_MAX:-2}
PASS=smoke-pass-1
RUN=$(date +%s)
TMP=$(mktemp -d)
SUB_PID=
SWITCHED=0
pass=0
fail=0

now() { date +%s.%N; }
# secs A B: B minus A, seconds with three decimals
secs() { awk -v a="$1" -v b="$2" 'BEGIN { printf "%.3f", b - a }'; }
below() { awk -v a="$1" -v b="$2" 'BEGIN { exit !(a < b) }'; }

check() {
    if [ "$1" = 0 ]; then
        pass=$((pass + 1))
        echo "ok    $2"
    else
        fail=$((fail + 1))
        echo "FAIL  $2"
    fi
}

stop_sub() {
    [ -z "$SUB_PID" ] || { kill -- "-$SUB_PID" 2> /dev/null; wait "$SUB_PID" 2> /dev/null; }
    SUB_PID=
}

cleanup() {
    stop_sub
    if [ "$SWITCHED" = 1 ]; then
        echo "putting live messages back on"
        $COMPOSE up -d "$APP" > /dev/null 2>&1
    fi
    rm -rf "$TMP"
}
trap cleanup EXIT

wait_ready() {
    local i
    for i in $(seq 1 90); do
        [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")" = 200 ] && return 0
        sleep 1
    done
    return 1
}

api() { # api METHOD PATH TOKEN [JSON]
    curl -s -X "$1" -H "Authorization: Bearer $3" -H 'Content-Type: application/json' \
        ${4:+-d "$4"} "$BASE/index.php?page=api&path=v1/$2"
}

token() { # token USERNAME
    curl -s -d grant_type=password -d "username=$1" -d "password=$PASS" \
        "$BASE/index.php?page=api&path=v1/auth/token" | sed -n 's/.*"access_token":"\([^"]*\)".*/\1/p'
}

login() { # login USERNAME JAR
    local page name tok
    page=$(curl -s -c "$2" -b "$2" "$BASE/index.php?page=login")
    name=$(grep -o "name='CSRFName' value='[^']*'" <<< "$page" | sed "s/.*value='//;s/'//")
    tok=$(grep -o "name='CSRFToken' value='[^']*'" <<< "$page" | sed "s/.*value='//;s/'//")
    curl -s -o /dev/null -c "$2" -b "$2" --data-urlencode "CSRFName=$name" --data-urlencode "CSRFToken=$tok" \
        -d "page=login&action=login_post&email=$1&password=$PASS" "$BASE/index.php"
}

etag() { # etag JAR [IF-NONE-MATCH]: prints "<status> <etag>"
    curl -s -o /dev/null -D - -b "$1" ${2:+-H "If-None-Match: $2"} \
        "$BASE/index.php?page=ajax&action=messages_check" |
        awk 'NR == 1 { code = $2 } tolower($1) == "etag:" { tag = $2 } END { gsub(/\r/, "", tag); print code, tag }'
}

echo "Seeding users and a listing on $BASE"
IDS=$({
    echo '<?php'
    cat << 'PHP'
define('CLI', true);
require '/application/oc-load.php';
use mindstellar\database\Db;
osc_set_preference('enabled_messaging', '1');
osc_set_preference('api_enabled', '1', 'api');
$run = $argv[1];
$ids = [];
foreach (['seller', 'buyer'] as $who) {
    $ids[$who] = Db::table(DB_TABLE_PREFIX . 't_user')->insert([
        'dt_reg_date' => date('Y-m-d H:i:s'), 's_name' => ucfirst($who), 's_username' => "$who$run",
        's_password' => osc_hash_password('smoke-pass-1'), 's_email' => "$who$run@example.test",
        'b_enabled' => 1, 'b_active' => 1,
    ]);
}
$item = Item::newInstance()->insertGetId([
    'fk_i_user_id' => $ids['seller'], 'fk_i_category_id' => 1, 'dt_pub_date' => date('Y-m-d H:i:s'),
    'dt_mod_date' => date('Y-m-d H:i:s'), 'dt_expiration' => date('Y-m-d H:i:s', time() + 86400 * 30),
    'fk_c_currency_code' => 'USD', 's_contact_name' => 'Seller', 's_contact_email' => "seller$run@example.test",
    's_ip' => '127.0.0.1', 'b_enabled' => 1, 'b_active' => 1, 'b_spam' => 0, 's_secret' => "smoke$run",
]);
Item::newInstance()->insertLocale($item, 'en_US', "Smoke bike $run", 'A bike for the live smoke test.');
echo "$ids[seller] $ids[buyer] $item\n";
PHP
} | $COMPOSE exec -T "$APP" sh -c "php /dev/stdin $RUN" | tail -1)
$COMPOSE exec -T "$APP" php /application/oc-cli.php cache:flush > /dev/null 2>&1
read -r SELLER BUYER ITEM <<< "$IDS"
if [ -z "${ITEM:-}" ]; then
    echo "FAIL  could not seed (got: $IDS)"
    exit 1
fi
echo "seller $SELLER, buyer $BUYER, listing $ITEM"

JAR="$TMP/seller.jar"
login "seller$RUN" "$JAR"

echo
echo "== live on =="
INBOX=$(curl -s -b "$JAR" "$BASE/index.php?page=messages")
grep -q "data-live-url=\"/live/sub/user-$SELLER\"" <<< "$INBOX"
check $? "inbox prints data-live-url=/live/sub/user-$SELLER"

SUB="$TMP/sub.log"
: > "$SUB"
setsid bash -c '
    curl -sN --max-time 30 -D "$1" -H "Accept: text/event-stream" -b "$2" "$3" |
        while IFS= read -r line; do printf "%s %s\n" "$(date +%s.%N)" "$line"; done' _ \
    "$TMP/sub.head" "$JAR" "$BASE/live/sub/user-$SELLER" > "$SUB" &
SUB_PID=$!
for _ in $(seq 1 50); do
    [ -s "$TMP/sub.head" ] && grep -q '^$' "$SUB" 2> /dev/null && break
    sleep 0.1
done
head -1 "$TMP/sub.head" | grep -q ' 200'
check $? "seller's stream opens (200)"

BTOK=$(token "buyer$RUN")
[ -n "$BTOK" ]
check $? "buyer signs in to the API"

T0=$(now)
SENT=$(api POST account/conversations "$BTOK" "{\"listing\":$ITEM,\"body\":\"Is it still for sale?\"}")
T1=$(now)
CONV=$(sed -n 's/.*"conversation_id":\([0-9]*\).*/\1/p' <<< "$SENT")
[ -n "$CONV" ]
check $? "buyer's message accepted (conversation $CONV, API call took $(secs "$T0" "$T1") s)"

ARRIVED=
for _ in $(seq 1 60); do
    ARRIVED=$(grep "\"kind\":\"sent\".*\"conversation\":$CONV" "$SUB" | head -1)
    [ -n "$ARRIVED" ] && break
    sleep 0.05
done
if [ -n "$ARRIVED" ]; then
    T2=${ARRIVED%% *}
    LAG=$(secs "$T0" "$T2")
    echo "note: $ARRIVED" | cut -d' ' -f1,3-
    echo "      $LAG s after the request started, $(secs "$T1" "$T2") s after the API answered"
    below "$LAG" "$LIVE_MAX"
    check $? "note reached the seller in under $LIVE_MAX s"
else
    check 1 "note reached the seller (nothing arrived in 3 s)"
fi
! grep -q '"text\|"body\|"s_body' <<< "$ARRIVED"
check $? "note carries ids only"
stop_sub

echo
echo "== live off =="
cat > "$TMP/off.yml" << 'YML'
services:
  app:
    environment:
      OSC_LIVE: "off"
YML
SWITCHED=1
$COMPOSE -f "$TMP/off.yml" up -d "$APP" > /dev/null 2>&1
wait_ready
check $? "stack is back up with OSC_LIVE=off"

[ "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: text/event-stream' -b "$JAR" "$BASE/live/sub/user-$SELLER")" = 404 ]
check $? "stream URL answers 404"

INBOX=$(curl -s -b "$JAR" "$BASE/index.php?page=messages")
grep -q 'data-osc-inbox' <<< "$INBOX" && ! grep -q 'data-live-url="/live' <<< "$INBOX"
check $? "inbox is there and prints no live URL"

read -r CODE1 TAG1 <<< "$(etag "$JAR")"
read -r CODE2 _ <<< "$(etag "$JAR" "$TAG1")"
[ "$CODE1" = 200 ] && [ "$CODE2" = 304 ]
check $? "messages_check: $CODE1 first, $CODE2 with the same ETag"

BTOK=$(token "buyer$RUN")
api POST "account/conversations/$CONV/messages" "$BTOK" '{"body":"Still there?"}' > /dev/null
read -r CODE3 TAG3 <<< "$(etag "$JAR" "$TAG1")"
[ "$CODE3" = 200 ] && [ "$TAG3" != "$TAG1" ]
check $? "next message shows by polling: messages_check $CODE3 with the old ETag"
read -r CODE4 _ <<< "$(etag "$JAR" "$TAG3")"
[ "$CODE4" = 304 ]
check $? "and 304 once the new ETag is sent ($CODE4)"

echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
