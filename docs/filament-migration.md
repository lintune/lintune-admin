# Filament v4 Migration Plan

Migrate lintune-admin and lintune-dash from Bootstrap 5 + AdminLTE to Filament v4 (Tailwind + Livewire + Alpine).

**What changes:** layouts, views, AdminLTE/Bootstrap dependency.
**What stays:** controllers, models, middleware, services, migrations, Keycloak/Mailcow/Nextcloud/SSH logic — untouched.

> **Note:** Filament v3 does not support Laravel 13. Filament v4.11.6 was installed instead.
> Local Laragon PHP lacks `ext-zip` — composer install must use `--ignore-platform-reqs` locally.
> The Docker container has `ext-zip` so the app runs fine in production.

---

## Status

### lintune-dash ✅ COMPLETE (2026-05-29)

All pages migrated. Panel live at `/dash`. Files created:

| File | Purpose |
|---|---|
| `app/Auth/TenantUser.php` | Authenticatable wrapper around KC session values |
| `app/Auth/TenantSessionGuard.php` | Custom guard — reads `session('access_token')` |
| `app/Providers/Filament/DashPanelProvider.php` | Panel config — guard, path, middleware, render hooks |
| `app/Livewire/SessionWatcher.php` | Inactivity timer + 30s session refresh |
| `app/Filament/Pages/Dashboard.php` | Dashboard with UserStatsWidget |
| `app/Filament/Widgets/UserStatsWidget.php` | Total / active / disabled user counts from KC |
| `app/Filament/Pages/ServiceStatus.php` | Uptime Kuma status cards, polls every 30s |
| `app/Filament/Pages/AuditLogs.php` | Paginated audit log table with filters |
| `app/Filament/Pages/Users.php` | KC-sourced user table + all toggle/create/delete actions |
| `app/Filament/Resources/GroupResource.php` | Groups CRUD (admin-gated via `canAccess()`) |
| `app/Filament/Resources/GroupResource/Pages/ListGroups.php` | Group list |
| `app/Filament/Resources/GroupResource/Pages/CreateGroup.php` | Create group (KC + NC wiring) |
| `app/Filament/Resources/GroupResource/Pages/ManageGroupMembers.php` | Member management page |

**Auth redirects updated:** `AuthController::callback()` and `showLogin()` redirect to `/dash`.
**Root route** (`/`) redirects to `/dash` when authenticated, `/login` otherwise.
**Old layout** (`layouts/app.blade.php`) stripped of AdminLTE — kept only for auth views during transition.

### lintune-admin ✅ COMPLETE (2026-05-29)

All pages migrated. Panel live at `/super`. Files created:

| File | Purpose |
|---|---|
| `app/Auth/SuperUser.php` | Authenticatable wrapper around KC session values |
| `app/Auth/SuperSessionGuard.php` | Custom guard — reads `session('super_access_token')` |
| `config/auth.php` | Created (was missing) — registers `super` guard |
| `app/Providers/Filament/SuperPanelProvider.php` | Panel config — guard, path, middleware, render hook |
| `app/Livewire/SuperSessionWatcher.php` | Inactivity timer + 30s session refresh |
| `app/Filament/Pages/Realms.php` | KC + DB merged realm table with toggle/edit actions |
| `app/Filament/Pages/CreateRealm.php` | Create realm form + broker federation wiring |
| `app/Filament/Pages/EditRealm.php` | Multi-form edit page: limits, Mailcow, Nextcloud, danger zone |
| `app/Filament/Pages/ServiceStatus.php` | Uptime Kuma monitor cards, polls every 30s |
| `app/Filament/Pages/AuditLogs.php` | Paginated audit log with app/user/action/date filters |
| `app/Filament/Pages/Settings.php` | Keycloak + Mailcow + Nextcloud settings form |
| `app/Filament/Pages/Backup.php` | Backup toggle + cron schedule + trigger action |
| `app/Filament/Pages/Vaultwarden.php` | Vaultwarden SSO status + wire action |

**WizardComplete middleware** updated to self-exclude `filament.super.pages.wizard*` routes (prevents redirect loop).
**Auth redirects updated:** `SuperAuthController::showLogin()` and `callback()` redirect to `/super`.
**Root route** redirects to `/super` when authenticated, `/super/login` otherwise.
**Old layout** (`super/layout.blade.php`) stripped of AdminLTE — kept only for login view.

**Note on RepairFederation:** `EditRealm::repairFederation()` redirects to the old `super.realms.repair-federation` route which is still handled by `SuperRealmController`. This keeps the 10-step repair logic in one place without duplication. The old route stays in `web.php`.

**Installer views** (`install/`) are unchanged — they cannot be Filament pages. SSE stream logic is frozen.

---

## Why Filament

Once migrated, new features (new management pages, forms, tables) are PHP classes, not Blade views. A full CRUD resource with search, filters, and modal forms is ~100 lines of PHP. AdminLTE requires a controller + routes + Blade view + custom JS per feature.

---

## Do lintune-dash first

lintune-dash is smaller, has no installer/SSE, and the auth pattern built there is reused directly in lintune-admin. Mistakes in dash don't touch the production installer.

---

## The Auth Problem (both apps)

Filament expects `Auth::user()` to return an Eloquent model. Neither app uses standard Laravel auth — both use custom session-based auth after a Keycloak PKCE flow.

**Solution: a thin custom Guard that reads from the existing session.**

Create a class implementing `Illuminate\Contracts\Auth\Guard`. Its `check()` reads the session token; its `user()` returns a plain `Authenticatable` wrapper around session values. Register it in `config/auth.php` and point the Filament panel at it via `->authGuard(...)`.

The Keycloak redirect controllers, callbacks, and logout routes stay as plain web routes — Filament does not manage the OAuth flow.

---

## lintune-dash Migration

### Phase 1 — Install & scaffold (2–3 days)

```bash
composer require filament/filament:"^3.3"
php artisan filament:install --panels
```

Configure `DashPanelProvider`:
```php
->id('dash')
->path('/dash')
->authGuard('tenant')           // custom guard wrapping session('realm'), session('access_token'), etc.
->loginRouteName('login')       // existing Keycloak redirect route
->authMiddleware([RequireAuth::class])
->sidebarCollapsibleOnDesktop()
```

Create `App\Auth\TenantSessionGuard` (wraps `session('access_token')`) and `App\Auth\TenantUser` (wraps `session('realm')`, `session('user_name')`, `session('is_realm_admin')`). Register via `Auth::extend()` in `AppServiceProvider`.

### Phase 2 — Inactivity timer (1 day)

The 5-minute inactivity timeout (JS polling `/session-check` every 30s) moves from the layout blade to a Livewire component registered via:
```php
->renderHook(PanelsRenderHook::BODY_END, fn() => Livewire::mount('session-watcher'))
```
The `SessionWatcher` component uses Alpine.js for activity tracking and calls the existing `/session-check` endpoint via `fetch()` — not a Livewire server round-trip, to avoid re-rendering the panel on every tick.

### Phase 3 — Dashboard & Status (2 days)

Dashboard stats → `StatsOverviewWidget` with `protected static ?string $pollingInterval = '30s'`.

Service status cards (Uptime Kuma, `KumaService::getStatus()`) → `ViewWidget` or custom `Page` with `wire:poll.30000ms`.

### Phase 4 — Audit Log (0.5 days)

Standard Eloquent-backed `Page` with `InteractsWithTable`. Scope query to `session('realm')` in `getTableQuery()`.

### Phase 5 — Users page (3–4 days, highest complexity)

User data comes from the Keycloak API, not Eloquent. Use `->records(Collection)` on the Filament table (Filament v3 supports in-memory collections). KC API calls mirror the current `UserController::index()` logic.

Actions (toggle mailbox, toggle Nextcloud, delete user, create user) become Filament `Action` objects on the table. The spinner overlay is replaced by Filament's built-in loading state on actions.

Note: with `->records(Collection)`, sorting/filtering is client-side. Fine for up to ~1 000 users.

### Phase 6 — Groups (2–3 days)

Groups are Eloquent-backed so this is a standard `Resource`. Scope via:
```php
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()->where('realm', session('realm'));
}
```

Authorization replacing `RequireRealmAdmin` middleware:
```php
public static function canAccess(): bool
{
    return (bool) session('is_realm_admin');
}
```

Member sync (add/remove from Mailcow alias or Keycloak group) → `Action` with a form on the edit page.

### Phase 7 — Cut over (0.5 days)

- Update `AuthController::callback()` redirect to Filament panel route
- Remove AdminLTE/Bootstrap CDN includes from any remaining views
- Delete replaced Blade views
- Check if Vite is still needed; Filament publishes its own assets via `php artisan filament:assets`

**lintune-dash total estimate: ~11 dev-days**

---

## lintune-admin Migration

### Phase 1 — Install & scaffold (2–3 days)

Same install. Configure `SuperPanelProvider`:
```php
->id('super')
->path('/super')
->authGuard('super')            // custom guard wrapping session('super_access_token')
->loginRouteName('super.login') // existing Keycloak redirect route
->authMiddleware([RequireSuperAuth::class, SetupComplete::class, WizardComplete::class])
```

The three existing middleware classes are registered on the panel's auth stack unchanged — all setup/wizard gating logic continues to work exactly as before.

Create `App\Auth\SuperSessionGuard` and `App\Auth\SuperUser` (same pattern as lintune-dash).

**WizardComplete gotcha:** `WizardComplete` redirects to `super.wizard`, which is itself a panel page. Ensure the wizard page route is excluded from `WizardComplete`'s check — same exclusion that exists on the current route group.

### Phase 2 — Session watcher + topbar status dots (1.5 days)

Session watcher: same pattern as lintune-dash (`SuperSessionWatcher` Livewire component via `renderHook`).

Navbar status dots (currently inline in `super/layout.blade.php`): move to a small Livewire component registered via `->renderHook(PanelsRenderHook::TOPBAR_END, ...)`.

### Phase 3 — Settings, Backup, Vaultwarden pages (2 days)

All three are forms — no table, no external API. Use `Page` + `InteractsWithForms`. The `mount()` method reads from `Setting::get()` and the `save()` action calls `Setting::set()`. Same logic as the current controllers.

### Phase 4 — Audit Log (0.5 days)

Same as lintune-dash but with an additional `app` filter column.

### Phase 5 — Service Status (1 day)

Custom `Page` with Livewire polling calling `KumaService::getStatus()`.

### Phase 6 — Realms resource (4 days, centrepiece)

The realm list merges DB data (`DomainRealmMap`) with Keycloak API data. Use `->records(Collection)` like the dash Users page — the collection is assembled in `getTableRecords()` by merging the two sources, exactly as `SuperRealmController::index()` does now.

Edit page: multi-section Filament form with `Section::make('Mailcow')`, `Section::make('Nextcloud')`, etc. Destructive actions (Repair Federation, Remove Mailcow, Remove Nextcloud) → `Action::make()->requiresConfirmation()->action(...)`.

Create page: maps directly to the current create-realm form and validation rules.

### Phase 7 — Wizard pages (2 days)

Post-installer wizard steps become a Filament `Wizard` component:
```php
Wizard::make([
    Wizard\Step::make('Server details')->schema([...]),
    Wizard\Step::make('Services')->schema([...]),
])
```

The SSE install progress step is a custom Livewire component embedded in a wizard step — it wraps a `<div>` that receives streamed output, leaving the `EventSource` + `response()->stream()` logic in `InstallController::stream()` completely unchanged.

### Phase 8 — Installer views (0.5 days)

The `/install/*` routes run before the Filament panel exists (before SETUP_COMPLETE). These views stay as Blade permanently — they cannot be Filament pages.

**The SSE progress page (`install/progress.blade.php`) must not change.** PHP output buffering + `flush()` is incompatible with Livewire. The `EventSource` JS and `response()->stream()` in `InstallController` are frozen.

Optional cosmetic-only change: add a Tailwind CDN link to `install/layout.blade.php` to match the panel's look. No logic changes.

New services added in future: copy the existing install stage pattern. The SSE stream, retry logic, and `CAPTURE:` output convention are all reusable as-is.

### Phase 9 — Cut over (0.5 days)

- Update `SuperAuthController::callback()` redirect to Filament panel route
- Remove AdminLTE/Bootstrap CDN includes
- Delete replaced Blade views (keep all `install/` views)

**lintune-admin total estimate: ~14 dev-days**

---

## Page-to-Filament Concept Quick Reference

| Current page | Filament concept |
|---|---|
| Simple form page (settings, backup) | `Page` + `InteractsWithForms` |
| Read-only paginated table (audit log) | `Page` + `InteractsWithTable` |
| Eloquent CRUD (groups) | `Resource` |
| API-sourced table (users, realms) | `Page` + `InteractsWithTable` + `->records(Collection)` |
| Dashboard stats | `StatsOverviewWidget` |
| Polling status cards | `ViewWidget` with `$pollingInterval` |
| Multi-step form (wizard) | `Page` with `Wizard` form component |
| SSE stream (installer progress) | Stays as Blade — do not migrate |

---

## Filament v3 Key APIs

- **PanelProvider** — `->authGuard()`, `->authMiddleware()`, `->loginRouteName()`, `->discoverResources()`, `->discoverPages()`, `->renderHook()`
- **Resource** — `form()`, `table()`, `getPages()`, `getEloquentQuery()`, `canAccess()`
- **Page** — `$view`, `InteractsWithForms`, `InteractsWithTable`, `$navigationLabel`, `$navigationGroup`
- **StatsOverviewWidget** — `getStats()` returning `Stat` objects, `$pollingInterval`
- **Action** — `->requiresConfirmation()`, `->form([...])`, `->action(fn($record, $data) => ...)`
- **Table** — `->records(Collection)` for non-Eloquent data, `->columns()`, `->actions()`, `->headerActions()`, `->filters()`

Full docs: https://filamentphp.com/docs
