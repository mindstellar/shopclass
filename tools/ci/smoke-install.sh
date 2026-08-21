#!/usr/bin/env bash
#
# This file is part of Shopclass (Mindstellar).
# Copyright (c) 2021-2026 Mindstellar Community
#
# Distributed under the GNU General Public License v3.0 or later. See LICENSE.
#
# SPDX-License-Identifier: GPL-3.0-or-later
#
## Smoke-installs a plugin or theme package against a real core container
## (docs/MARKET.md §6.5): boots MariaDB + the prod core image, lets the image's
## own entrypoint self-provision, drops the package (and a deprecation-collector
## instrumentation plugin) in, and drives the real admin HTTP flow — install,
## enable, dashboard, configure route, public pages, disable, uninstall for a
## plugin; activate + render for a theme.
##
## Runs from an extracted release bundle with no repository checkout: it shells
## out only to docker, curl, and standard POSIX tools.
##
## Usage:
##   smoke-install.sh --type=plugin|theme --slug=<slug> --path=<dir> \
##                     --image=<docker-image> --out=<result.json>
##
## Exit: 0 on a clean run (result.ok == true), non-zero if anything fatal was
## found. Always writes --out, even on failure, so the caller has a report.

set -euo pipefail

# ---------------------------------------------------------------------------
# Args
# ---------------------------------------------------------------------------
TYPE=""
SLUG=""
PKG_PATH=""
IMAGE=""
OUT=""

for arg in "$@"; do
  case "$arg" in
    --type=*) TYPE="${arg#--type=}" ;;
    --slug=*) SLUG="${arg#--slug=}" ;;
    --path=*) PKG_PATH="${arg#--path=}" ;;
    --image=*) IMAGE="${arg#--image=}" ;;
    --out=*) OUT="${arg#--out=}" ;;
    -h|--help)
      echo "Usage: smoke-install.sh --type=plugin|theme --slug=<slug> --path=<dir> --image=<image> --out=<result.json>" >&2
      exit 0
      ;;
    *)
      echo "Unknown option: $arg" >&2
      exit 2
      ;;
  esac
done

if [ "$TYPE" != "plugin" ] && [ "$TYPE" != "theme" ]; then
  echo "smoke-install: --type must be 'plugin' or 'theme'" >&2
  exit 2
fi
if [ -z "$SLUG" ] || [ -z "$PKG_PATH" ] || [ -z "$IMAGE" ] || [ -z "$OUT" ]; then
  echo "smoke-install: --slug, --path, --image and --out are all required" >&2
  exit 2
fi
if [ ! -d "$PKG_PATH" ]; then
  echo "smoke-install: not a directory: $PKG_PATH" >&2
  exit 2
fi
PKG_PATH="$(cd "$PKG_PATH" && pwd)"

command -v docker >/dev/null 2>&1 || { echo "smoke-install: docker is required" >&2; exit 2; }
command -v curl >/dev/null 2>&1 || { echo "smoke-install: curl is required" >&2; exit 2; }

# ---------------------------------------------------------------------------
# Working state
# ---------------------------------------------------------------------------
RUN_ID="smoke-$(date +%s)-$$"
NET="net-${RUN_ID}"
DB_CONTAINER="db-${RUN_ID}"
APP_CONTAINER="app-${RUN_ID}"
WORK="$(mktemp -d)"
JAR="${WORK}/cookies.txt"

DB_NAME="shopclass_smoke"
DB_USER="shopclass"
DB_PASSWORD="smoke-$(head -c8 /dev/urandom | od -An -tx1 | tr -d ' \n')"
DB_ROOT_PASSWORD="root-$(head -c8 /dev/urandom | od -An -tx1 | tr -d ' \n')"
ADMIN_USER="admin"
ADMIN_PASSWORD="Smoke-$(head -c8 /dev/urandom | od -An -tx1 | tr -d ' \n')1!"
ADMIN_EMAIL="admin@example.com"
DEPRECATION_LOG_IN_CONTAINER="/application/oc-content/downloads/deprecation-log.jsonl"

# steps[] accumulated as "name<TAB>ok<TAB>detail" lines, written out as JSON at the end.
STEPS_FILE="${WORK}/steps.tsv"
: > "$STEPS_FILE"
FATALS_FILE="${WORK}/fatals.txt"
: > "$FATALS_FILE"
UNEXPECTED_OUTPUT=""
NETWORK_CREATED=0
DB_STARTED=0
APP_STARTED=0

record_step() {
  # $1 name  $2 ok(0|1)  $3 detail (may be empty, must not contain a tab/newline)
  local detail
  detail="$(printf '%s' "${3:-}" | tr '\t\n\r' '   ')"
  printf '%s\t%s\t%s\n' "$1" "$2" "$detail" >> "$STEPS_FILE"
  if [ "$2" != "1" ]; then
    echo "smoke-install: FAILED step: $1 ${detail:+-- $detail}" >&2
  else
    echo "smoke-install: ok   step: $1" >&2
  fi
}

record_fatal() {
  printf '%s\n' "$1" >> "$FATALS_FILE"
}

# ---------------------------------------------------------------------------
# Cleanup — every exit path, including a failure mid-setup.
# ---------------------------------------------------------------------------
# shellcheck disable=SC2329  # invoked indirectly, via the EXIT trap below.
cleanup() {
  local rc=$?
  set +e
  if [ "$APP_STARTED" = "1" ]; then
    docker rm -f "$APP_CONTAINER" >/dev/null 2>&1
  fi
  if [ "$DB_STARTED" = "1" ]; then
    docker rm -f "$DB_CONTAINER" >/dev/null 2>&1
  fi
  if [ "$NETWORK_CREATED" = "1" ]; then
    docker network rm "$NET" >/dev/null 2>&1
  fi
  rm -rf "$WORK"
  exit "$rc"
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# JSON helpers (no jq dependency — this runs from a bare extracted bundle)
# ---------------------------------------------------------------------------
json_escape() {
  # Escapes a string for embedding inside a JSON string literal.
  local s="$1"
  s="${s//\\/\\\\}"
  s="${s//\"/\\\"}"
  s="$(printf '%s' "$s" | tr '\n' ' ' | tr '\r' ' ')"
  printf '%s' "$s"
}

write_result() {
  local ok_bool="$1"
  {
    printf '{\n'
    printf '  "ok": %s,\n' "$ok_bool"

    printf '  "fatals": ['
    local first=1
    while IFS= read -r line; do
      [ -z "$line" ] && continue
      [ "$first" = "1" ] || printf ','
      first=0
      printf '\n    "%s"' "$(json_escape "$line")"
    done < "$FATALS_FILE"
    [ "$first" = "0" ] && printf '\n  '
    printf '],\n'

    printf '  "unexpected_output": "%s",\n' "$(json_escape "$UNEXPECTED_OUTPUT")"

    printf '  "deprecations": ['
    if [ -s "${WORK}/deprecations.jsonl" ]; then
      first=1
      while IFS= read -r line; do
        [ -z "$line" ] && continue
        [ "$first" = "1" ] || printf ','
        first=0
        printf '\n    %s' "$line"
      done < "${WORK}/deprecations.jsonl"
      printf '\n  '
    fi
    printf '],\n'

    printf '  "tables_left": ['
    if [ -s "${WORK}/tables_left.txt" ]; then
      first=1
      while IFS= read -r t; do
        [ -z "$t" ] && continue
        [ "$first" = "1" ] || printf ','
        first=0
        printf '\n    "%s"' "$(json_escape "$t")"
      done < "${WORK}/tables_left.txt"
      printf '\n  '
    fi
    printf '],\n'

    printf '  "prefs_left": ['
    if [ -s "${WORK}/prefs_left.txt" ]; then
      first=1
      while IFS= read -r p; do
        [ -z "$p" ] && continue
        [ "$first" = "1" ] || printf ','
        first=0
        printf '\n    "%s"' "$(json_escape "$p")"
      done < "${WORK}/prefs_left.txt"
      printf '\n  '
    fi
    printf '],\n'

    printf '  "steps": ['
    first=1
    while IFS=$'\t' read -r name ok detail; do
      [ -z "$name" ] && continue
      [ "$first" = "1" ] || printf ','
      first=0
      local ok_json="false"
      [ "$ok" = "1" ] && ok_json="true"
      printf '\n    {"name": "%s", "ok": %s, "detail": "%s"}' "$(json_escape "$name")" "$ok_json" "$(json_escape "$detail")"
    done < "$STEPS_FILE"
    [ "$first" = "0" ] && printf '\n  '
    printf ']\n'
    printf '}\n'
  } > "$OUT"
}

# ---------------------------------------------------------------------------
# Network + database
# ---------------------------------------------------------------------------
echo "==> creating network $NET" >&2
docker network create "$NET" >/dev/null
NETWORK_CREATED=1

echo "==> starting database" >&2
docker run -d --name "$DB_CONTAINER" --network "$NET" \
  -e "MARIADB_DATABASE=${DB_NAME}" \
  -e "MARIADB_USER=${DB_USER}" \
  -e "MARIADB_PASSWORD=${DB_PASSWORD}" \
  -e "MARIADB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}" \
  mariadb:11 >/dev/null
DB_STARTED=1

db_ready=0
for _ in $(seq 1 60); do
  if docker exec "$DB_CONTAINER" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then
    db_ready=1
    break
  fi
  sleep 2
done
if [ "$db_ready" = "1" ]; then
  record_step "start database" 1 ""
else
  record_step "start database" 0 "mariadb did not become healthy in time"
  write_result "false"
  exit 1
fi

db_query() {
  # $1 = SQL. Runs as root against $DB_NAME.
  docker exec -e "MYSQL_PWD=${DB_ROOT_PASSWORD}" "$DB_CONTAINER" \
    mariadb -uroot -N -B -e "$1" "$DB_NAME" 2>/dev/null
}

# ---------------------------------------------------------------------------
# App container — the image's own entrypoint self-provisions from these env
# vars (php oc-cli.php install --unattended), then starts nginx+php-fpm.
# ---------------------------------------------------------------------------
echo "==> starting core ($IMAGE)" >&2
# The production image ships `output_buffering = 0` (no implicit top-level
# buffer), which means Plugins::install()'s `ob_get_length() > 0` check — the
# mechanism this harness relies on to catch a package that echoes stray output
# at install time — can never see anything to measure. Bind-mounting an ini
# override that turns buffering on for this one throwaway container (not the
# image, not any real deployment) is enough to make that existing core check
# actually fire during the smoke test.
PHP_INI_OVERRIDE="${WORK}/zz-smoke-output-buffering.ini"
echo "output_buffering = 4096" > "$PHP_INI_OVERRIDE"

# WEB_PATH must match the host:port curl actually reaches — core echoes it
# verbatim into every absolute URL and Location header it generates (e.g.
# admin action redirects), and those are followed with `curl -L` below. The
# published port has to be known before the container starts (WEB_PATH is
# baked in at install time), so it is chosen here and handed to docker's `-p`
# rather than discovered afterwards with `docker port`; a handful of retries
# absorbs the small chance of colliding with something else already bound.
HOST_PORT=""
for _ in $(seq 1 8); do
  TRY_PORT=$(( (RANDOM % 20000) + 20000 ))
  if docker run -d --name "$APP_CONTAINER" --network "$NET" \
    -p "127.0.0.1:${TRY_PORT}:80" \
    -v "${PHP_INI_OVERRIDE}:/usr/local/etc/php/conf.d/zz-smoke-output-buffering.ini:ro" \
    -e OSC_IGNORE_CONFIG_FILE=1 \
    -e DB_HOST="$DB_CONTAINER" \
    -e DB_PORT=3306 \
    -e DB_NAME="$DB_NAME" \
    -e DB_USER="$DB_USER" \
    -e DB_PASSWORD="$DB_PASSWORD" \
    -e DB_TABLE_PREFIX=oc_ \
    -e WEB_PATH="http://127.0.0.1:${TRY_PORT}/" \
    -e OSC_SITE_TITLE="Package CI smoke test" \
    -e OSC_LOCALE=en_US \
    -e OSC_ADMIN_USER="$ADMIN_USER" \
    -e OSC_ADMIN_EMAIL="$ADMIN_EMAIL" \
    -e OSC_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
    -e OSC_DEPRECATION_LOG="$DEPRECATION_LOG_IN_CONTAINER" \
    "$IMAGE" >/dev/null 2>"${WORK}/apprun.err"; then
    HOST_PORT="$TRY_PORT"
    APP_STARTED=1
    break
  fi
  docker rm -f "$APP_CONTAINER" >/dev/null 2>&1 || true
done
if [ -z "$HOST_PORT" ]; then
  record_step "start core" 0 "could not publish a host port after 8 attempts: $(cat "${WORK}/apprun.err" 2>/dev/null)"
  write_result "false"
  exit 1
fi
BASE="http://127.0.0.1:${HOST_PORT}"
ADMIN="${BASE}/oc-admin/index.php"

echo "==> waiting for core to answer on ${BASE}" >&2
app_ready=0
for _ in $(seq 1 90); do
  code="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/" || true)"
  case "$code" in
    200|301|302|303|307|308) app_ready=1; break ;;
  esac
  sleep 2
done
if [ "$app_ready" = "1" ]; then
  record_step "install core" 1 ""
else
  install_log="$(docker logs "$APP_CONTAINER" 2>&1 | tail -60 || true)"
  record_step "install core" 0 "core never answered a request; last log lines: ${install_log}"
  write_result "false"
  exit 1
fi

# ---------------------------------------------------------------------------
# HTTP helpers
# ---------------------------------------------------------------------------
urlenc() {
  # Pure-shell percent-encoding — no network round trip (curl needs a real,
  # reachable URL to report %{url_effective}, which a throwaway "" target does
  # not give it) and no dependency beyond bash itself.
  local string="$1" length pos c hex encoded=""
  length=${#string}
  for (( pos = 0; pos < length; pos++ )); do
    c="${string:pos:1}"
    case "$c" in
      [a-zA-Z0-9.~_-]) encoded+="$c" ;;
      *)
        printf -v hex '%02X' "'$c"
        encoded+="%${hex}"
        ;;
    esac
  done
  printf '%s' "$encoded"
}

http_get() {
  # $1 = path (relative to $BASE). Writes the final rendered body to
  # $WORK/last.html (following redirects — core's admin actions all
  # redirect-then-render, per Utils::redirectTo()) and prints the final
  # status code.
  curl -sL -b "$JAR" -c "$JAR" -o "${WORK}/last.html" -w '%{http_code}' "${BASE}${1}"
}

assert_2xx_3xx() {
  # $1 = step name  $2 = path
  local code
  code="$(http_get "$2")"
  case "$code" in
    2??|3??) record_step "$1" 1 "HTTP $code" ;;
    *) record_step "$1" 0 "HTTP $code from ${2}" ;;
  esac
}

# ---------------------------------------------------------------------------
# Copy the package + the deprecation collector into the container
# ---------------------------------------------------------------------------
if [ "$TYPE" = "plugin" ]; then
  DEST_KIND="plugins"
else
  DEST_KIND="themes"
fi

echo "==> copying package into the container" >&2
if docker cp "${PKG_PATH}/." "${APP_CONTAINER}:/application/oc-content/${DEST_KIND}/${SLUG}" 2>"${WORK}/cp.err"; then
  record_step "copy package into container" 1 ""
else
  record_step "copy package into container" 0 "$(cat "${WORK}/cp.err")"
  write_result "false"
  exit 1
fi

echo "==> installing the deprecation collector" >&2
COLLECTOR_SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/deprecation-collector" && pwd)"
if docker cp "${COLLECTOR_SRC}/." "${APP_CONTAINER}:/application/oc-content/plugins/deprecation-collector" 2>"${WORK}/cp2.err"; then
  record_step "copy deprecation collector into container" 1 ""
else
  record_step "copy deprecation collector into container" 0 "$(cat "${WORK}/cp2.err")"
fi

# ---------------------------------------------------------------------------
# Admin login. The stateless CSRF token issued for one authenticated page load
# is valid (bound to this admin session, 2h lifetime — mindstellar\Csrf) for
# every subsequent admin request in this run, so it is scraped once and reused
# rather than re-scraped before each action.
# ---------------------------------------------------------------------------
scrape_csrf() {
  # Reads $WORK/last.html, sets CSRF_NAME / CSRF_TOKEN. Empty on no match.
  # mindstellar\Csrf::replaceForms() injects hidden `name='CSRFName'
  # value='...'` fields into every <form> lacking class="nocsrf"; a page with
  # no such form (e.g. the admin dashboard) still carries the same token,
  # because osc_csrf_token_url() emits it inline as a `CSRFName=...&
  # CSRFToken=...` query string wherever admin JS builds an action URL. Try
  # the form-field shape first, then fall back to the query-string shape.
  # `|| true` on every one of these: with `pipefail`, grep finding no match
  # (the expected outcome half the time — a page carries one shape or the
  # other, never both) would otherwise trip `set -e` on the assignment.
  CSRF_NAME="$(grep -oE "name='CSRFName' value='[^']*'" "${WORK}/last.html" | head -1 | sed "s/.*value='//;s/'$//" || true)"
  CSRF_TOKEN="$(grep -oE "name='CSRFToken' value='[^']*'" "${WORK}/last.html" | head -1 | sed "s/.*value='//;s/'$//" || true)"
  if [ -z "$CSRF_NAME" ] || [ -z "$CSRF_TOKEN" ]; then
    CSRF_NAME="$(grep -oE "CSRFName=[A-Za-z0-9_-]+" "${WORK}/last.html" | head -1 | sed "s/CSRFName=//" || true)"
    CSRF_TOKEN="$(grep -oE "CSRFToken=[A-Za-z0-9_-]+" "${WORK}/last.html" | head -1 | sed "s/CSRFToken=//" || true)"
  fi
}

echo "==> logging in to oc-admin" >&2
http_get "/oc-admin/index.php?page=login" >/dev/null
scrape_csrf
if [ -z "$CSRF_NAME" ] || [ -z "$CSRF_TOKEN" ]; then
  record_step "admin login" 0 "no CSRF token found on the login page"
  write_result "false"
  exit 1
fi

login_code="$(curl -s -o "${WORK}/last.html" -w '%{http_code}' -b "$JAR" -c "$JAR" \
  --data-urlencode "page=login" \
  --data-urlencode "action=login_post" \
  --data-urlencode "user=${ADMIN_USER}" \
  --data-urlencode "password=${ADMIN_PASSWORD}" \
  --data-urlencode "locale=en_US" \
  --data-urlencode "remember=1" \
  --data-urlencode "CSRFName=${CSRF_NAME}" \
  --data-urlencode "CSRFToken=${CSRF_TOKEN}" \
  "$ADMIN")"

# Confirm the session actually carries an authenticated admin, rather than
# trusting the redirect status alone (a rejected login also redirects, back to
# the login form).
http_get "/oc-admin/index.php" >/dev/null
if grep -q 'id="loginform"' "${WORK}/last.html"; then
  record_step "admin login" 0 "still on the login form after login_post (HTTP ${login_code})"
  write_result "false"
  exit 1
fi
record_step "admin login" 1 ""
scrape_csrf
if [ -z "$CSRF_NAME" ] || [ -z "$CSRF_TOKEN" ]; then
  record_step "reuse admin CSRF token" 0 "no CSRF token found on the authenticated dashboard"
  write_result "false"
  exit 1
fi
CSRF_QS="CSRFName=$(urlenc "$CSRF_NAME")&CSRFToken=$(urlenc "$CSRF_TOKEN")"

# ---------------------------------------------------------------------------
# Install + enable the deprecation collector through the same admin flow used
# for the package under test — copying its files in is not enough, a plugin
# only runs once it is both installed and active (Plugins::loadActive()).
# ---------------------------------------------------------------------------
echo "==> enabling the deprecation collector" >&2
COLLECTOR_PATH_ENC="$(urlenc "deprecation-collector/index.php")"
code="$(http_get "/oc-admin/index.php?page=plugins&action=install&plugin=${COLLECTOR_PATH_ENC}&${CSRF_QS}")"
case "$code" in
  2??|3??) record_step "install deprecation collector" 1 "HTTP $code" ;;
  *) record_step "install deprecation collector" 0 "HTTP $code" ;;
esac
code="$(http_get "/oc-admin/index.php?page=plugins&action=enable&plugin=${COLLECTOR_PATH_ENC}&${CSRF_QS}")"
case "$code" in
  2??|3??) record_step "enable deprecation collector" 1 "HTTP $code" ;;
  *) record_step "enable deprecation collector" 0 "HTTP $code" ;;
esac

# ---------------------------------------------------------------------------
# Settle core's own lazy state, then snapshot the schema + preferences before
# the package touches anything.
# ---------------------------------------------------------------------------
# Not every core preference is written at install time. Some are created the
# first time something needs them — rendering the search page mints a
# search-alert token, and that writes alert_private_key and alert_public_key.
# The pages visited during the lifecycle below therefore produced those rows
# *between* the two snapshots, and the diff attributed them to the package: every
# submission was reported as leaving behind preferences it had never created,
# on a gate whose whole job is to notice exactly that. Visiting the same public
# routes first moves those writes into the baseline, so what the diff reports is
# the package's own doing.
#
# Deliberately the same routes the lifecycle uses, and deliberately quiet: a
# failure here is not the package's problem, and the assertions that do matter
# run against these URLs again once it is installed.
for warm_path in "/index.php" "/index.php?page=search" "/index.php?page=item&id=1" "/index.php?page=contact"; do
  http_get "$warm_path" >/dev/null 2>&1 || true
done

db_query "SHOW TABLES" | sort > "${WORK}/tables_before.txt"
db_query "SELECT s_name FROM oc_t_preference" | sort > "${WORK}/prefs_before.txt"

# ---------------------------------------------------------------------------
# Plugin or theme lifecycle
# ---------------------------------------------------------------------------
if [ "$TYPE" = "plugin" ]; then
  PLUGIN_PATH="${SLUG}/index.php"
  PLUGIN_PATH_ENC="$(urlenc "$PLUGIN_PATH")"

  echo "==> install" >&2
  code="$(http_get "/oc-admin/index.php?page=plugins&action=install&plugin=${PLUGIN_PATH_ENC}&${CSRF_QS}")"
  # Plugins::install() classifies any output the plugin generated at include time
  # as 'error_output' and core renders it back as a flash message on the plugins
  # list this redirects to.
  if grep -qo 'unexpected output</strong> during the installation' "${WORK}/last.html"; then
    UNEXPECTED_OUTPUT="$(grep -oE 'Output: "[^"]*"' "${WORK}/last.html" | head -1 | sed 's/^Output: "//;s/"$//' || true)"
    record_step "install plugin" 0 "unexpected output during install"
  else
    case "$code" in
      2??|3??) record_step "install plugin" 1 "HTTP $code" ;;
      *) record_step "install plugin" 0 "HTTP $code" ;;
    esac
  fi

  echo "==> enable" >&2
  code="$(http_get "/oc-admin/index.php?page=plugins&action=enable&plugin=${PLUGIN_PATH_ENC}&${CSRF_QS}")"
  case "$code" in
    2??|3??) record_step "enable plugin" 1 "HTTP $code" ;;
    *) record_step "enable plugin" 0 "HTTP $code" ;;
  esac

  assert_2xx_3xx "load admin dashboard" "/oc-admin/index.php"

  if grep -qE "_configure" "${PKG_PATH}/index.php" 2>/dev/null; then
    assert_2xx_3xx "load plugin configure route" "/oc-admin/index.php?page=plugins&action=admin&plugin=${PLUGIN_PATH_ENC}"
  fi

  assert_2xx_3xx "load public home" "/index.php"
  assert_2xx_3xx "load a listing page" "/index.php?page=search"

  echo "==> disable" >&2
  code="$(http_get "/oc-admin/index.php?page=plugins&action=disable&plugin=${PLUGIN_PATH_ENC}&${CSRF_QS}")"
  case "$code" in
    2??|3??) record_step "disable plugin" 1 "HTTP $code" ;;
    *) record_step "disable plugin" 0 "HTTP $code" ;;
  esac

  echo "==> uninstall" >&2
  code="$(http_get "/oc-admin/index.php?page=plugins&action=uninstall&plugin=${PLUGIN_PATH_ENC}&${CSRF_QS}")"
  case "$code" in
    2??|3??) record_step "uninstall plugin" 1 "HTTP $code" ;;
    *) record_step "uninstall plugin" 0 "HTTP $code" ;;
  esac
else
  SLUG_ENC="$(urlenc "$SLUG")"

  echo "==> activate theme" >&2
  code="$(http_get "/oc-admin/index.php?page=appearance&action=activate&theme=${SLUG_ENC}&${CSRF_QS}")"
  case "$code" in
    2??|3??) record_step "activate theme" 1 "HTTP $code" ;;
    *) record_step "activate theme" 0 "HTTP $code" ;;
  esac

  assert_2xx_3xx "render home" "/index.php"
  assert_2xx_3xx "render search" "/index.php?page=search"
  assert_2xx_3xx "render an item page" "/index.php?page=item&id=1"
  assert_2xx_3xx "render contact" "/index.php?page=contact"

  # A fresh install has no public user at all — visiting any profile ID would
  # 404 regardless of which theme is active, which is correct behaviour for a
  # missing resource but tells us nothing about the theme. Seed one directly so
  # "one user page" actually exercises the theme's profile template.
  db_query "INSERT INTO oc_t_user (dt_reg_date, s_name, s_username, s_password, s_email, b_enabled, b_active, dt_access_date, s_access_ip) VALUES (NOW(), 'Smoke Test User', 'smoke-test-user', '\$2y\$12\$abcdefghijklmnopqrstuuVWuNjNSXhVeNz7iN.4lTz9dK4l1S9Uy', 'smoke-user@example.com', 1, 1, NOW(), '127.0.0.1')" >/dev/null || true
  SEED_USER_ID="$(db_query "SELECT pk_i_id FROM oc_t_user WHERE s_username='smoke-test-user'" || true)"
  if [ -n "$SEED_USER_ID" ]; then
    assert_2xx_3xx "render a user page" "/index.php?page=user&action=pub_profile&id=${SEED_USER_ID}"
  else
    record_step "render a user page" 0 "could not seed a public user to visit"
  fi
fi

# ---------------------------------------------------------------------------
# Fatal / parse error detection. The prod image never shows a raw PHP error to
# the browser — oc-includes/osclass/classes/logger/OsclassErrors.php catches
# every uncaught exception and true fatal and renders a branded error page
# instead — so a response body will not contain the words "Fatal error". What
# it does, unconditionally, is `error_log()` the failure with one of two fixed
# prefixes ("Shopclass error: " for an uncaught exception, "Shopclass fatal: "
# for a shutdown-time E_ERROR/E_PARSE/E_CORE_ERROR/E_COMPILE_ERROR), which
# lands in the container's stdout (supervisord routes php-fpm's own stream
# there). The literal "Fatal error"/"Parse error" strings are still checked as
# a fallback in case OSC_DEBUG or a different error-handling config is in play.
# ---------------------------------------------------------------------------
if docker logs "$APP_CONTAINER" 2>&1 | grep -E 'Shopclass error:|Shopclass fatal:|Fatal error|Parse error' > "${WORK}/log-fatals.txt"; then
  while IFS= read -r line; do
    record_fatal "$line"
  done < "${WORK}/log-fatals.txt"
fi

# ---------------------------------------------------------------------------
# Snapshot after, diff, and collect the deprecation collector's log.
# ---------------------------------------------------------------------------
db_query "SHOW TABLES" | sort > "${WORK}/tables_after.txt"
db_query "SELECT s_name FROM oc_t_preference" | sort > "${WORK}/prefs_after.txt"
comm -13 "${WORK}/tables_before.txt" "${WORK}/tables_after.txt" > "${WORK}/tables_left.txt" || true
comm -13 "${WORK}/prefs_before.txt" "${WORK}/prefs_after.txt" > "${WORK}/prefs_left.txt" || true

if docker exec "$APP_CONTAINER" cat "$DEPRECATION_LOG_IN_CONTAINER" > "${WORK}/deprecations.raw.jsonl" 2>/dev/null; then
  # Rewrite the collector's in-container absolute path down to the same
  # package-relative shape deprecation-scan.php's own "file" values use
  # (e.g. "index.php", not "/application/oc-content/plugins/<slug>/index.php"),
  # so annotate.php's --path-prefix applies uniformly to both.
  sed "s#/application/oc-content/${DEST_KIND}/${SLUG}/##g" "${WORK}/deprecations.raw.jsonl" > "${WORK}/deprecations.jsonl"
else
  : > "${WORK}/deprecations.jsonl"
fi

# ---------------------------------------------------------------------------
# Final verdict
# ---------------------------------------------------------------------------
OK="true"
if [ -s "$FATALS_FILE" ]; then
  OK="false"
fi
if [ -n "$UNEXPECTED_OUTPUT" ]; then
  OK="false"
fi
while IFS=$'\t' read -r _name ok _detail; do
  [ -z "$_name" ] && continue
  if [ "$ok" != "1" ]; then
    OK="false"
  fi
done < "$STEPS_FILE"

write_result "$OK"

if [ "$OK" = "true" ]; then
  exit 0
else
  exit 1
fi
