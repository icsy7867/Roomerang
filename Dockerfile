FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
RUN groupadd --gid 1000 paste && useradd --uid 1000 --gid 1000 --create-home --shell /usr/sbin/nologin paste
COPY --chown=paste:paste . /app
COPY --chown=paste:paste images/roomerang.svg /app/public/assets/roomerang.svg
COPY --chown=paste:paste docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
RUN mkdir -p /app/data \
    && chown -R paste:paste /app/data \
    && chmod +x /app/docker-entrypoint.sh

ENV PASTE_CHAT_DB=/app/data/database.sql
ENV PASTE_CHAT_MAX_MESSAGE_LENGTH=12000
ENV MAXIMUM_DURATION=1mo
ENV DEFAULT_DURATION=1h

RUN sed -ri 's!/var/www/html!/app/public!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's!<Directory /var/www/>!<Directory /app/public/>!g; s!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && sed -ri 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -ri 's/\*:80>/*:8080>/' /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's/APACHE_RUN_USER=www-data/APACHE_RUN_USER=paste/; s/APACHE_RUN_GROUP=www-data/APACHE_RUN_GROUP=paste/' /etc/apache2/envvars \
    && mkdir -p /var/run/apache2 /var/lock/apache2 /var/log/apache2 \
    && chown -R paste:paste /etc/apache2 /usr/local/etc/php /var/run/apache2 /var/lock/apache2 /var/log/apache2

VOLUME ["/app/data"]
USER 1000:1000
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 CMD php /app/scripts/healthcheck.php || exit 1
ENTRYPOINT ["/app/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
