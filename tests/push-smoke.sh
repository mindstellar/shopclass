#!/usr/bin/env bash
#
# This file is part of Shopclass (Mindstellar).
# Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
#
# Distributed under the GNU General Public License v3.0 or later. See LICENSE.
#
# SPDX-License-Identifier: GPL-3.0-or-later
#
## End-to-end check of browser notifications. Needs a running stack on `localhost` or
## HTTPS, for example `docker compose -f docker-compose.prod.yml up -d --build`. It switches
## messaging, push and the API on, adds two users and a listing, then:
##   1. the seller's messages page prints the opt-in form, and push_worker answers
##      text/javascript with no-cache
##   2. push_subscribe takes a push address (CSRF checked), refuses a private one, and
##      push_status says active
##   3. the buyer writes through the API; the wake-up job is queued once
##   4. the job runs in a one-off PHP process whose HTTP client is a recorder (the site
##      refuses to POST to a local stub, by design): one empty POST with an Authorization:
##      vapid header and a Topic header, and the answer 410 removes the subscription
##   5. switch off: push_worker answers 404 and the page prints no form
## The push address is a public host name; nothing is sent to it. Use a throwaway stack.
##
## Usage:  bash tests/push-smoke.sh [base url]       (default http://localhost:8080)
## Env:    COMPOSE  the compose command, default "docker compose -f docker-compose.prod.yml"
##         APP      the app service name, default "app"

set -u

BASE=${1:-http://localhost:8080}
BASE=${BASE%/}
COMPOSE=${COMPOSE:-docker compose -f docker-compose.prod.yml}
APP=${APP:-app}
PASS=smoke-pass-1
RUN=$(date +%s)
ENDPOINT="https://updates.push.services.mozilla.com/wpush/v2/smoke$RUN"
TMP=$(mktemp -d)
pass=0
fail=0

check() {
    if [ "$1" = 0 ]; then
        pass=$((pass + 1))
        echo "ok    $2"
    else
        fail=$((fail + 1))
        echo "FAIL  $2"
    fi
}

cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

php_in_app() { # php_in_app ARGS... : runs the PHP on stdin inside the app container
    $COMPOSE exec -T "$APP" sh -c "php /dev/stdin $*"
}

api() { # api METHOD PATH TOKEN [JSON]
    curl -s -X "$1" -H "Authorization: Bearer $3" -H 'Content-Type: application/json' \
        ${4:+-d "$4"} "$BASE/index.php?page=api&path=v1/$2"
}

token() { # token USERNAME
    curl -s -d grant_type=password -d "username=$1" -d "password=$PASS" \
        "$BASE/index.php?page=api&path=v1/auth/token" | sed -n 's/.*"access_token":"\([^"]*\)".*/\1/p'
}

field() { # field NAME HTML: value of a CSRF input
    grep -o "name='$1' value='[^']*'" <<< "$2" | head -1 | sed "s/.*value='//;s/'//"
}

login() { # login USERNAME JAR
    local page
    page=$(curl -s -c "$2" -b "$2" "$BASE/index.php?page=login")
    curl -s -o /dev/null -c "$2" -b "$2" \
        --data-urlencode "CSRFName=$(field CSRFName "$page")" --data-urlencode "CSRFToken=$(field CSRFToken "$page")" \
        -d "page=login&action=login_post&email=$1&password=$PASS" "$BASE/index.php"
}

ajax() { # ajax JAR ACTION [curl args]: POST with the CSRF pair taken from the inbox
    local jar=$1 action=$2 page
    shift 2
    page=$(curl -s -b "$jar" "$BASE/index.php?page=messages")
    curl -s -b "$jar" --data-urlencode "CSRFName=$(field CSRFName "$page")" \
        --data-urlencode "CSRFToken=$(field CSRFToken "$page")" "$@" \
        "$BASE/index.php?page=ajax&action=$action"
}

echo "Seeding users and a listing on $BASE"
IDS=$({
    echo '<?php'
    cat << 'PHP'
define('CLI', true);
require '/application/oc-load.php';
use mindstellar\database\Db;
osc_set_preference('enabled_messaging', '1');
osc_set_preference('enabled_push', '1');
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
Item::newInstance()->insertLocale($item, 'en_US', "Smoke bike $run", 'A bike for the push smoke test.');
echo "$ids[seller] $ids[buyer] $item\n";
PHP
} | php_in_app "$RUN" | tail -1)
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
echo "== opt-in =="
INBOX=$(curl -s -b "$JAR" "$BASE/index.php?page=messages")
grep -q 'data-osc-push' <<< "$INBOX"
check $? "messages page prints the opt-in form"

HEAD=$(curl -s -D - -o "$TMP/worker.js" -b "$JAR" "$BASE/index.php?page=ajax&action=push_worker")
grep -qi '^content-type: text/javascript' <<< "$HEAD" && grep -qi '^cache-control:.*no-cache' <<< "$HEAD" && [ -s "$TMP/worker.js" ]
check $? "push_worker answers text/javascript, no-cache"

R=$(ajax "$JAR" push_subscribe -d "endpoint=$ENDPOINT")
grep -q '"ok":true' <<< "$R"
check $? "push_subscribe takes a push address"

# Core answers an AJAX CSRF failure with HTTP 200 and an error body.
R=$(curl -s -b "$JAR" -d "endpoint=$ENDPOINT" "$BASE/index.php?page=ajax&action=push_subscribe")
grep -q '"error":1' <<< "$R"
check $? "push_subscribe without the CSRF token is refused"

CODE=$(ajax "$JAR" push_subscribe -o /dev/null -w '%{http_code}' -d 'endpoint=https://127.0.0.1/x')
[ "$CODE" = 422 ]
check $? "a private address is refused ($CODE)"

R=$(curl -s -b "$JAR" --get --data-urlencode "endpoint=$ENDPOINT" "$BASE/index.php?page=ajax&action=push_status")
grep -q '"active":true' <<< "$R"
check $? "push_status says active"

echo
echo "== wake-up =="
BTOK=$(token "buyer$RUN")
[ -n "$BTOK" ]
check $? "buyer signs in to the API"
SENT=$(api POST account/conversations "$BTOK" "{\"listing\":$ITEM,\"body\":\"Is it still for sale?\"}")
api POST account/conversations "$BTOK" "{\"listing\":$ITEM,\"body\":\"And the lock?\"}" > /dev/null
grep -q '"conversation_id"' <<< "$SENT"
check $? "buyer's messages accepted"

# Runs the queued job at once, with a recording client that answers 410, so the stack's
# own worker (it waits 15 s) has nothing left to send to the fake address.
OUT=$({
    echo '<?php'
    cat << 'PHP'
define('CLI', true);
require '/application/oc-load.php';
use mindstellar\container\Container;
use mindstellar\database\Db;
use mindstellar\http\HttpClient;
use mindstellar\push\PushSubscriptions;
use mindstellar\security\AddressGuard;

$sent = [];
$c = Container::root();
$c->instance(HttpClient::class, new class ($sent) implements HttpClient {
    public function __construct(public array &$sent) {}
    public function send(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): array
    {
        $this->sent[] = compact('method', 'url', 'headers', 'body');
        return ['status' => 410, 'headers' => [], 'body' => ''];
    }
});
$c->instance(AddressGuard::class, new AddressGuard(static fn () => ['93.184.216.34']));
Db::table(DB_TABLE_PREFIX . 't_job_queue')->where('s_type', 'message.push')
    ->update(['dt_next_run' => date('Y-m-d H:i:s', time() - 5)]);
$queued = Db::table(DB_TABLE_PREFIX . 't_job_queue')->where('s_type', 'message.push')->count();
osc_job_run(10);
$h = [];
foreach (($sent[0]['headers'] ?? []) as $k => $v) {
    $h[strtolower(is_int($k) ? explode(':', (string) $v)[0] : $k)] = is_int($k) ? trim(explode(':', (string) $v, 2)[1] ?? '') : $v;
}
echo json_encode([
    'queued' => $queued, 'posts' => count($sent), 'method' => $sent[0]['method'] ?? '',
    'empty' => ($sent[0]['body'] ?? '') === '' || ($sent[0]['body'] ?? null) === null,
    'vapid' => str_starts_with($h['authorization'] ?? '', 'vapid t='), 'topic' => $h['topic'] ?? '',
    'left' => count($c->get(PushSubscriptions::class)->forUser((int) $argv[1])),
]), "\n";
PHP
} | php_in_app "$SELLER" | tail -1)
echo "job: $OUT"
grep -q '"queued":1' <<< "$OUT"
check $? "two messages queued one wake-up"
grep -q '"posts":1,"method":"POST","empty":true' <<< "$OUT"
check $? "one POST with no body"
grep -q '"vapid":true,"topic":"shopclass-messages"' <<< "$OUT"
check $? "Authorization: vapid and Topic headers set"
grep -q '"left":0' <<< "$OUT"
check $? "a 410 answer removed the subscription"

echo
echo "== switched off =="
$COMPOSE exec -T "$APP" sh -c "echo \"<?php define('CLI', true); require '/application/oc-load.php'; osc_set_preference('enabled_push', '0');\" | php" > /dev/null 2>&1
$COMPOSE exec -T "$APP" php /application/oc-cli.php cache:flush > /dev/null 2>&1
[ "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/index.php?page=ajax&action=push_worker")" = 404 ]
check $? "push_worker answers 404"
! curl -s -b "$JAR" "$BASE/index.php?page=messages" | grep -q 'data-osc-push'
check $? "the messages page prints no form"

echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
