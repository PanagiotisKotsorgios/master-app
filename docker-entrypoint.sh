#!/usr/bin/env bash
# ============================================================
# docker-entrypoint.sh
# ============================================================
# 1. Write runtime php.ini from env vars (no literal ${VAR} placeholders)
# 2. Wait for DB (up to ~120s)
# 3. Run migrations (idempotent)
# 4. Ensure runtime dirs are owned by www-data
# 5. Exec apache2-foreground
# ============================================================
set -euo pipefail

: "${DB_HOST:=db}"
: "${DB_PORT:=3306}"
: "${DB_USER:=master}"
: "${DB_PASS:=}"
: "${DB_NAME:=master_db}"
: "${DB_CHARSET:=utf8mb4}"
: "${RUN_MIGRATIONS:=1}"
: "${PHP_MEMORY_LIMIT:=256M}"
: "${PHP_UPLOAD_MAX_FILESIZE:=10M}"
: "${PHP_POST_MAX_SIZE:=12M}"

# ── 1. Write PHP tunables with resolved values ──
INI="/usr/local/etc/php/conf.d/zz-runtime.ini"
cat > "$INI" <<EOF
memory_limit=${PHP_MEMORY_LIMIT}
upload_max_filesize=${PHP_UPLOAD_MAX_FILESIZE}
post_max_size=${PHP_POST_MAX_SIZE}
EOF
echo "[entrypoint] wrote $INI (memory=${PHP_MEMORY_LIMIT}, upload=${PHP_UPLOAD_MAX_FILESIZE})"

# ── 2. Wait for DB ──
echo "[entrypoint] waiting for MySQL at ${DB_HOST}:${DB_PORT}…"
for i in $(seq 1 60); do
    if php -r "try { new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_NAME};charset=${DB_CHARSET}', '${DB_USER}', '${DB_PASS}', [PDO::ATTR_TIMEOUT => 2]); exit(0); } catch (Exception \$e) { exit(1); }" 2>/dev/null; then
        echo "[entrypoint] DB ready (attempt ${i})."
        break
    fi
    sleep 2
    if [ "$i" = "60" ]; then
        echo "[entrypoint] DB not reachable after 120s — starting Apache anyway (healthz.php will report DEGRADED)."
    fi
done

# ── 3. Runtime dir perms (Coolify may mount volumes over these) ──
mkdir -p /var/www/html/logs \
         /var/www/html/backups \
         /var/www/html/uploads/events/public \
         /var/www/html/uploads/events/private
chown -R www-data:www-data /var/www/html/logs /var/www/html/backups /var/www/html/uploads 2>/dev/null || true

# ── 4. Run migrations ──
if [ "${RUN_MIGRATIONS}" = "1" ] && [ -f /var/www/html/tools/run_events_migration.php ]; then
    echo "[entrypoint] running migrations…"
    php /var/www/html/tools/run_events_migration.php || echo "[entrypoint] migration warnings (non-fatal)."
fi

echo "[entrypoint] handing off to: $*"
exec "$@"
