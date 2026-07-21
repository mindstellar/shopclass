# Custom PHP-FPM image for the Shopclass local development stack.
FROM php:8.3-fpm-alpine
LABEL maintainer="navjottomer@gmail.com"

# Run php-fpm workers as your host user so files the app writes onto the
# bind-mounted project (config.php, uploads, downloads, cache) are owned by you
# rather than an in-container user. Override to match your host if it differs:
#   docker compose build --build-arg PUID=$(id -u) --build-arg PGID=$(id -g)
ARG PUID=1000
ARG PGID=1000

# Download the extension installer script.
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions

# System packages + the PHP extensions Shopclass actually uses. (openssl, json,
# ctype, iconv and posix are already in the base image.)
RUN apk add --no-cache \
      coreutils \
      curl \
      git \
      msmtp \
      shadow \
      zip unzip \
    && PHP_EXTENSIONS=" \
      bcmath \
      curl \
      exif \
      fileinfo \
      gd \
      gettext \
      imagick \
      intl \
      mbstring \
      memcached \
      mysqli \
      opcache \
      zip \
    " \
    && install-php-extensions $PHP_EXTENSIONS

# Align the www-data worker with the host user/group.
RUN groupmod -o -g "${PGID}" www-data \
    && usermod  -o -u "${PUID}" www-data

# Route outgoing mail to the Mailhog container so the installer's welcome
# e-mail (and any other mail) is catchable in a browser during development.
RUN printf '%s\n' \
      'defaults' \
      'auto_from on' \
      'tls off' \
      'syslog off' \
      '' \
      'account mailhog' \
      'host mailhog' \
      'port 1025' \
      '' \
      'account default : mailhog' \
      > /etc/msmtprc

# Install Composer.
ENV PATH=$PATH:/root/composer/vendor/bin \
  COMPOSER_ALLOW_SUPERUSER=1 \
  COMPOSER_HOME=/root/composer
RUN cd /root \
  # Download installer and check for its integrity.
  && curl -sSL https://getcomposer.org/installer > composer-setup.php \
  && curl -sSL https://composer.github.io/installer.sha384sum > composer-setup.sha384sum \
  && sha384sum --check composer-setup.sha384sum \
  # Install Composer 2.
  && php composer-setup.php --install-dir=/usr/local/bin --filename=composer --2 \
  # Remove installer files.
  && rm /root/composer-setup.php /root/composer-setup.sha384sum
CMD ["php-fpm"]
