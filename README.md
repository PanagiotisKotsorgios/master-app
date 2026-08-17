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

## Deploying on Coolify

1. **Create a new resource** → *Application* → *Public Repository*.
2. **Repository URL**: `https://github.com/PanagiotisKotsorgios/master-app`
3. **Branch**: `main`
4. **Build pack**: **Dockerfile** (Coolify detects the repo `Dockerfile` automatically).
5. **Port**: `80`.
6. **Environment variables** — copy from `.env.example` and fill in values:
   - `APP_URL` — must match your Coolify domain (e.g. `https://master.your-domain.com`)
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — point at your MySQL 8 database resource (Coolify → *Databases* → *New MySQL*; the internal hostname is that resource's name)
   - `CRON_SECRET` — generate with `openssl rand -hex 32`
   - `BREVO_API_KEY`, `MAIL_FROM_EMAIL`, `MAIL_FROM_NAME` — if you want transactional email
7. **Persistent volumes** — create three:
   - `/var/www/html/uploads` — user uploads (payment proofs, docs)
   - `/var/www/html/backups` — DB backups written by the admin panel
   - `/var/www/html/logs` — PHP error log
8. **Domain & HTTPS** — assign a domain in Coolify, enable Let's Encrypt. HSTS turns on automatically.
9. **Deploy**. First boot runs migrations; watch the container logs.
10. **Cron** — in Coolify → your app → *Scheduled Tasks*:
    - `*/15 * * * *` → `php /var/www/html/cron/reminders.php`
    - `*/15 * * * *` → `php /var/www/html/cron/event_reminders.php`
    - `0 9 1 1,4,7,10 *` → `php /var/www/html/cron/quarterly-stats.php`

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
