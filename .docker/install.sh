#!/bin/sh
# Shopclass one-command install: writes a Docker Compose stack into a folder and starts it.
#
#   curl -fsSL https://github.com/mindstellar/shopclass/releases/latest/download/install.sh | sh
#   curl -fsSL .../install.sh | sh -s -- --domain=shop.example.com --email=you@example.com
#
# Run it again in the same folder to upgrade. It never overwrites .env or the passwords in it.
set -eu
umask 077

# Replaced with the release version when the release is built.
RELEASE_VERSION="__SHOPCLASS_VERSION__"
IMAGE="ghcr.io/mindstellar/shopclass"

say() { printf '%s\n' "$*"; }
fail() { printf 'Error: %s\n' "$*" >&2; exit 1; }

usage() {
    cat <<'TXT'
Usage: install.sh [options]

  --domain=NAME   Serve the site on this domain with a free HTTPS certificate
                  (Let's Encrypt). The domain must point at this server, and
                  ports 80 and 443 must be open.
  --www=yes|no    Also answer on the www (or bare) form of the domain and
                  send it to --domain. Asked if not given.
  --email=ADDR    Admin e-mail. Let's Encrypt also uses it for expiry notices.
  --url=URL       Site address when there is no domain, e.g. http://203.0.113.5/
  --port=N        Port for the site when there is no domain (default 80).
  --title=TEXT    Site title (default Shopclass).
  --dir=PATH      Folder for the stack (default ./shopclass).
  --version=TAG   Image version, e.g. 6.4.0 (default: this release).
  --yes           Do not ask; use the defaults for anything not given.
  --help          Show this help.
TXT
}

# ask VAR "Question" "default": reads from the terminal, since a piped script has
# itself on stdin. With no terminal, VAR gets the default.
ask() {
    if [ -z "$TTY" ]; then
        eval "$1=\$3"
        return
    fi
    if [ -n "$3" ]; then
        printf '%s [%s]: ' "$2" "$3" >/dev/tty
    else
        printf '%s: ' "$2" >/dev/tty
    fi
    IFS= read -r answer </dev/tty || answer=""
    [ -n "$answer" ] || answer=$3
    eval "$1=\$answer"
}

random() {
    LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c "$1"
}

# plain VALUE NAME: refuse characters that would break .env, YAML or nginx.
plain() {
    case "$1" in
        *[\$\#\'\"\\\`]*) fail "$2 must not contain \$ # ' \" \\ or \`" ;;
    esac
    [ "$(printf '%s' "$1" | tr -d '[:cntrl:]')" = "$1" ] || fail "$2 must be one line of plain text"
}

port_busy() {
    command -v ss >/dev/null 2>&1 || return 1
    ss -ltnH 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]$1\$"
}

stack_exists() {
    [ -n "$(docker volume ls -q --filter "name=^${1}_db-data\$")" ] \
        || [ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$1")" ]
}

# older A B: true when version A is older than B.
older() {
    [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n 1)" = "$1" ]
}

main() {
    DIR="shopclass"
    DOMAIN=""
    ALIAS=""
    WWW=""
    EMAIL=""
    URL=""
    PORT=""
    TITLE="Shopclass"
    VERSION=""
    ASSUME_YES=0

    for arg in "$@"; do
        case "$arg" in
            --domain=*) DOMAIN=${arg#*=} ;;
            --www=*) WWW=${arg#*=} ;;
            --email=*) EMAIL=${arg#*=} ;;
            --url=*) URL=${arg#*=} ;;
            --port=*) PORT=${arg#*=} ;;
            --title=*) TITLE=${arg#*=} ;;
            --dir=*) DIR=${arg#*=} ;;
            --version=*) VERSION=${arg#*=} ;;
            --yes|-y) ASSUME_YES=1 ;;
            --help|-h) usage; exit 0 ;;
            *) usage >&2; fail "unknown option: $arg" ;;
        esac
    done

    TTY=""
    if [ "$ASSUME_YES" -eq 0 ] && (: </dev/tty) 2>/dev/null; then
        TTY=/dev/tty
    fi

    # --- Checks ----------------------------------------------------------------

    command -v docker >/dev/null 2>&1 || fail "Docker is not installed. See https://docs.docker.com/engine/install/"
    docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is not installed. See https://docs.docker.com/compose/install/"
    docker info >/dev/null 2>&1 || fail "cannot talk to Docker. Start Docker, add your user to the docker group, or run this as root."

    if [ -z "$VERSION" ]; then
        case "$RELEASE_VERSION" in
            __*) VERSION=latest ;;
            *) VERSION=$RELEASE_VERSION ;;
        esac
    fi
    printf '%s' "$VERSION" | grep -Eqx '[0-9A-Za-z._-]+' || fail "not a valid version: $VERSION"

    mkdir -p -- "$DIR"
    cd -- "$DIR"

    # --- Upgrade an existing stack ---------------------------------------------

    if [ -f .env ]; then
        current=$(sed -n 's/^SHOPCLASS_VERSION=//p' .env)
        if [ "$VERSION" != latest ] && [ "$current" != latest ] && older "$current" "$VERSION"; then
            say "Upgrading Shopclass $current -> $VERSION."
            sed "s/^SHOPCLASS_VERSION=.*/SHOPCLASS_VERSION=$VERSION/" .env >.env.tmp && mv .env.tmp .env
        else
            say "Shopclass $current is already set up in $(pwd). Starting it."
        fi
        docker compose pull
        docker compose up -d
        say "Done. The stack in $(pwd) is running."
        return 0
    fi

    # --- Stack name ------------------------------------------------------------

    # The Docker project name comes from the folder. Never reuse another stack's data.
    PROJECT=$(basename "$(pwd)" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_\n-' '-')
    if stack_exists "$PROJECT"; then
        n=2
        while stack_exists "$PROJECT-$n"; do n=$((n + 1)); done
        where=$(docker ps -a --filter "label=com.docker.compose.project=$PROJECT" \
            --format '{{.Label "com.docker.compose.project.working_dir"}}' | sort -u | head -n 1)
        running=$(docker ps -q --filter "label=com.docker.compose.project=$PROJECT")
        choice=1
        if [ -n "$TTY" ]; then
            say "Docker already has a stack named '$PROJECT'${where:+, set up in $where}."
            say "  1) Use the new name '$PROJECT-$n' (the old stack stays as it is)"
            say "  2) Replace '$PROJECT': this deletes its database and uploaded files"
            ask choice "Choose" "1"
        fi
        case "$choice" in
            1)
                PROJECT="$PROJECT-$n"
                say "Using the name '$PROJECT'."
                ;;
            2)
                [ -z "$running" ] || fail "'$PROJECT' is running. Stop it first with: cd ${where:-<its folder>} && docker compose down"
                confirm=""
                ask confirm "Type '$PROJECT' to delete it" ""
                [ "$confirm" = "$PROJECT" ] || fail "not confirmed; nothing was deleted."
                ids=$(docker ps -aq --filter "label=com.docker.compose.project=$PROJECT")
                # shellcheck disable=SC2086
                [ -z "$ids" ] || docker rm $ids >/dev/null
                vols=$(docker volume ls -q --filter "label=com.docker.compose.project=$PROJECT")
                # shellcheck disable=SC2086
                [ -z "$vols" ] || docker volume rm $vols >/dev/null
                stack_exists "$PROJECT" && fail "could not delete all of '$PROJECT'. Remove it by hand, or pick option 1."
                say "Deleted the old '$PROJECT' stack."
                ;;
            *) fail "choose 1 or 2" ;;
        esac
    fi

    # --- Questions -------------------------------------------------------------

    if [ -z "$DOMAIN" ] && [ -z "$URL" ]; then
        ask DOMAIN "Domain for HTTPS (leave empty to use an IP address)" ""
    fi
    DOMAIN=$(printf '%s' "$DOMAIN" | sed 's#^https\{0,1\}://##; s#/.*$##' | tr '[:upper:]' '[:lower:]')

    if [ -n "$DOMAIN" ]; then
        printf '%s' "$DOMAIN" | grep -Eqx '[a-z0-9]([a-z0-9.-]*[a-z0-9])?' || fail "not a valid domain: $DOMAIN"
        [ -z "$PORT" ] || fail "--port works only without --domain; HTTPS uses ports 80 and 443."
        [ "$VERSION" = latest ] || ! older "$VERSION" 6.4.0 || fail "HTTPS needs Shopclass 6.4.0 or newer."
        for p in 80 443; do
            ! port_busy "$p" || fail "port $p is already in use. Stop the program that uses it, then run this again."
        done
        URL="https://$DOMAIN/"

        case "$DOMAIN" in
            www.*) ALIAS=${DOMAIN#www.} ;;
            *) ALIAS="www.$DOMAIN" ;;
        esac
        if [ -z "$WWW" ]; then
            # A sub-domain such as shop.example.com rarely has a www form.
            case "$DOMAIN" in
                www.*) WWW=yes ;;
                *.*.*) WWW=no ;;
                *) WWW=yes ;;
            esac
            ask WWW "Also send $ALIAS to $DOMAIN? (yes/no)" "$WWW"
        fi
        case "$WWW" in
            y|Y|yes|YES|Yes) ;;
            n|N|no|NO|No) ALIAS="" ;;
            *) fail "--www takes yes or no" ;;
        esac
    else
        [ -n "$PORT" ] || PORT=80
        case "$PORT" in *[!0-9]*|'') fail "not a valid port: $PORT" ;; esac
        [ "$PORT" -ge 1 ] && [ "$PORT" -le 65535 ] || fail "not a valid port: $PORT"
        ! port_busy "$PORT" || fail "port $PORT is already in use. Pick another with --port=N."
        if [ -z "$URL" ]; then
            ip=$(hostname -I 2>/dev/null | awk '{print $1}') || ip=""
            [ -n "$ip" ] || ip=localhost
            if [ "$PORT" = 80 ]; then guess="http://$ip/"; else guess="http://$ip:$PORT/"; fi
            ask URL "Site address" "$guess"
        fi
        case "$URL" in http://?*|https://?*) ;; *) fail "the site address must start with http:// or https://" ;; esac
        plain "$URL" "the site address"
        case "$URL" in *[[:space:]]*) fail "the site address must not contain spaces" ;; esac
        URL="${URL%/}/"
    fi

    if [ -z "$EMAIL" ]; then
        ask EMAIL "Admin e-mail" ""
    fi
    plain "$EMAIL" "the e-mail"
    printf '%s' "$EMAIL" | grep -Eqx '[^@ ]+@[^@ ]+\.[^@ ]+' || fail "a valid admin e-mail is required (--email=you@example.com)"
    plain "$TITLE" "the title"

    # --- Files -----------------------------------------------------------------

    ADMIN_PASSWORD=$(random 20)

    cat >.env <<ENV
# Shopclass stack settings. Keep this file private: it holds the passwords.
SHOPCLASS_VERSION=$VERSION
SHOPCLASS_URL='$URL'
SHOPCLASS_DOMAIN='$DOMAIN'
SHOPCLASS_REDIRECT_FROM='$ALIAS'
SHOPCLASS_PORT=$PORT
SITE_TITLE='$TITLE'

ADMIN_USER=admin
ADMIN_EMAIL='$EMAIL'
# Used only on the first start. Change it in the admin panel after you sign in.
ADMIN_PASSWORD=$ADMIN_PASSWORD

DB_PASSWORD=$(random 32)
DB_ROOT_PASSWORD=$(random 32)

# Outgoing mail. Set these to send e-mail, then run: docker compose up -d
SMTP_HOST=
SMTP_PORT=587
SMTP_USER=
SMTP_PASSWORD=
SMTP_FROM=
ENV

    if [ -n "$DOMAIN" ]; then
        APP_PORTS='
    ports:
      - "80:80"
      - "443:443"'
        TLS_ENV='
      OSC_TLS_DOMAIN: ${SHOPCLASS_DOMAIN}
      OSC_TLS_REDIRECT_FROM: ${SHOPCLASS_REDIRECT_FROM}'
        TLS_VOLUME='
      - tls:/var/lib/shopclass-tls'
        TLS_VOLUMES='
  tls:'
    else
        APP_PORTS='
    ports:
      - "${SHOPCLASS_PORT}:80"'
        TLS_ENV=""
        TLS_VOLUME=""
        TLS_VOLUMES=""
    fi

    umask 022
    cat >docker-compose.yml <<YAML
# Written by the Shopclass installer. Settings live in .env.
name: $PROJECT

services:
  app:
    image: $IMAGE:\${SHOPCLASS_VERSION}$APP_PORTS
    environment:
      OSC_IGNORE_CONFIG_FILE: "1"
      DB_HOST: db
      DB_NAME: shopclass
      DB_USER: shopclass
      DB_PASSWORD: \${DB_PASSWORD}
      WEB_PATH: \${SHOPCLASS_URL}
      OSC_SITE_TITLE: \${SITE_TITLE}
      OSC_ADMIN_USER: \${ADMIN_USER}
      OSC_ADMIN_EMAIL: \${ADMIN_EMAIL}
      OSC_ADMIN_PASSWORD: \${ADMIN_PASSWORD}
      OSC_MICROCACHE: "1"
      SMTP_HOST: \${SMTP_HOST:-}
      SMTP_PORT: \${SMTP_PORT:-587}
      SMTP_USER: \${SMTP_USER:-}
      SMTP_PASSWORD: \${SMTP_PASSWORD:-}
      SMTP_FROM: \${SMTP_FROM:-}$TLS_ENV
    volumes:
      - uploads:/application/oc-content/uploads
      - downloads:/application/oc-content/downloads
      - plugins:/application/oc-content/plugins
      - themes:/application/oc-content/themes$TLS_VOLUME
    depends_on:
      db:
        condition: service_healthy
    restart: unless-stopped

  db:
    image: mariadb:11
    environment:
      MARIADB_DATABASE: shopclass
      MARIADB_USER: shopclass
      MARIADB_PASSWORD: \${DB_PASSWORD}
      MARIADB_ROOT_PASSWORD: \${DB_ROOT_PASSWORD}
    volumes:
      - db-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 20
    restart: unless-stopped

volumes:
  db-data:
  uploads:
  downloads:
  plugins:
  themes:$TLS_VOLUMES
YAML

    # --- Start -----------------------------------------------------------------

    for name in $DOMAIN $ALIAS; do
        [ -n "$(getent hosts "$name" 2>/dev/null)" ] \
            || say "Warning: $name does not resolve yet. Point its DNS at this server; HTTPS starts once it does."
    done

    say "Starting Shopclass $VERSION in $(pwd)..."
    docker compose pull
    docker compose up -d

    say "Waiting for the site to finish its first start..."
    app=$(docker compose ps -q app)
    tries=0
    until [ "$(docker inspect -f '{{.State.Health.Status}}' "$app" 2>/dev/null)" = "healthy" ]; do
        tries=$((tries + 1))
        [ "$tries" -le 60 ] || fail "the site did not become ready in 5 minutes. See the log: docker compose logs app"
        sleep 5
    done

    cat <<DONE

Shopclass is running.

  Site:      $URL
  Admin:     ${URL}oc-admin/
  User:      admin
  Password:  $ADMIN_PASSWORD

Sign in and change this password now. It is also in $(pwd)/.env.
DONE
    if [ -n "$DOMAIN" ]; then
        say "HTTPS turns on within a minute or two, once Let's Encrypt has checked $DOMAIN."
        say "Progress: docker compose logs -f app | grep tls:"
    fi
    say "To upgrade later, run the same install command again in $(dirname "$(pwd)")."
}

main "$@"
