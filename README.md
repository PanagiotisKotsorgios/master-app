# MAster

Martial-arts club management SaaS: athletes · subscriptions · notifications · payments · full events subsystem (championships, camps, seminars) with cross-club registration, brackets, live scoring, public discovery.

Stack: PHP 8.2 · MySQL 8 · Apache. Zero-JS-framework — vanilla + a couple of CDN libs.

---

## Quick start (local, docker)

```bash
cp .env.example .env
# edit .env — at minimum: DB_PASS and CRON_SECRET
docker build -t master-app .
docker run --rm -p 8080:80 \
  --env-file .env \
  -e DB_HOST=host.docker.internal \
  master-app
# open http://localhost:8080/
```

The entrypoint auto-runs `tools/run_events_migration.php` at boot.

---

## Deploying on Coolify (fully self-contained)

The `docker-compose.yml` in this repo ships **both** the app and the MySQL 8 database as a single stack, with named volumes for persistence. You don't need to create a separate DB resource.

1. **New resource** → *Application* → *Public Repository*.
2. **Repository URL**: `https://github.com/PanagiotisKotsorgios/master-app`
3. **Branch**: `main`
4. **Build pack**: **Docker Compose** (auto-detected from `docker-compose.yml`).
5. **Environment variables** — the only ones you *must* set:
   ```
   APP_URL=https://<your-domain>
   DB_PASS=<pick a strong password>
   DB_ROOT_PASSWORD=<pick another strong one>
   CRON_SECRET=<openssl rand -hex 32>
   ```
   Optional:
   ```
   BREVO_API_KEY=…            # if you want email
   MAIL_FROM_EMAIL=noreply@…
   MAIL_FROM_NAME=MAster
   DB_NAME=master_db          # defaults are fine
   DB_USER=master
   ```
6. **Domain & HTTPS** — assign a domain, enable Let's Encrypt. HSTS is automatic.
7. **Deploy**. First boot: MySQL starts → app waits for it → migrations apply the full baseline schema + events subsystem → Apache serves on port 80.
8. **Cron** — Coolify → app → *Scheduled Tasks*:
   ```
   */15 * * * *   docker exec <app-container> php /var/www/html/cron/reminders.php
   */15 * * * *   docker exec <app-container> php /var/www/html/cron/event_reminders.php
   0 9 1 1,4,7,10 *   docker exec <app-container> php /var/www/html/cron/quarterly-stats.php
   ```
   (Coolify usually offers a "run inside container" scheduled-task shortcut — use that instead of `docker exec`.)

### What ships in the compose stack

| Service | Purpose | Volume | Port |
|---|---|---|---|
| `db`  | MySQL 8, `utf8mb4`, private (not exposed to host) | `master-db` | internal `3306` |
| `app` | PHP 8.2 + Apache, healthcheck at `/healthz.php` | `master-uploads` · `master-backups` · `master-logs` | published `80` |

### Post-deploy sanity checks

- [ ] `https://your-domain/` — landing page loads.
- [ ] `https://your-domain/healthz.php` → `OK`
- [ ] `https://your-domain/healthz.php?db=1` → `OK db`
- [ ] `https://your-domain/events/` — public events directory.
- [ ] `https://your-domain/cron/reminders.php` without `?token=` → **403 Unauthorized**.
- [ ] After first deploy, create your superadmin via the container:
  ```bash
  docker exec -it <app-container> php -r '
    require "/var/www/html/includes/config.php";
    $h = password_hash("change-me-now", PASSWORD_DEFAULT);
    getDB()->prepare("INSERT INTO users (name,email,password,role,active) VALUES (?,?,?,?,1)")
      ->execute(["Admin","admin@your-domain.com",$h,"superadmin"]);
    echo "superadmin created\n";
  '
  ```

---

## Post-deploy sanity checks

- [ ] `https://your-domain/` loads the marketing page.
- [ ] `https://your-domain/events/` shows the public events directory.
- [ ] Register a superadmin via SQL (see the initial `DATABASE.SQL.sql` schema privately).
- [ ] `/tools/run_events_migration.php?web=1` (superadmin) reports "0 failed".
- [ ] Hit `/cron/reminders.php` without a token → **403 Unauthorized** (confirms secret is enforced).
- [ ] Hit `/cron/reminders.php?token=<your-CRON_SECRET>` → JSON summary.

---

## Environment variables

| Var | Required | Purpose |
|---|---|---|
| `APP_URL` | ✅ | Canonical origin, used in email links and CSP |
| `APP_NAME` | | Display name |
| `SESSION_LIFETIME` | | Seconds; default 28800 (8h) |
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASS` | ✅ | MySQL connection |
| `CRON_SECRET` | ✅ | Shared secret for HTTP-invoked crons; without it, HTTP cron endpoints refuse |
| `BREVO_API_KEY` | | Transactional email |
| `MAIL_FROM_EMAIL` `MAIL_FROM_NAME` | | From-header defaults |
| `PHP_MEMORY_LIMIT` `PHP_UPLOAD_MAX_FILESIZE` `PHP_POST_MAX_SIZE` | | Container PHP tuning |
| `RUN_MIGRATIONS` | | Set to `0` to skip auto-migrate on boot |

Per-tenant secrets (Viva keys, bank IBAN, IRIS phone, bulker.gr SMS creds) live in the `system_settings` DB table and are edited from the superadmin panel — no env var needed.

---

## Repo layout

```
/               index.php · login · register · forgot-password · contact
/admin          superadmin panel
/dashboard      club owner dashboard
/employee       employee panel
/parent         parent portal
/pages          core club CRUD (athletes, subscriptions, events, …)
/events         public event pages (SEO, no auth): view, results, display, certificate, club, federation, athletes, og
/api            JSON endpoints (public_stats, belts, events, athletes_search)
/cron           reminders · quarterly-stats · event_reminders
/includes       config · layout · mailer · security_headers · events · events_bracket
/migrations     001_events.sql · 002_events_extras.sql
/tools          run_events_migration.php
/uploads/events user-uploaded files (git-ignored; mount as persistent volume)
/backups        DB dumps (git-ignored; mount as persistent volume)
/logs           runtime error log (git-ignored; mount as persistent volume)
Dockerfile · docker-entrypoint.sh · .env.example
```

---

## License

Proprietary. All rights reserved.
