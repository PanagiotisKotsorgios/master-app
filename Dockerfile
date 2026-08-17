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

# ── PHP prod config (static bits) ──
# Runtime-tunable bits (memory_limit, upload sizes) are written by
# docker-entrypoint.sh from env vars at container start — writing them
# at build time with ${VAR} placeholders produces "memory to 0 bytes"
# warnings because PHP treats the literal ${VAR} as an invalid value.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    { \
        echo "expose_php=Off"; \
        echo "date.timezone=Europe/Athens"; \
        echo "session.gc_maxlifetime=28800"; \
        echo "log_errors=On"; \
        echo "error_log=/proc/self/fd/2"; \
        echo "error_reporting=E_ALL"; \
        echo "display_errors=Off"; \
        echo "display_startup_errors=Off"; \
    } > "$PHP_INI_DIR/conf.d/zz-master.ini"

# Route Apache error log to stderr so `docker logs` shows PHP fatals immediately
RUN ln -sf /proc/self/fd/2 /var/log/apache2/error.log; \
    ln -sf /proc/self/fd/1 /var/log/apache2/access.log

# ── OPcache prod ──
RUN { \
        echo "opcache.enable=1"; \
        echo "opcache.memory_consumption=192"; \
        echo "opcache.interned_strings_buffer=16"; \
        echo "opcache.max_accelerated_files=20000"; \
        echo "opcache.validate_timestamps=1"; \
        echo "opcache.revalidate_freq=2"; \
    } > "$PHP_INI_DIR/conf.d/zz-opcache.ini"

# ── Apache: allow .htaccess overrides on the docroot + set ServerName ──
RUN printf '%s\n' \
    'ServerName localhost' \
    '<Directory /var/www/html>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/master.conf; \
    a2enconf master; \
    # Silence "Could not reliably determine the server's fully qualified domain name"
    echo "ServerName localhost" >> /etc/apache2/apache2.conf

# ── Entrypoint (waits for DB, runs migrations, then apache-fg) ──
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# ── Healthcheck: lightweight /healthz.php (no DB touch, always 200 if PHP+Apache up) ──
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 \
    CMD curl -fsS http://127.0.0.1/healthz.php >/dev/null || exit 1

EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
