# Production image for Shopclass — a single self-contained container (nginx +
# php-fpm + supervisord) that a one-click platform, PaaS or `docker run` can boot.
# The app self-provisions on first start from environment variables (see
# .docker/prod/entrypoint.sh); no interactive installer step.
#
#   docker build -t shopclass .
#   docker run -p 8080:80 --env-file prod.env shopclass
#
# vendor/ and oc-includes/assets/ are committed, so no composer/npm build is
# needed here; the storefront default theme lives in its own repo and is bundled
# below (STOREFRONT_VERSION defaults to its latest release).
FROM php:8.3-fpm-alpine

LABEL org.opencontainers.image.title="Shopclass" \
      org.opencontainers.image.description="Self-hosted PHP classifieds CMS" \
      org.opencontainers.image.source="https://github.com/mindstellar/shopclass"

# System packages + a lightweight web/process layer. curl is used by the app and
# the entrypoint's health/DB waits. msmtp is a send-only SMTP client: the image
# bundles no MTA, so PHP mail() relays through it to a smarthost the entrypoint
# configures from the environment (ca-certificates backs its TLS trust).
RUN apk add --no-cache nginx supervisor curl unzip tzdata msmtp ca-certificates

# PHP extensions Shopclass uses in production (superset of composer's ext-*
# requires, plus opcache and the memcached object-cache driver). imagick is left
# out to keep the image lean — the app's image handling works on gd.
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions \
        bcmath curl exif fileinfo gd gettext intl mbstring memcached mysqli opcache zip \
    && rm /usr/local/bin/install-php-extensions

# Runtime PHP config: production OPcache + a larger upload ceiling. clear_env=no
# lets php-fpm workers see the container environment (DB_*, WEB_PATH, ...) via
# getenv(), so the app can be configured entirely from the environment.
COPY .docker/prod/opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini
COPY .docker/prod/php.ini      /usr/local/etc/php/conf.d/99-shopclass.ini
RUN printf '[www]\nclear_env = no\n' > /usr/local/etc/php-fpm.d/zz-clear-env.conf

WORKDIR /application
COPY . /application

# Bundle the storefront default theme from its own repository (themes are not
# tracked in this repo). Pin with --build-arg STOREFRONT_VERSION=vX.Y.Z if needed.
ARG STOREFRONT_VERSION=latest
RUN set -eu; \
    if [ "$STOREFRONT_VERSION" = "latest" ]; then \
        THEME_URL=$(curl -fsSL https://api.github.com/repos/mindstellar/theme-storefront/releases/latest \
            | grep 'browser_download_url' | grep -o 'https://[^"]*\.zip' | head -1); \
    else \
        THEME_URL="https://github.com/mindstellar/theme-storefront/releases/download/${STOREFRONT_VERSION}/storefront_${STOREFRONT_VERSION#v}.zip"; \
    fi; \
    [ -n "$THEME_URL" ] || { echo "could not resolve storefront theme download url" >&2; exit 1; }; \
    rm -rf /application/oc-content/themes/storefront; \
    curl -fsSL -o /tmp/storefront.zip "$THEME_URL"; \
    unzip -qq /tmp/storefront.zip -d /application/oc-content/themes/; \
    rm -f /tmp/storefront.zip

# Writable runtime dirs and ownership. Only oc-content (uploads/downloads/cache)
# is written at runtime; the rest of the tree can stay read-only.
RUN mkdir -p /application/oc-content/uploads /application/oc-content/downloads \
             /run/nginx /var/log/supervisor \
    && chown -R www-data:www-data /application/oc-content \
    && chmod +x /application/.docker/prod/entrypoint.sh /application/.docker/prod/healthcheck.sh

COPY .docker/prod/nginx.conf      /etc/nginx/nginx.conf
COPY .docker/prod/supervisord.conf /etc/supervisord.conf
# Empty default so nginx's `include /etc/nginx/real_ip.conf` is a no-op until the
# entrypoint (re)generates it from OSC_REAL_IP_HEADER.
RUN : > /etc/nginx/real_ip.conf

# Configure entirely from the environment by default: ignore any config.php and
# read DB settings from DB_* / WEB_PATH. Override per-deploy as needed.
# OSC_DISABLE_SELF_UPDATE turns off the admin's file-writing self-updater: the
# code is baked into this image, so updates come from deploying a newer image tag
# (the entrypoint's db:upgrade migrates the schema), not from writing into a
# running container.
ENV OSC_IGNORE_CONFIG_FILE=1 \
    OSC_DISABLE_SELF_UPDATE=1

EXPOSE 80

# Liveness/readiness: see .docker/prod/healthcheck.sh (200 once installed, 3xx
# otherwise — both mean the web stack is up; a DB outage 5xx fails it).
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD ["/application/.docker/prod/healthcheck.sh"]

ENTRYPOINT ["/application/.docker/prod/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
