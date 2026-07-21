# Custom PHP-FPM image with PHP extensions and Composer
FROM php:8.3-fpm-alpine
LABEL maintainer="navjottomer@gmail.com"

# Download script to install PHP extensions and dependencies
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions

RUN apk add --no-cache \
      coreutils \
      curl \
      git \
      msmtp \
      zip unzip \
# iconv, mbstring and pdo_sqlite are omitted as they are already installed
    && PHP_EXTENSIONS=" \
      amqp \
      bcmath \
      bz2 \
      curl \
      calendar \
      event \
      exif \
      fileinfo \
      gd \
      gettext \
      intl \
      ldap \
      mcrypt \
      memcached \
      memcache \
      mysqli \
      opcache \
      redis \
      soap \
      sockets \
      xsl \
      zip \
    " \
    && install-php-extensions $PHP_EXTENSIONS

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