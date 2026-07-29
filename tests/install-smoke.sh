#!/usr/bin/env bash
#
# This file is part of Shopclass (Mindstellar).
# Copyright (c) 2021-2026 Mindstellar Community
#
# Distributed under the GNU General Public License v3.0 or later. See LICENSE.
#
# SPDX-License-Identifier: GPL-3.0-or-later
#
## Installs a release zip the way a site owner would and checks the result works.
##
## The unit and drift suites run against the repository. A release is not the
## repository: .build.sh archives a ref, overlays the built CSS/JS, drops in a
## theme from another repo and zips the lot. Everything specific to that zip --
## a file the archive missed, a build artefact that never got committed, an
## installer step that only breaks on a clean database -- is invisible to every
## other check, and the first person to find it is whoever downloads the zip.
##
## So this drives the real installer over HTTP against a real database: the
## database step, the admin account, the finish step that writes the
## osclass_installed sentinel, and then signs in. No PHP is included from the
## zip by this script -- it is exercised only through the web server, which is
## the only way the failures above actually show up.
##
## Usage:  tests/install-smoke.sh <path-to-release-zip>
##
## Env:
##   SMOKE_DB_HOST  database host                       [127.0.0.1]
##   SMOKE_DB_PORT  database port                       [3306]
##   SMOKE_DB_USER  user able to create a database      [root]
##   SMOKE_DB_PASS  its password                        [root]
##   SMOKE_DB_NAME  database to create and drop         [shopclass_install_smoke]
##   SMOKE_PORT     port for the throwaway PHP server   [8123]
##
## Exits non-zero on the first failed check, with the stage that failed.

set -euo pipefail

ZIP="${1:-}"
if [ -z "$ZIP" ] || [ ! -f "$ZIP" ]; then
  echo "usage: tests/install-smoke.sh <path-to-release-zip>" >&2
  exit 2
fi
ZIP="$(cd "$(dirname "$ZIP")" && pwd)/$(basename "$ZIP")"

DB_HOST="${SMOKE_DB_HOST:-127.0.0.1}"
DB_PORT="${SMOKE_DB_PORT:-3306}"
DB_USER="${SMOKE_DB_USER:-root}"
DB_PASS="${SMOKE_DB_PASS:-root}"
DB_NAME="${SMOKE_DB_NAME:-shopclass_install_smoke}"
PORT="${SMOKE_PORT:-8123}"

ADMIN_USER="smokeadmin"
ADMIN_PASS="smokepass123"
ADMIN_MAIL="smoke@example.com"
BASE="http://127.0.0.1:${PORT}"

WORK="$(mktemp -d)"
JAR="$WORK/cookies.txt"
SERVER_PID=""

# PHP_CLI_SERVER_WORKERS makes the built-in server fork workers, and those
# outlive a kill aimed at the parent -- leaving the port held, so the next run
# cannot bind. Killing the parent is not enough, so sweep anything still bound
# to this run's port. The pattern carries the port, so it can only ever match
# a server this script started.
# PHP_CLI_SERVER_WORKERS makes the built-in server fork workers. They are
# children of the process $! refers to, and they outlive a kill aimed only at
# their parent -- leaving the port held, so the next run cannot bind. Take the
# children first, by parent pid: matching on the command line instead would
# also match whatever shell invoked this script with the same port in its
# arguments, which is a good way to kill the caller.
cleanup() {
  if [ -n "$SERVER_PID" ]; then
    pkill -TERM -P "$SERVER_PID" 2>/dev/null || true
    kill -TERM "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  rm -rf "$WORK"
}
trap cleanup EXIT

fail() {
  echo "FAIL: $*" >&2
  if [ -f "$WORK/server.log" ]; then
    echo "--- last lines of the PHP server log ---" >&2
    tail -20 "$WORK/server.log" >&2
  fi
  exit 1
}

ok() { echo "  ok  $*"; }

# The database is created and dropped through PHP rather than a mysql client:
# PHP with mysqli is already a hard requirement to run any of this, a client
# binary is not.
db_exec() {
  php -r '
    $h = getenv("H"); $p = (int) getenv("P"); $u = getenv("U");
    $w = getenv("W"); $q = getenv("Q");
    mysqli_report(MYSQLI_REPORT_OFF);
    $c = @new mysqli($h, $u, $w, "", $p);
    if ($c->connect_errno) { fwrite(STDERR, "db connect failed: " . $c->connect_error . "\n"); exit(1); }
    if (!$c->query($q)) { fwrite(STDERR, "query failed: " . $c->error . "\n"); exit(1); }
    exit(0);
  ' 2>&1
}

# ---------------------------------------------------------------------------
echo "==> unpacking $ZIP"
unzip -qq "$ZIP" -d "$WORK/site" || fail "the zip could not be unpacked"

# .build.sh packages a single top-level directory; take whatever it is called
# rather than assuming, so renaming it does not silently break this check.
ROOT="$(find "$WORK/site" -mindepth 1 -maxdepth 1 -type d | head -1)"
[ -n "$ROOT" ] || fail "the zip contains no top-level directory"
[ -f "$ROOT/index.php" ] || fail "no index.php at the top level of the zip"
[ -f "$ROOT/oc-includes/osclass/install.php" ] || fail "the installer is missing from the zip"
ok "unpacked $(basename "$ROOT")"

# The pieces .build.sh overlays rather than archives. Missing here means the
# release was cut without a build, which no other check would notice.
for artefact in \
  oc-admin/themes/modern/css/main.css \
  oc-admin/themes/modern/js/location.min.js \
  oc-includes/assets \
  oc-includes/vendor/autoload.php
do
  [ -e "$ROOT/$artefact" ] || fail "built artefact missing from the zip: $artefact"
done
ok "built artefacts present"

[ ! -f "$ROOT/config.php" ] || fail "the zip ships a config.php, so the installer would refuse a fresh install"
ok "no config.php shipped"

# ---------------------------------------------------------------------------
echo "==> preparing database $DB_NAME"
H="$DB_HOST" P="$DB_PORT" U="$DB_USER" W="$DB_PASS" Q="DROP DATABASE IF EXISTS \`$DB_NAME\`" \
  db_exec || fail "could not drop a previous $DB_NAME"
ok "clean slate"

# ---------------------------------------------------------------------------
echo "==> starting a throwaway PHP server on $PORT"
env PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:${PORT}" -t "$ROOT" >"$WORK/server.log" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 40); do
  if curl -fsS -o /dev/null "${BASE}/oc-includes/osclass/install.php" 2>/dev/null; then break; fi
  sleep 0.25
done
curl -fsS -o /dev/null "${BASE}/oc-includes/osclass/install.php" || fail "the installer never came up"
ok "installer reachable"

# ---------------------------------------------------------------------------
# The installer carries a per-session nonce on every state-changing step, so
# each POST needs the value from the page that precedes it.
nonce_from() {
  grep -oE 'name="install_nonce" value="[^"]*"' "$1" | head -1 | sed 's/.*value="//;s/"//'
}

echo "==> step 2 -> 3: creating the schema"
curl -fsS -c "$JAR" -b "$JAR" "${BASE}/oc-includes/osclass/install.php?step=2" -o "$WORK/step2.html" \
  || fail "step 2 did not render"
NONCE="$(nonce_from "$WORK/step2.html")"
[ -n "$NONCE" ] || fail "no installer nonce on step 2"

# createdb=1 exercises the create-the-database path, which is what most owners
# on shared hosting actually use.
curl -fsS -b "$JAR" -c "$JAR" -X POST "${BASE}/oc-includes/osclass/install.php" \
  -d "step=3" -d "install_nonce=${NONCE}" \
  -d "dbhost=${DB_HOST}:${DB_PORT}" -d "dbname=${DB_NAME}" \
  -d "username=${DB_USER}" -d "password=${DB_PASS}" -d "tableprefix=oc_" \
  -d "createdb=1" -d "admin_username=${DB_USER}" -d "admin_password=${DB_PASS}" \
  -o "$WORK/step3.html" || fail "the database step did not respond"

# The installer re-renders the database form with the message when it fails, and
# moves on to the site-details form when it succeeds. The nonce field on the
# page that comes back is the one the finish step needs.
if grep -qiE 'ins-panel-danger[^>]*>[^<]*[A-Za-z]' "$WORK/step3.html"; then
  grep -oiE 'ins-panel-danger.{0,200}' "$WORK/step3.html" | head -2 >&2
  fail "the database step reported an error"
fi
grep -q 'id="ins-target-form"' "$WORK/step3.html" || fail "the database step did not reach the site-details form"
ok "schema created"

# ---------------------------------------------------------------------------
echo "==> creating the admin account"
NONCE="$(nonce_from "$WORK/step3.html")"
[ -n "$NONCE" ] || fail "no installer nonce on the site-details form"

curl -fsS -b "$JAR" -c "$JAR" -X POST "${BASE}/oc-includes/osclass/install-location.php" \
  -d "install_nonce=${NONCE}" \
  -d "s_name=${ADMIN_USER}" -d "s_passwd=${ADMIN_PASS}" \
  -d "webtitle=Install Smoke Test" -d "email=${ADMIN_MAIL}" \
  -d "skip-location-input=skip" \
  -o "$WORK/finish.json" || fail "the finish endpoint did not respond"

grep -q '"status":true' "$WORK/finish.json" \
  || { cat "$WORK/finish.json" >&2; fail "the finish endpoint did not report success"; }
ok "admin account created"

# ---------------------------------------------------------------------------
# Only this step writes the osclass_installed sentinel, and until it is written
# every page renders "not installed". A release that gets this far and no
# further looks installed and is not.
echo "==> step 4: finalising"
curl -fsS -b "$JAR" -c "$JAR" \
  "${BASE}/oc-includes/osclass/install.php?step=4&install_nonce=${NONCE}&password=${ADMIN_PASS}" \
  -o "$WORK/step4.html" || fail "step 4 did not render"
grep -qi "session expired" "$WORK/step4.html" && fail "step 4 rejected the installer nonce"
ok "finalised"

# ---------------------------------------------------------------------------
echo "==> checking the installed site"

# Follows redirects and takes extra curl arguments, so a signed-in check can
# pass its cookie jar. The admin front door redirects rather than rendering,
# so not following would report a 302 that means nothing either way.
code() {
  local url="$1" out="$2"
  shift 2
  curl -sL -o "$out" -w '%{http_code}' "$@" "$url"
}

[ "$(code "${BASE}/" "$WORK/home.html")" = "200" ] \
  || fail "the front page does not return 200 after installing"
grep -qi "isn't installed yet\|isn&#039;t installed yet" "$WORK/home.html" \
  && fail "the front page still says the site is not installed"
ok "front page serves"

[ "$(code "${BASE}/oc-admin/index.php?page=login" "$WORK/login.html")" = "200" ] \
  || fail "the admin sign-in page does not return 200"
ok "admin sign-in page serves"

# Re-running the installer on an installed site must not offer to install again.
curl -fsS "${BASE}/oc-includes/osclass/install.php" -o "$WORK/reinstall.html" || true
grep -qi "already installed" "$WORK/reinstall.html" \
  || fail "the installer does not recognise the site as already installed"
ok "installer refuses to run twice"

# ---------------------------------------------------------------------------
# Signing in is the end-to-end proof: it reads the admin row the installer
# wrote, verifies the hash it stored, and starts a session.
echo "==> signing in"
rm -f "$JAR"
curl -fsS -c "$JAR" "${BASE}/oc-admin/index.php?page=login" -o "$WORK/login2.html" \
  || fail "the sign-in page did not render"
CSRF_NAME="$(grep -oE "name='CSRFName' value='[^']*'" "$WORK/login2.html" | head -1 | sed "s/.*value='//;s/'//")"
CSRF_TOKEN="$(grep -oE "name='CSRFToken' value='[^']*'" "$WORK/login2.html" | head -1 | sed "s/.*value='//;s/'//")"
[ -n "$CSRF_NAME" ] && [ -n "$CSRF_TOKEN" ] || fail "the sign-in form carried no CSRF token"

LOCATION="$(curl -s -D- -b "$JAR" -c "$JAR" -o /dev/null \
  -d "page=login" -d "action=login_post" \
  -d "user=${ADMIN_USER}" -d "password=${ADMIN_PASS}" -d "locale=en_US" \
  -d "CSRFName=${CSRF_NAME}" -d "CSRFToken=${CSRF_TOKEN}" \
  "${BASE}/oc-admin/index.php" | grep -i '^location:' | tr -d '\r' | sed 's/^[Ll]ocation: *//')"

case "$LOCATION" in
  *oc-admin*) ok "signed in (redirected to $LOCATION)" ;;
  *) fail "signing in did not redirect into the admin (got '${LOCATION:-no redirect}')" ;;
esac

[ "$(code "${BASE}/oc-admin/index.php" "$WORK/dash.html" -b "$JAR")" = "200" ] \
  || fail "the admin did not respond after signing in"
grep -qi "page=login" "$WORK/dash.html" && grep -qi "user_login\|loginform" "$WORK/dash.html" \
  && fail "the admin bounced back to the sign-in form, so the session did not stick"
ok "admin responds to the signed-in session"

# ---------------------------------------------------------------------------
# A PHP warning or notice on any page above would have been rendered into the
# body. None of these pages should produce one.
for page in "$WORK/home.html" "$WORK/login.html" "$WORK/dash.html"; do
  if grep -qiE '(Fatal error|Parse error|Warning:|Notice:|Deprecated:)' "$page"; then
    grep -oiE '(Fatal error|Parse error|Warning:|Notice:|Deprecated:)[^<]{0,120}' "$page" | head -3 >&2
    fail "PHP diagnostics rendered into $(basename "$page")"
  fi
done
ok "no PHP diagnostics on the pages checked"

echo
echo "PASS: the release zip installs and the site works."
