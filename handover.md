# lintune-admin — Handover

Last updated: 2026-05-29

---

## What this is

MSP super-admin portal. Manages Keycloak realms (tenants), runs the SSH-based installer for Keycloak + Mailcow + Nextcloud AIO + Vaultwarden, and provides a Headscale VPN mesh for multi-server setups.

Stack: Laravel 13 · Filament v4.11.6 · MariaDB · Docker (php:8.4-fpm-alpine + nginx) · SSE streams for installer output.

Live at: `https://admin.dev.lintune.xyz` (dev)

---

## Current state (2026-05-29)

### UI — Filament v4 migration ✅ COMPLETE

Both lintune-admin and lintune-dash migrated from Bootstrap 5 + AdminLTE to Filament v4. Full details in [`docs/filament-migration.md`](docs/filament-migration.md).

**lintune-admin** panel at `/super`:
- Realms — live KC + DB table, toggle/edit/delete, repair federation
- Create/Edit Realm — Mailcow + Nextcloud provisioning forms, danger zone
- Service Status — Uptime Kuma cards (30s poll)
- Audit Log — cross-app log with filters
- Settings — Keycloak / Mailcow / Nextcloud config
- Backup — schedule + manual trigger
- Vaultwarden — SSO wire action

**lintune-dash** panel at `/dash`:
- Dashboard stats (KC user counts)
- Users — full KC table with mailbox/Nextcloud toggles
- Groups — Eloquent-backed CRUD, member sync to KC + NC + Mailcow
- Service Status, Audit Log

Auth: custom `SuperSessionGuard` / `TenantSessionGuard` wrapping Keycloak PKCE sessions — no Laravel `users` table involved.

### Installer — working end-to-end

Keycloak → Mailcow → Nextcloud → Vaultwarden (planned) all working.
Headscale VPN mesh: functional but has known bugs (see todo-01 below).

### Dev environment — file-mount setup ✅

No image rebuild needed for code changes. Source mounted from WSL clones into containers via `docker-compose.override.yml`. See the Dev Environment section in the root [CLAUDE.md](../CLAUDE.md) for full details.

Source is mounted directly from the Windows repo via `/mnt/c` — no sync step needed. Edit in Windows, changes are live in the container immediately (opcache disabled).

After blade/config/route changes: `docker exec lintune-lintune-admin-1 php artisan optimize:clear`

---

## Open todos

| # | File | Summary |
|---|---|---|
| 01 | (spec lost — see CLAUDE.md) | Headscale API key / URL not persisting correctly between install retries — 5 bugs, workaround applied |
| 03 | `lintune-project/tests/e2e/` | Playwright installed + 19 tests written, need a session to run --headed and fix broken selectors |
| 04 | [`tugon/todo/todo-01-support-bridge.md`](../tugon/todo/todo-01-support-bridge.md) | Tugon support bridge — full spec, not started |

---

## Known issues / active workarounds

### Headscale install (todo-01)
`HEADSCALE_API_KEY` and `HEADSCALE_URL` must be manually present in `/opt/lintune/admin.env` before running the headscale install stage. Both are currently set in the dev env.

Root causes documented in [todo/todo-01.md](todo/todo-01.md) — short version:
1. Key is appended (not replaced) on each retry → `preg_match` finds the oldest/stale key
2. Code checks `.env` file but ignores `Setting::get()` on entry
3. Retry wipes headscale (invalidating keys) but leaves stale key in `.env`
4. `HEADSCALE_URL` only gets set when the key is missing — if key is found, URL stays empty
5. Docker bind mount breaks silently if `admin.env` is replaced via `mv` instead of `cat > file`

### Docker bind mount — admin.env
Always write into the existing inode. Use `cat tmp > /opt/lintune/admin.env`, never `mv`. An `mv` (or any tool that creates a new file) breaks the bind mount silently — the container keeps reading the old unlinked inode.

---

## Architecture reminders

- **All DB migrations live in lintune-admin only.** lintune-dash has none.
- **Settings table** is shared with lintune-dash. Single source of truth for platform config.
- **`admin.env`** on host is both `env_file:` (sets process env) AND bind-mounted as `.env` (Laravel reads file directly for values added after container boot). `writeEnv()` in `InstallController` writes into the existing inode correctly.
- **SSE install streams** use `response()->stream()` + PHP output buffering. Incompatible with Livewire — keep installer views as plain Blade.
- **Installer routes** (`/install/*`) are outside the Filament panel and must stay that way — they run before setup is complete.
- **WizardComplete middleware** self-excludes `filament.super.pages.wizard*` routes to prevent redirect loops.

---

## Key file locations

| What | Where |
|---|---|
| Panel provider | `app/Providers/Filament/SuperPanelProvider.php` |
| Auth guard | `app/Auth/SuperSessionGuard.php` + `SuperUser.php` |
| Filament pages | `app/Filament/Pages/` |
| Installer | `app/Http/Controllers/InstallController.php` |
| SSH install logic | `app/Services/SshInstaller.php` |
| Headscale service | `app/Services/HeadscaleService.php` |
| DB schema | `database/migrations/` (authoritative for both apps) |
| Filament migration doc | `docs/filament-migration.md` |
| Todos | `todo/` |
