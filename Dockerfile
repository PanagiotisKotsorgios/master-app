# ============================================================
# Dockerfile — MAster PHP app for Coolify / any container host
# ============================================================
# Base: php:8.2 + Apache
# Extensions: pdo_mysql, mbstring, gd (for OG images), intl, opcache, zip
# Extras: mod_rewrite, mod_headers, healthcheck, entrypoint
# ============================================================

FROM php:8.2-apache

# ── Build-time deps → install extensions ──
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev \
        libicu-dev libzip-dev libonig-dev \
        curl unzip; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mysqli mbstring gd intl opcache zip; \
    apt-get clean; rm -rf /var/lib/apt/lists/*

# ── Apache: rewrite + headers + serve /var/www/html ──
RUN a2enmod rewrite headers expires

# ── Composer (for future vendored deps) ──
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Copy the app ──
WORKDIR /var/www/html
COPY . /var/www/html/

# ── If composer.json exists, install (no dev, no scripts) ──
RUN if [ -f composer.json ]; then \
        composer install --no-dev --no-interaction --optimize-autoloader --no-scripts || true; \
    fi

# ── Runtime dirs: create + own by www-data ──
RUN mkdir -p /var/www/html/logs \
             /var/www/html/backups \
             /var/www/html/uploads/events/public \
             /var/www/html/uploads/events/private; \
    chown -R www-data:www-data /var/www/html/logs /var/www/html/backups /var/www/html/uploads

# ── PHP prod config ──
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    { \
        echo "memory_limit=\${PHP_MEMORY_LIMIT:-256M}"; \
        echo "upload_max_filesize=\${PHP_UPLOAD_MAX_FILESIZE:-10M}"; \
        echo "post_max_size=\${PHP_POST_MAX_SIZE:-12M}"; \
        echo "expose_php=Off"; \
        echo "date.timezone=Europe/Athens"; \
        echo "session.gc_maxlifetime=28800"; \
    } > "$PHP_INI_DIR/conf.d/zz-master.ini"

# ── OPcache prod ──
RUN { \
        echo "opcache.enable=1"; \
        echo "opcache.memory_consumption=192"; \
        echo "opcache.interned_strings_buffer=16"; \
        echo "opcache.max_accelerated_files=20000"; \
        echo "opcache.validate_timestamps=1"; \
        echo "opcache.revalidate_freq=2"; \
    } > "$PHP_INI_DIR/conf.d/zz-opcache.ini"

# ── Apache: allow .htaccess overrides on the docroot ──
RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/master.conf; \
    a2enconf master

# ── Entrypoint (waits for DB, runs migrations, then apache-fg) ──
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# ── Healthcheck: lightweight /healthz.php (no DB touch, always 200 if PHP+Apache up) ──
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 \
    CMD curl -fsS http://127.0.0.1/healthz.php >/dev/null || exit 1

EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
