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

## Mail behavior
- Production: native PHP `mail()` via host local MTA (cPanel typical setup)
- Development: file log transport to `var/log/mail.log`

Sender values come from environment/private config:
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME`

## cPanel deployment notes
1. Upload code so private dirs (`config`, `src`, `database`, `templates`, `var`) are outside/alongside the web root.
2. Set document root to `public/`.
3. Ensure writable permissions for:
   - `var/data`
   - `var/log`
4. Create `config/local.php` (not committed) for host-specific overrides.
5. Confirm local MTA works with PHP `mail()`.
6. Configure SPF/DKIM/DMARC for sender domain.
7. Run `php bin/migrate.php` after deploy.
8. Add routine backup for `var/data/app.sqlite`.

## Security notes
- CSRF token required for all POST actions
- Session strict mode + HttpOnly + SameSite=Lax cookies
- Session ID regeneration on successful auth
- Mail headers sanitized to prevent header injection via newlines

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
