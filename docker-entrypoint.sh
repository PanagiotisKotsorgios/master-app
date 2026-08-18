#!/usr/bin/env bash
# ============================================================
# docker-entrypoint.sh — Apache starts FIRST, everything else after
# ============================================================
# Design:
#   1. Launch Apache in the BACKGROUND immediately → healthcheck
#      passes within seconds, container stays healthy no matter what.
#   2. Do all DB / migration / superadmin work AFTER Apache is up.
#   3. If DB is unreachable (e.g. Coolify is in Dockerfile-only mode
#      with no db service), Apache still serves /healthz.php and
#      surfaces PHP errors via `docker logs`.
#   4. Finally, `wait` on Apache so the container tracks its lifecycle.
# ============================================================

# Defaults
: "${DB_HOST:=db}"
: "${DB_PORT:=3306}"
: "${DB_USER:=master}"
: "${DB_PASS:=master-app-default-pass}"
: "${DB_NAME:=master_db}"
: "${DB_CHARSET:=utf8mb4}"
: "${DB_ROOT_PASSWORD:=master-root-fallback-2026}"
: "${RUN_MIGRATIONS:=1}"
: "${PHP_MEMORY_LIMIT:=256M}"
: "${PHP_UPLOAD_MAX_FILESIZE:=10M}"
: "${PHP_POST_MAX_SIZE:=12M}"
: "${AUTO_CREATE_ADMIN:=1}"
: "${ADMIN_EMAIL:=admin@master-app.gr}"
: "${ADMIN_NAME:=Admin}"

export DB_HOST DB_PORT DB_USER DB_PASS DB_NAME DB_CHARSET DB_ROOT_PASSWORD
export ADMIN_EMAIL ADMIN_NAME

LOG_DIR=/var/www/html/logs
mkdir -p "$LOG_DIR" 2>/dev/null || true
chown -R www-data:www-data "$LOG_DIR" 2>/dev/null || true

log() { echo "[entrypoint] $*"; }

# ── 1. PHP tunables (before Apache starts, so php-apache picks them up) ──
{
    echo "memory_limit=${PHP_MEMORY_LIMIT}"
    echo "upload_max_filesize=${PHP_UPLOAD_MAX_FILESIZE}"
    echo "post_max_size=${PHP_POST_MAX_SIZE}"
} > /usr/local/etc/php/conf.d/zz-runtime.ini 2>/dev/null || true
log "wrote /usr/local/etc/php/conf.d/zz-runtime.ini"

# ── 2. Runtime dirs (Apache needs these) ──
mkdir -p /var/www/html/backups \
         /var/www/html/uploads/events/public \
         /var/www/html/uploads/events/private 2>/dev/null || true
chown -R www-data:www-data /var/www/html/logs /var/www/html/backups /var/www/html/uploads 2>/dev/null || true

# ── 3. Auto-generate CRON_SECRET if empty ──
CRON_FILE="$LOG_DIR/.cron_secret"
if [ -z "${CRON_SECRET:-}" ]; then
    if [ -s "$CRON_FILE" ]; then
        CRON_SECRET="$(cat "$CRON_FILE" 2>/dev/null)"
        log "reusing CRON_SECRET from $CRON_FILE"
    else
        CRON_SECRET="$(head -c 24 /dev/urandom 2>/dev/null | od -An -tx1 | tr -d ' \n')"
        if [ -n "$CRON_SECRET" ]; then
            echo "$CRON_SECRET" > "$CRON_FILE" 2>/dev/null || true
            chmod 600 "$CRON_FILE" 2>/dev/null || true
            log "═══════════════════════════════════════════════════"
            log "Generated CRON_SECRET: $CRON_SECRET"
            log "Persisted to $CRON_FILE"
            log "═══════════════════════════════════════════════════"
        fi
    fi
    export CRON_SECRET
fi

# ── 4. START APACHE IN BACKGROUND — healthcheck can now pass ──
log "starting Apache in background so healthcheck can pass immediately…"
"$@" &
APACHE_PID=$!
log "Apache PID: $APACHE_PID"

# ── 5. Provisioning happens in background so it never blocks Apache ──
(
    # Give Apache 3 seconds to bind port 80 before we start DB work.
    sleep 3

    # Wait for DB (root reachable = server up)
    log "waiting for MySQL at ${DB_HOST}:${DB_PORT}…"
    DB_READY=0
    for i in $(seq 1 60); do
        if php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";charset=".getenv("DB_CHARSET"), "root", getenv("DB_ROOT_PASSWORD"), [PDO::ATTR_TIMEOUT => 2]); exit(0); } catch (Throwable $e) { exit(1); }' 2>/dev/null; then
            log "MySQL root reachable (attempt ${i})."
            DB_READY=1
            break
        fi
        sleep 2
    done

    if [ "$DB_READY" != "1" ]; then
        log "═══════════════════════════════════════════════════"
        log "WARNING: No DB reachable at ${DB_HOST}:${DB_PORT} after 120s."
        log "Apache is still serving. Most likely cause:"
        log "  → Coolify is in 'Dockerfile' build pack mode."
        log "  → Change it to 'Docker Compose' in the Coolify UI."
        log "  → Then redeploy — both db + app containers will start."
        log "═══════════════════════════════════════════════════"
        exit 0
    fi

    # Self-heal app user credentials via root
    log "aligning app user '${DB_USER}' credentials…"
    php -r '
        try {
            $root = new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), "root", getenv("DB_ROOT_PASSWORD"));
            $root->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db   = getenv("DB_NAME");
            $user = getenv("DB_USER");
            $pass = getenv("DB_PASS");
            $root->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $q = $root->quote($pass);
            try { $root->exec("CREATE USER IF NOT EXISTS \x27$user\x27@\x27%\x27 IDENTIFIED WITH mysql_native_password BY $q"); } catch(Throwable $e) {}
            try { $root->exec("ALTER USER  \x27$user\x27@\x27%\x27 IDENTIFIED WITH mysql_native_password BY $q"); } catch(Throwable $e) {}
            $root->exec("GRANT ALL PRIVILEGES ON `$db`.* TO \x27$user\x27@\x27%\x27");
            $root->exec("FLUSH PRIVILEGES");
            fwrite(STDOUT, "[entrypoint] app user aligned.\n");
        } catch (Throwable $e) {
            fwrite(STDERR, "[entrypoint] align error: " . $e->getMessage() . "\n");
        }
    ' 2>&1

    # Verify app user
    if php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_NAME").";charset=".getenv("DB_CHARSET"), getenv("DB_USER"), getenv("DB_PASS"), [PDO::ATTR_TIMEOUT => 5]); exit(0); } catch (Throwable $e) { fwrite(STDERR, "[entrypoint] app-user verify: " . $e->getMessage() . "\n"); exit(1); }' 2>&1; then
        log "app user verified against ${DB_NAME}."
    else
        log "WARNING: app user still can't connect — check DB_PASS."
        exit 0
    fi

    # Migrations
    if [ "${RUN_MIGRATIONS}" = "1" ] && [ -f /var/www/html/tools/run_events_migration.php ]; then
        log "running migrations…"
        php /var/www/html/tools/run_events_migration.php 2>&1 || log "migration warnings (non-fatal)."
    fi

    # Auto-create superadmin
    if [ "$AUTO_CREATE_ADMIN" = "1" ]; then
        ADMIN_PASS_FILE="$LOG_DIR/.admin_password"
        export ADMIN_PASS_FILE
        php -r '
            require "/var/www/html/includes/config.php";
            try {
                $db = getDB();
                $c = (int)$db->query("SELECT COUNT(*) FROM users WHERE role=\x27superadmin\x27")->fetchColumn();
                if ($c > 0) { fwrite(STDOUT, "[entrypoint] superadmin already exists.\n"); exit(0); }
                $pw = bin2hex(random_bytes(6));
                $hash = password_hash($pw, PASSWORD_DEFAULT);
                $sid = null;
                try {
                    $planId = (int)$db->query("SELECT id FROM plans WHERE slug=\x27pro\x27 AND active=1 LIMIT 1")->fetchColumn();
                    if (!$planId) $planId = (int)$db->query("SELECT id FROM plans WHERE active=1 LIMIT 1")->fetchColumn();
                    if ($planId) {
                        $db->prepare("INSERT INTO schools (name, email, plan_id, plan_status, trial_ends, active) VALUES (?, ?, ?, \x27active\x27, DATE_ADD(NOW(), INTERVAL 3650 DAY), 1)")
                           ->execute(["MAster Admin School", getenv("ADMIN_EMAIL"), $planId]);
                        $sid = (int)$db->lastInsertId();
                    }
                } catch (Throwable $e) { $sid = null; }
                $db->prepare("INSERT INTO users (school_id, name, email, password, role, active) VALUES (?, ?, ?, ?, \x27superadmin\x27, 1)")
                   ->execute([$sid, getenv("ADMIN_NAME"), getenv("ADMIN_EMAIL"), $hash]);
                file_put_contents(getenv("ADMIN_PASS_FILE"), $pw);
                @chmod(getenv("ADMIN_PASS_FILE"), 0600);
                fwrite(STDOUT, "\n[entrypoint] ═══════════════════════════════════════════════════\n");
                fwrite(STDOUT, "[entrypoint] Created superadmin.\n");
                fwrite(STDOUT, "[entrypoint]   Email:    " . getenv("ADMIN_EMAIL") . "\n");
                fwrite(STDOUT, "[entrypoint]   Password: " . $pw . "\n");
                fwrite(STDOUT, "[entrypoint]   Login:    /login.php\n");
                fwrite(STDOUT, "[entrypoint] ═══════════════════════════════════════════════════\n\n");
            } catch (Throwable $e) {
                fwrite(STDERR, "[entrypoint] auto-create superadmin failed: " . $e->getMessage() . "\n");
            }
        ' 2>&1
    fi

    log "provisioning done."
) &

# ── 6. Wait on Apache (this is what keeps container alive) ──
log "handing off — waiting on Apache (PID $APACHE_PID)."
wait $APACHE_PID
EXIT_CODE=$?
log "Apache exited with code $EXIT_CODE"
exit $EXIT_CODE
