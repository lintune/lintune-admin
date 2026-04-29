# lintune-admin — Claude Context

## What this repo is
Super-admin portal for the Lintune platform. Used by the MSP operator only — never by end customers.
Handles realm provisioning, service integration setup, and platform-level configuration.

## Repo structure
```
app/Http/Controllers/Super/   — all controllers (SuperRealmController, SettingsController, etc.)
app/Http/Middleware/           — RequireSuperAuth, SetupComplete
app/Models/                   — DomainRealmMap, RealmConfig, Setting, Mailbox, NextcloudUser, AuditLog
app/Services/                 — AuditLogger, MailcowService, NextcloudService
resources/views/super/        — all Blade views (layout.blade.php, realms.blade.php, realm-edit.blade.php, etc.)
routes/web.php                — all routes under /super prefix, guarded by RequireSuperAuth
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

## What NOT to do
- Do not create migrations in lintune-dash.
- Do not build cross-realm queries in lintune-dash.
- Do not hardcode Keycloak/Mailcow/Nextcloud URLs — always read from config or `Setting::get()`.
- Do not duplicate platform config into lintune-dash's own config files.
