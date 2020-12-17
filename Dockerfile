# Lightly modified from drupal:9 to not create a project inside the container
FROM php:7.4-apache-buster as base

# Production PHP.ini
RUN cp ${PHP_INI_DIR}/php.ini-production ${PHP_INI_DIR}/php.ini

# install the PHP extensions we need
RUN set -eux; \
	\
	if command -v a2enmod; then \
		a2enmod rewrite; \
	fi; \
	\
	savedAptMark="$(apt-mark showmanual)"; \
	\
	apt-get update; \
	apt-get install -y --no-install-recommends \
		libfreetype6-dev \
		libjpeg-dev \
		libpng-dev \
		libpq-dev \
		libzip-dev \
	; \
	\
	docker-php-ext-configure gd \
		--with-freetype \
		--with-jpeg=/usr \
	; \
	\
	docker-php-ext-install -j "$(nproc)" \
		gd \
		opcache \
		pdo_pgsql \
		zip \
	; \
	\
# reset apt-mark's "manual" list so that "purge --auto-remove" will remove all build dependencies
	apt-mark auto '.*' > /dev/null; \
	apt-mark manual $savedAptMark; \
	ldd "$(php -r 'echo ini_get("extension_dir");')"/*.so \
		| awk '/=>/ { print $3 }' \
		| sort -u \
		| xargs -r dpkg-query -S \
		| cut -d: -f1 \
		| sort -u \
		| xargs -rt apt-mark manual; \
	\
	apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
	rm -rf /var/lib/apt/lists/*

# set recommended PHP.ini settings
# see https://secure.php.net/manual/en/opcache.installation.php
RUN { \
		echo 'opcache.memory_consumption=128'; \
		echo 'opcache.interned_strings_buffer=8'; \
		echo 'opcache.max_accelerated_files=4000'; \
		echo 'opcache.revalidate_freq=60'; \
		echo 'opcache.fast_shutdown=1'; \
	} > ${PHP_INI_DIR}/conf.d/opcache-recommended.ini

RUN set -eux; \
    # Need some extras for certain composer downloads
    apt-get update; \
    apt-get install -y --no-install-recommends git openssh-client unzip
COPY docker/ssh_known_hosts /etc/ssh/ssh_known_hosts

# https://github.com/drupal/drupal/blob/9.0.1/composer.lock#L4052-L4053
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/

# https://www.drupal.org/node/3060/release
ENV PATH=${PATH}:/opt/drupal/vendor/bin

COPY app /opt/drupal
WORKDIR /opt/drupal

RUN set -eux; \
	export COMPOSER_HOME="$(mktemp -d)"; \
    composer install --no-interaction --optimize-autoloader --no-dev; \
	# delete composer cache
	rm -rf "$COMPOSER_HOME"
RUN set -eux; \
    mkdir -p web/sites/default/files; \
	chown -Rv www-data:www-data web/sites web/modules web/themes config/sync; \
	rmdir /var/www/html; \
	ln -sf /opt/drupal/web /var/www/html;
