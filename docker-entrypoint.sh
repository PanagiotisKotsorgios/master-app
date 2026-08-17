#!/usr/bin/env bash
# ============================================================
# docker-entrypoint.sh
# ============================================================
# 1. Wait for DB (max 60s)
# 2. Run migrations if RUN_MIGRATIONS=1 (default: 1)
# 3. Ensure runtime dirs exist and are owned by www-data
# 4. Hand off to CMD (apache2-foreground)
# ============================================================
set -euo pipefail

: "${DB_HOST:=db}"
: "${DB_PORT:=3306}"
: "${DB_USER:=master}"
: "${DB_PASS:=}"
: "${DB_NAME:=master_db}"
: "${RUN_MIGRATIONS:=1}"

echo "[entrypoint] Waiting for MySQL at ${DB_HOST}:${DB_PORT}…"
for i in $(seq 1 30); do
    if php -r "try { new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_NAME}', '${DB_USER}', '${DB_PASS}'); exit(0); } catch (Exception \$e) { exit(1); }"; then
        echo "[entrypoint] DB ready."
        break
    fi
    sleep 2
    if [ "$i" = "30" ]; then
        echo "[entrypoint] DB not reachable after 60s — starting Apache anyway."
    fi
done

# Runtime dir permissions (Coolify may mount volumes over these)
mkdir -p /var/www/html/logs /var/www/html/backups \
         /var/www/html/uploads/events/public /var/www/html/uploads/events/private
chown -R www-data:www-data /var/www/html/logs /var/www/html/backups /var/www/html/uploads 2>/dev/null || true

# Run migrations (idempotent — script ignores duplicate-column errors)
if [ "${RUN_MIGRATIONS}" = "1" ] && [ -f /var/www/html/tools/run_events_migration.php ]; then
    echo "[entrypoint] Running migrations…"
    php /var/www/html/tools/run_events_migration.php || echo "[entrypoint] Migration warnings (non-fatal)."
fi

exec "$@"
