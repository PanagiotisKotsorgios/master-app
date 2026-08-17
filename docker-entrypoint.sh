#!/usr/bin/env bash
# ============================================================
# docker-entrypoint.sh — fully self-provisioning
# ============================================================
# 1. Write runtime php.ini from env vars
# 2. Auto-generate CRON_SECRET if empty (persisted in /var/www/html/logs)
# 3. Wait for DB, run migrations
# 4. Auto-create a superadmin on first boot (creds printed to stderr)
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
: "${AUTO_CREATE_ADMIN:=1}"
: "${ADMIN_EMAIL:=admin@master-app.gr}"
: "${ADMIN_NAME:=Admin}"

LOG_DIR=/var/www/html/logs
mkdir -p "$LOG_DIR"
chown -R www-data:www-data "$LOG_DIR" 2>/dev/null || true

# ── 1. PHP tunables ──
INI="/usr/local/etc/php/conf.d/zz-runtime.ini"
cat > "$INI" <<EOF
memory_limit=${PHP_MEMORY_LIMIT}
upload_max_filesize=${PHP_UPLOAD_MAX_FILESIZE}
post_max_size=${PHP_POST_MAX_SIZE}
EOF
echo "[entrypoint] wrote $INI"

# ── 2. Auto-generate CRON_SECRET if empty ──
CRON_FILE="$LOG_DIR/.cron_secret"
if [ -z "${CRON_SECRET:-}" ]; then
    if [ -s "$CRON_FILE" ]; then
        CRON_SECRET="$(cat "$CRON_FILE")"
        echo "[entrypoint] reusing CRON_SECRET from $CRON_FILE"
    else
        CRON_SECRET="$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
        echo "$CRON_SECRET" > "$CRON_FILE"
        chmod 600 "$CRON_FILE"
        echo "[entrypoint] ═══════════════════════════════════════════════════"
        echo "[entrypoint] Generated CRON_SECRET: $CRON_SECRET"
        echo "[entrypoint] Persisted to $CRON_FILE (survive restarts)"
        echo "[entrypoint] ═══════════════════════════════════════════════════"
    fi
    export CRON_SECRET
fi

# ── 3. Wait for DB + self-heal app user credentials ──
echo "[entrypoint] waiting for MySQL at ${DB_HOST}:${DB_PORT}…"
DB_READY=0
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-master-root-fallback-2026}"

# Step A: wait until root can log in (server is up)
for i in $(seq 1 60); do
    if php -r "try { new PDO('mysql:host=${DB_HOST};port=${DB_PORT};charset=${DB_CHARSET}', 'root', '${DB_ROOT_PASSWORD}', [PDO::ATTR_TIMEOUT => 2]); exit(0); } catch (Exception \$e) { exit(1); }" 2>/dev/null; then
        echo "[entrypoint] MySQL root reachable (attempt ${i})."
        DB_READY=1
        break
    fi
    sleep 2
done

# Step B: if root works, force-align the app user's password + grants.
# Runs on every boot so DB_PASS rotations, volume-baked stale creds,
# or env-var mismatches (DB_PASS vs DB_PASSWORD) all self-heal.
if [ "$DB_READY" = "1" ]; then
    echo "[entrypoint] aligning app user '${DB_USER}' credentials…"
    php <<PHP || echo "[entrypoint] user-align warning (non-fatal)"
<?php
try {
    \$root = new PDO('mysql:host=${DB_HOST};port=${DB_PORT}', 'root', '${DB_ROOT_PASSWORD}');
    \$root->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    \$root->exec("CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    \$root->exec("CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED WITH mysql_native_password BY " . \$root->quote('${DB_PASS}'));
    \$root->exec("ALTER USER '${DB_USER}'@'%' IDENTIFIED WITH mysql_native_password BY " . \$root->quote('${DB_PASS}'));
    \$root->exec("GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%'");
    \$root->exec("FLUSH PRIVILEGES");
    echo "[entrypoint] app user aligned.\n";
} catch (Throwable \$e) {
    fwrite(STDERR, "[entrypoint] align error: " . \$e->getMessage() . "\n");
    exit(1);
}
PHP

    # Step C: verify app user actually works
    if php -r "try { new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_NAME};charset=${DB_CHARSET}', '${DB_USER}', '${DB_PASS}', [PDO::ATTR_TIMEOUT => 5]); exit(0); } catch (Exception \$e) { fwrite(STDERR, \"[entrypoint] app-user check failed: \" . \$e->getMessage() . \"\\n\"); exit(1); }"; then
        echo "[entrypoint] app user '${DB_USER}' verified against ${DB_NAME}."
    else
        echo "[entrypoint] WARNING: app user still can't connect — check DB_PASS."
        DB_READY=0
    fi
fi

if [ "$DB_READY" = "0" ]; then
    echo "[entrypoint] DB not usable after retries — starting Apache anyway (healthz.php will report DEGRADED)."
fi

# Runtime dir perms
mkdir -p /var/www/html/backups \
         /var/www/html/uploads/events/public \
         /var/www/html/uploads/events/private
chown -R www-data:www-data /var/www/html/logs /var/www/html/backups /var/www/html/uploads 2>/dev/null || true

# ── 4. Migrations ──
if [ "$DB_READY" = "1" ] && [ "${RUN_MIGRATIONS}" = "1" ] && [ -f /var/www/html/tools/run_events_migration.php ]; then
    echo "[entrypoint] running migrations…"
    php /var/www/html/tools/run_events_migration.php || echo "[entrypoint] migration warnings (non-fatal)."
fi

# ── 5. Auto-create superadmin on first boot ──
if [ "$DB_READY" = "1" ] && [ "$AUTO_CREATE_ADMIN" = "1" ]; then
    ADMIN_PASS_FILE="$LOG_DIR/.admin_password"
    php <<PHP
<?php
require '/var/www/html/includes/config.php';
try {
    \$db = getDB();
    \$c = \$db->query("SELECT COUNT(*) FROM users WHERE role='superadmin'")->fetchColumn();
    if ((int)\$c > 0) {
        fwrite(STDERR, "[entrypoint] superadmin already exists — skipping auto-create.\n");
        exit(0);
    }
    \$pw = bin2hex(random_bytes(6));   // 12-char random password
    \$hash = password_hash(\$pw, PASSWORD_DEFAULT);
    // Try to create a bootstrap school so plan_id FK is satisfied on tables that need it
    try {
        \$sid = null;
        \$planId = (int)\$db->query("SELECT id FROM plans WHERE slug='pro' AND active=1 LIMIT 1")->fetchColumn();
        if (!\$planId) \$planId = (int)\$db->query("SELECT id FROM plans WHERE active=1 LIMIT 1")->fetchColumn();
        if (\$planId) {
            \$db->prepare("INSERT INTO schools (name, email, plan_id, plan_status, trial_ends, active) VALUES (?, ?, ?, 'active', DATE_ADD(NOW(), INTERVAL 3650 DAY), 1)")
               ->execute(['MAster Admin School', '${ADMIN_EMAIL}', \$planId]);
            \$sid = (int)\$db->lastInsertId();
        }
    } catch (Throwable \$e) { \$sid = null; }
    \$db->prepare("INSERT INTO users (school_id, name, email, password, role, active) VALUES (?, ?, ?, ?, 'superadmin', 1)")
       ->execute([\$sid, '${ADMIN_NAME}', '${ADMIN_EMAIL}', \$hash]);
    file_put_contents('${ADMIN_PASS_FILE}', \$pw);
    chmod('${ADMIN_PASS_FILE}', 0600);
    fwrite(STDERR, "\n");
    fwrite(STDERR, "[entrypoint] ═══════════════════════════════════════════════════\n");
    fwrite(STDERR, "[entrypoint] Created superadmin.\n");
    fwrite(STDERR, "[entrypoint]   Email:    ${ADMIN_EMAIL}\n");
    fwrite(STDERR, "[entrypoint]   Password: " . \$pw . "\n");
    fwrite(STDERR, "[entrypoint]   Login:    /login.php\n");
    fwrite(STDERR, "[entrypoint] Password also written to ${ADMIN_PASS_FILE}\n");
    fwrite(STDERR, "[entrypoint] ═══════════════════════════════════════════════════\n\n");
} catch (Throwable \$e) {
    fwrite(STDERR, "[entrypoint] auto-create superadmin failed: " . \$e->getMessage() . "\n");
    exit(0);   // don't fail the container start
}
PHP
fi

echo "[entrypoint] handing off to: $*"
exec "$@"
