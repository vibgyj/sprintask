# Sprintask

Deployment-ready vanilla PHP 8.2 + SQLite time tracker with passwordless OTP auth.

## Stack
- PHP 8.2+ (no framework, no Composer dependencies)
- SQLite (PDO)
- Minimal vanilla JS + custom CSS
- Docker (local) + cPanel-ready layout

## Directory layout
- `public/` → **only** web root (set as document root)
- `config/` → app config
- `database/` → schema
- `src/` → services + routing
- `templates/` → server-rendered views
- `var/data/app.sqlite` → private DB file (outside public)
- `var/log/mail.log` → private dev mail log (outside public)

## Deployment modes
- **Root-domain / dedicated-domain mode (existing default):** set web docroot to `public/`.
- **Split cPanel subdirectory mode (new):** keep private app outside `public_html`, expose only front-controller/assets under a URL subdirectory (for example `/sprintask/`).

### How app-root resolution works
- `public/index.php` resolves application root in this order:
  1. `SPRINTASK_ROOT` from real server environment (`getenv` / server env)
  2. fallback: parent directory of the public folder (`dirname(__DIR__)` behavior)
- It then requires `<app-root>/src/bootstrap.php`.
- If bootstrap is missing, it returns HTTP 500 with a safe misconfiguration message.
- No host-specific absolute path is committed in repository code.

### How mount/base-path resolution works
- Routing and URL generation derive base path from runtime script location (for example `/sprintask/index.php` => `/sprintask`, `/index.php` => root).
- `APP_BASE_URL` path component is optional and acts as explicit override.
- Redirects, links, form actions, and static asset URLs are generated with this base path so both `/` and `/sprintask/` deployments work.

## Features
- Passwordless register/login with 6-digit OTP
  - hashed OTP, single-use, 10-minute expiry
  - resend cooldown, max attempts, email/IP request throttles
  - prior active challenge replaced on resend
- Category/task CRUD scoped to authenticated user
- Timer start/stop + manual entry add/edit/delete
- One active timer per user via SQLite partial unique index
- Transactional timer and destructive delete operations
- Composite ownership FKs with cascades
- Week/month reports with timezone-aware clipping in PHP
  - Monday-start weeks
  - period clipping for overlaps and running timer
- Theme: system default + persisted light/dark override
- CSRF protection + strict session settings + session regeneration on login

## Local setup (Docker)
1. Copy `.env.example` to `.env` and adjust values.
2. Run:
   - `docker compose up --build`
3. Open `http://localhost:8080`

> Note: this app is vanilla PHP and does **not** parse `.env` itself. Docker exports variables from `.env`; plain cPanel upload of a `.env` file alone does nothing.

Persistence:
- `compose.yaml` bind-mounts `./var/data` and `./var/log`

## Local setup (without Docker)
1. Ensure PHP 8.2+ with `pdo_sqlite` enabled.
2. Create writable directories:
   - `var/data`
   - `var/log`
3. Run migration:
   - `php bin/migrate.php`
4. Serve `public/` as docroot.

If using PHP built-in server for quick local checks:
- `php -S 127.0.0.1:8080 -t public public/index.php`

## Mail behavior
- Production: native PHP `mail()` via host local MTA (cPanel typical setup)
- Development/local OTP emails (including one-time codes) are written to `var/log/mail.log`

Inspect/follow OTP mail log:
- Host: `tail -n 20 var/log/mail.log` (inspect), `tail -f var/log/mail.log` (follow)
- Docker (`app` service): `docker compose exec app sh -lc 'tail -n 20 /var/www/html/var/log/mail.log'` (inspect), `docker compose exec app sh -lc 'tail -f /var/www/html/var/log/mail.log'` (follow)

Sender values come from environment/private config:
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME`

## cPanel split deployment (served at `https://example.com/sprintask/`)

Target layout:
- Private app root: `/home/cpanel-user/private-app`
- Public URL directory: `/home/cpanel-user/public_html/example-site/sprintask`

### 1) Place private application files outside web root
Copy these into `/home/cpanel-user/private-app`:
- `bin/`
- `config/`
- `database/`
- `src/`
- `templates/`
- `var/`

### 2) Place only public files in URL directory
Copy these into `/home/cpanel-user/public_html/example-site/sprintask`:
- `index.php`
- `.htaccess`
- `bootstrap_resolver.php`
- `assets/`

Never copy these private directories under the public URL directory: `bin/`, `config/`, `database/`, `src/`, `templates/`, `var/`.

### 3) Configure `SPRINTASK_ROOT` in real server environment
Set to:
- `/home/cpanel-user/private-app`

Recommended options depend on host:
- cPanel/host UI env-variable support (preferred)
- Apache include/vhost-level env config (host-managed)
- optional `.htaccess SetEnv` only if host allows it

`SetEnv` is host-dependent because `.htaccess` directives are ignored when Apache `AllowOverride None` is in effect.

### 4) Configure app URL + private overrides
Create `/home/cpanel-user/private-app/config/local.php` (not committed), for example:

```php
<?php

return [
    'app' => [
        'base_url' => 'https://example.com/sprintask/',
    ],
    // Optional host-specific overrides:
    // 'mail' => [...],
    // 'db' => ['path' => '/home/cpanel-user/private-app/var/data/app.sqlite'],
];
```

### 5) Permissions
Ensure PHP user can write:
- `/home/cpanel-user/private-app/var/data`
- `/home/cpanel-user/private-app/var/log`

### 6) Initialize schema (no Terminal/SSH)
- For this current app, a normal browser request is enough: `src/bootstrap.php` runs `Database::migrate()` during app bootstrap.
- The current `database/schema.sql` is repeat-safe for startup because it uses `CREATE TABLE IF NOT EXISTS` and `CREATE INDEX IF NOT EXISTS`.
- After upload + permissions, open the app once in the browser to initialize/update the SQLite schema.

If your host provides shell access, CLI migration is still available from private root:
- `php bin/migrate.php`

⚠️ This browser-startup behavior is specific to the current schema/bootstrap design. Future non-idempotent schema changes (for example `ALTER`/data backfills) still need an explicit migration path.

⚠️ Never publish `bin/migrate.php` on the web (even temporarily). It is a CLI-oriented script with no web auth guard.

### 7) Verify HTTP behavior
Expected success:
- `https://example.com/sprintask/`
- `https://example.com/sprintask/reports`

Expected non-exposure (not downloadable source/data):
- `https://example.com/sprintask/config/local.php`
- `https://example.com/sprintask/database/schema.sql`
- `https://example.com/sprintask/var/data/app.sqlite`

These should return 403/404 (or your host's equivalent deny response), never file contents.

## Future dedicated-domain deployment
When moving to a dedicated domain/subdomain that can point directly at `public/`:
- Set document root to `<repo-or-app-root>/public`
- Unset `SPRINTASK_ROOT` (or leave empty)
- Set `APP_BASE_URL`/`app.base_url` path to `/` (for example `https://app.example.com/`)
- Keep private directories outside `public/` as before

## Security notes
- CSRF token required for all POST actions
- Session strict mode + HttpOnly + SameSite=Lax cookies
- Session ID regeneration on successful auth
- Mail headers sanitized to prevent header injection via newlines
- Split deployment keeps non-public files outside the web root boundary
- `.htaccess` behavior is host-dependent (`AllowOverride`); test it explicitly

## Tests
Run dependency-free tests:

```bash
php tests/run.php
```

Coverage includes:
- OTP lifecycle + dev mail logging
- ownership enforcement and cascades
- one-active-timer invariant
- report aggregation/clipping across DST boundary
- base-path-aware routing/link generation for `/` and `/sprintask`
- app-root resolution default/override behavior for public entrypoint
