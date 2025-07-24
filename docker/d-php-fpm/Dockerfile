FROM composer:2.7.8 as composer
FROM php:8.1-fpm

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install --no-install-recommends -y \
  git \
  locales \
  curl \
  wget \
  zlib1g-dev \
  libicu-dev \
  zip \
  zsh \
  libxml2-dev \
  libpng-dev \
  libzip-dev \
  gnupg \
  gnupg1 \
  gnupg2 \
  apt-transport-https \
  && docker-php-ext-configure intl \
  && docker-php-ext-install pdo pdo_mysql mysqli zip gd intl \
  && docker-php-ext-enable mysqli \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

RUN rm /etc/localtime \
    && ln -s /usr/share/zoneinfo/Europe/Paris /etc/localtime \
    && "date" \
    && locale-gen

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY ./conf/php.ini /usr/local/etc/php/php.ini

COPY ./php-start.sh /root/start.sh
CMD ["/bin/sh", "/root/start.sh"]

EXPOSE 9000
