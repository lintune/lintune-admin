# lintune-admin — Claude Context

## What this repo is
Super-admin portal for the MSP operator only — never by end customers.
Handles realm provisioning, service integration setup, and platform-level configuration.

## Repo structure
```
app/Http/Controllers/Super/   — all controllers (SuperRealmController, SettingsController, etc.)
app/Http/Middleware/           — RequireSuperAuth, SetupComplete, WizardComplete
app/Models/                   — DomainRealmMap, RealmConfig, Setting, Mailbox, NextcloudUser, AuditLog
app/Services/                 — AuditLogger, MailcowService, NextcloudService, SshInstaller
resources/views/super/        — all Blade views (layout.blade.php, realms.blade.php, realm-edit.blade.php, etc.)
resources/views/install/      — installer views (welcome, server-type, configure, progress, done, manual)
routes/web.php                — all routes under /install (pre-auth) and /super prefix (guarded by RequireSuperAuth)
database/migrations/          — ALL migrations live here (never in lintune-dash)
```

## Architecture rules
- **All database migrations live exclusively here.** Never create migrations in lintune-dash, even if the table is only used by dash.
- The `settings` table is the single source of truth for platform-wide config. Never hardcode platform config values in controllers or views — always use `Setting::get()`.
- This app is for platform operators only. Never build customer/tenant-facing features here.
- lintune-dash is scoped to one realm per session. This app manages across all realms.
- Realm provisioning, Mailcow domain setup, Nextcloud group setup, and Keycloak realm creation are exclusively this app's responsibility.

## Database
- Shared MariaDB with lintune-dash. Database name: `lintune`.
- Key tables owned here: `domain_realm_maps`, `realm_configs`, `settings`, `audit_logs`, `mailboxes`, `nextcloud_users`, `groups`, `group_members`, `sessions`.
- `domain_realm_maps` — per-realm flags (mailcow_enabled, nextcloud_enabled) and limits (max_users, max_mailbox_users, max_nextcloud_users).
- `realm_configs` — encrypted per-realm overrides for service credentials (mailcow.url, mailcow.api_key, nextcloud.url, etc.).
- `settings` — platform-wide key/value config, sensitive values stored encrypted.

## Key models
- `DomainRealmMap` — one row per realm, holds enabled flags and user limits.
- `RealmConfig::get($realm, $key)` / `::set($realm, $key, $value, $encrypted)` — per-realm config with optional encryption.
- `Setting::get($key, $default)` / `::set($key, $value, $encrypted)` — platform-wide settings.

## Security rules
- Sensitive values in `settings` and `realm_configs` must use `encrypted = true`. Use Laravel's `encrypt()`/`decrypt()`.
- Never store plaintext credentials, API keys, or passwords in the codebase or database.
- Always validate and sanitize input with Laravel's `validate()` and explicit rules.
- Lowercase and trim realm names and email addresses before use.

## API conventions
- Always `rtrim($base, '/')` when building Keycloak, Mailcow, or Nextcloud API URLs. Never assume no trailing slash.
- Keycloak admin token obtained via `adminToken()` — password grant against master realm using admin-cli.
- Mailcow API uses `X-API-Key` header.
- Nextcloud uses Basic Auth + `OCS-APIRequest: true` header.

## UI stack
- Bootstrap 5.3.3 + Bootstrap Icons 1.11.3 + AdminLTE 4.0.0-rc2.
- Layout: `resources/views/super/layout.blade.php` — includes session timer, spinner overlay, sidebar nav.
- Spinner overlay: shown automatically on any form submit. Add `data-no-spinner` to forms that should skip it (e.g. logout).
- Use `@push('scripts')` for page-specific JS.

## Install flow (pre-auth, /install/*)

The installer runs before SETUP_COMPLETE is set and is blocked afterward.

**Stages** — keycloak is always first; mailcow and nextcloud are optional depending on what the operator chose on the configure screen:
1. `keycloak` — ensureDocker → installKeycloak → wait for KC ready → setupKeycloak (OIDC client, broker realm, service account, writes .env)
2. `mailcow` — ensureDocker → installMailcow → Setting::set mailcow.url
3. `nextcloud` — ensureDocker → installNextcloud → Setting::set nextcloud.url + saveNcServiceCredentials

**SSE stream** — `GET /install/stream/{key}` with query params:
- `?stage=keycloak|mailcow|nextcloud` (default: keycloak)
- `?retry=1` — triggers cleanup (down + rm -rf install dir) before reinstalling

After a non-final stage the SSE emits `done` with `{next_stage: 'mailcow'}` so the frontend shows a "Continue" button. After the last stage it emits `done` with `{redirect: '...'}`.

**Cache keys** (all keyed by UUID from `run()`):
- `install_params:{key}` — full form params, 2h TTL, kept until last stage completes
- `install_log:{key}` — accumulated log across stages, 2h TTL
- `install_result:{key}` — final log + kcUrl + adminUsername, 10 min TTL, read by done page

**`writeEnv()`** — writes to `/var/www/html/lintune-admin/.env` which is volume-mounted from
`/opt/lintune/admin.env` on the host. Changes persist across container recreations.
`SETUP_COMPLETE` must NOT exist in `admin.env` before install; a blank OS env var (set by
Docker's `env_file:` at container start) would prevent Laravel's immutable Dotenv from
loading the file-written `SETUP_COMPLETE=true` on the same container run.

## SshInstaller service

`app/Services/SshInstaller.php` — runs bash scripts on remote servers over SSH via phpseclib3.

**Key behaviour:**
- `execScript()` uses the phpseclib callback form of `exec()` so output streams to the browser line-by-line as it arrives, not buffered until the command exits. Critical for docker pull progress.
- Lines matching `CAPTURE:key:value` are stored silently in `$captured[]` (used for Nextcloud admin password).
- Non-root users: commands are prefixed with `sudo`.
- Avoids sequential `exec()` calls on the same channel (phpseclib3 channel-reuse bug) — all work is done in a single `exec()` per logical operation.

**Public methods:**
- `ensureDocker()` — installs Docker via get.docker.com if missing.
- `installKeycloak($user, $pass, $port, $hostname, $clean=false)` — docker-compose up in `/opt/keycloak`; post-start clears temp-admin flag via `kcadm.sh set-password --temporary false` inside the container.
- `installMailcow($hostname, $tz, $clean=false)` — clones mailcow-dockerized, runs generate_config.sh; pulls each service image **individually** (sequential loop over `docker compose config --services`) so the terminal shows per-image progress.
- `installNextcloud($domain, $tz, $clean=false)` — Nextcloud AIO mastercontainer; configures domain+timezone via AIO REST API; captures admin password.
- `$clean=true` wipes the install directory (docker compose down + rm -rf) before reinstalling. Used when the installer frontend sends `?retry=1`.

## Middleware
- `RequireSuperAuth` — checks `session('super_access_token')`; redirects to super.login if absent.
- `SetupComplete` — blocks `/super/setup` when setup is done; redirects all other `/super/*` routes to `/install` when setup is not done.
- `WizardComplete` — checks `Setting::get('wizard.complete')`; redirects to wizard if false. Applied to realm management, settings, and audit log routes.

## What NOT to do
- Do not create migrations in lintune-dash.
- Do not build cross-realm queries in lintune-dash.
- Do not hardcode Keycloak/Mailcow/Nextcloud URLs — always read from config or `Setting::get()`.
- Do not duplicate platform config into lintune-dash's own config files.
- Do not delete `install_params:{key}` from cache at the start of a stream — params must persist across all stages. Only the last stage cleans them up.
- Do not call `docker compose pull` for all Mailcow services at once — pull one service at a time with a loop so the terminal shows progress per image.
