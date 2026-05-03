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
1. `keycloak` — ensureDocker → installKeycloak → wait for KC ready → setupKeycloak (OIDC client, broker realm, service account, writes .env) → **configureBrokerHomeIdpDiscovery** (Organizations enabled, `broker-home-idp-discovery` browser flow created and bound)
2. `mailcow` — ensureDocker → installMailcow (injects API_KEY + API_ALLOW_FROM into mailcow.conf, captures key) → postConfigureMailcow (new superadmin via DB, delete default admin) → Setting::set mailcow.url + mailcow.api_key (encrypted)
3. `nextcloud` — ensureDocker → installNextcloud (5-phase, creates lintune-svc + operator accounts) → create `nextcloud` OIDC client in Keycloak broker realm + add `nc_groups` attribute mapper on it → configureNextcloudOidc (installs user_oidc app, runs occ user_oidc:provider with group restriction) → Setting::set nextcloud credentials + oidc_client_id + oidc_client_secret

**SSE stream** — `GET /install/stream/{key}` with query params:
- `?stage=keycloak|mailcow|nextcloud` (default: keycloak)
- `?retry=1` — triggers cleanup (down + rm -rf install dir) before reinstalling

After a non-final stage the SSE emits `done` with `{next_stage: 'mailcow'}` so the frontend shows a "Continue" button. After the last stage it emits `done` with `{redirect: '...'}` and the JS shows a "View Summary" button — it does NOT auto-redirect, giving the operator time to read the log.

**`SETUP_COMPLETE` is written by `done()`, NOT by the SSE stream.** Writing it inside the stream causes a 404 on the done page: the browser's GET to `/install/done` hits the constructor which calls `abort(404)` when SETUP_COMPLETE is already true.

**`done()` also sets `wizard.complete`** in the DB (`Setting::set('wizard.complete', '1')`), so the post-login wizard is bypassed when the guided installer was used.

**`SetupComplete` middleware** reads the `.env` file directly (via `isSetupComplete()`) rather than `config('setup.complete')`. PHP-FPM workers cache env vars at spawn time — writing `SETUP_COMPLETE=true` to the file during the install would otherwise not be visible until the container restarted.

**Cache keys** (all keyed by UUID from `run()`):
- `install_params:{key}` — full form params, 2h TTL, kept until last stage completes
- `install_log:{key}` — accumulated log across stages, 2h TTL
- `install_result:{key}` — final log + kcUrl + adminUsername, 30 min TTL, read by done page

**`writeEnv()`** — writes to `/var/www/html/lintune-admin/.env` which is volume-mounted from
`/opt/lintune/admin.env` on the host. Changes persist across container recreations.
`SETUP_COMPLETE` must NOT exist in `admin.env` before install; a blank OS env var (set by
Docker's `env_file:` at container start) would prevent Laravel's immutable Dotenv from
loading the file-written `SETUP_COMPLETE=true` on the same container run.

**`writeInstallLog()`** — writes `{8charkey}_{stage}.log` to `storage/logs/install/` (volume-mounted from `/opt/lintune/logs/` on the host). Called in the catch block of `stream()` after emitting the error event (not before — if it throws, the error event must already be sent). On retry, appends with a separator header rather than overwriting.

## SshInstaller service

`app/Services/SshInstaller.php` — runs bash scripts on remote servers over SSH via phpseclib3.

**Key behaviour:**
- `execScript()` wraps the script in `bash << 'LINTUNE_EOF'` with `set -e` and `exec 2>&1` at the top, so stderr is captured and streamed alongside stdout. Uses the phpseclib callback form of `exec()` so output streams line-by-line as it arrives, not buffered until exit. Critical for docker pull progress.
- Lines matching `CAPTURE:key:value` are stored silently in `$captured[]` and not emitted to the terminal.
- Non-root users: commands are prefixed with `sudo`.
- Avoids sequential `exec()` calls on the same channel (phpseclib3 channel-reuse bug) — all work is done in a single `exec()` per logical operation.
- Credentials injected into bash scripts via base64 (`base64_encode()` in PHP, `printf '%s' 'B64' | base64 -d` in bash) to safely handle special characters.
- **All `docker compose exec` calls inside heredoc scripts must include `< /dev/null`.** The heredoc itself is stdin for the bash process; without `/dev/null` redirection, `docker exec` consumes the remaining heredoc as its own stdin and hangs.

**Public methods:**
- `ensureDocker()` — installs Docker via get.docker.com if missing.
- `installKeycloak($user, $pass, $port, $hostname, $clean=false)` — docker-compose up in `/opt/keycloak`; post-start clears temp-admin flag via `kcadm.sh set-password` (without `--temporary` flag — omitting it is what makes the password permanent; passing `--temporary false` is incorrect syntax and has no effect).
- `installMailcow($hostname, $tz, $clean=false)` — clones mailcow-dockerized, runs `generate_config.sh`; appends `API_KEY` (5×6 uppercase alphanumeric, generated via `/dev/urandom`) and `API_ALLOW_FROM=127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16` to `mailcow.conf` before containers start; emits `CAPTURE:mailcow_api_key`; pulls each service image **individually** (sequential loop over `docker compose config --services`); after `docker compose up -d`, removes `API_KEY` and `API_ALLOW_FROM` from `mailcow.conf` (Mailcow has already persisted them to its DB on first boot).
- `installNextcloud($domain, $tz, $clean=false, $adminUsername='', $adminPassword='')` — Nextcloud AIO mastercontainer via docker-compose in `/opt/nextcloud-aio`. Five-phase flow:
  1. **Mastercontainer up** — polls `wget https://localhost:9080/` until AIO responds; the first page load generates the AIO passphrase in `/var/lib/docker/volumes/nextcloud_aio_mastercontainer/_data/data/configuration.json` (key `"password"`). Captured as `nc_aio_pass`.
  2. **Config file written** — uses `jq` to merge domain, timezone, `apache_port: "11000"`, `apache_ip_binding: "0.0.0.0"`, `borg_restore_password: ""`, and `wasStartButtonClicked: true` into `configuration.json`, deleting any existing `secrets` block so AIO regenerates them. Ownership fixed via `docker exec -u root ... chown www-data:www-data`.
  3. **Pull + start** — runs `PullContainerImages.php` then `StartContainers.php` via `docker exec -u www-data` (NOT `sudo -u www-data` inside the container — sudo may not be configured in AIO). Both scripts are silent; progress shown by backgrounding `docker events` filtered to `image pull` and `container create/start` events respectively. Background process killed with `|| true` on `wait` to absorb the SIGTERM exit code under `set -e`.
  4. **Wait + service account** — polls `occ status --output=json | jq '.installed'` (60×10s) until Nextcloud is ready. Reads `secrets.NEXTCLOUD_PASSWORD` from `configuration.json` (captured as `nc_admin_pass`). Creates `lintune-svc` service account via `docker exec -u www-data ... php occ user:add --password-from-env --group="admin"` with `openssl rand -hex 24` password (captured as `nc_svc_pass`).
  5. **Operator account** — creates the MSP operator's own Nextcloud account (same username/password as the install form) via `occ user:add --group="admin"`.
  
  `InstallController::runNextcloudStage()` saves captured values to `settings` encrypted: `nextcloud.aio_passphrase`, `nextcloud.admin_password`, `nextcloud.service_user` (`lintune-svc`), `nextcloud.service_password`. Then creates a `nextcloud` OIDC client in Keycloak's broker realm, adds the `nc_groups` attribute mapper to it, and calls `configureNextcloudOidc`. Saves `nextcloud.oidc_client_id` and `nextcloud.oidc_client_secret` to `settings`.
- `configureNextcloudOidc($kcBaseUrl, $brokerRealm, $clientId, $clientSecret)` — installs the `user_oidc` app via `occ app:install user_oidc` (falls back to `app:enable` if already installed), then runs `occ user_oidc:provider Keycloak` with the discovery URI pointing at `{kcBaseUrl}/realms/{brokerRealm}/.well-known/openid-configuration`. Credentials injected via base64. Key flags: `--mapping-uid=email` (use email claim as NC user ID, not the Keycloak UUID sub claim), `--unique-uid=0` (no provider-name suffix appended), `--mapping-groups=groups` (read group names from the `groups` JWT claim), `--group-restrict-login-to-whitelist=1` (only allow login if user is in a whitelisted group), `--group-whitelist-regex=nextcloud` (the whitelist is the literal `nextcloud` group). Only users in the `nextcloud` KC group in their tenant realm can log in; others are blocked at the user_oidc layer before NC even creates an account.
- `postConfigureMailcow($adminUsername, $adminPassword)` — runs after `installMailcow()`; polls for the default `admin` row in MySQL (polling on admin row existence, not just MySQL ping — Mailcow's PHP init scripts run after MySQL accepts connections), hashes the password via `doveadm pw -s SSHA256` (dovecot-mailcow container, `< /dev/null` required to prevent heredoc stdin hang), inserts the new superadmin, then deletes the default admin and all associated rows (`tfa`, `domain_admins`, `admin`). No API involvement — pure DB operations.
  > **TODO (pre-production):** The Mailcow superadmin currently reuses the lintune-admin operator credentials (`admin_username` / `admin_password` from the install form). For production, `postConfigureMailcow` should generate its own random password and store it encrypted in `settings` (like Nextcloud does), so the Mailcow web UI is not protected by the same secret as the lintune-admin panel.
- `$clean=true` wipes the install directory (docker compose down + rm -rf) before reinstalling. Used when the installer frontend sends `?retry=1`.

## Home IdP Discovery (broker realm)

Configured during the Keycloak install stage via `configureBrokerHomeIdpDiscovery()` in `InstallController`.

**How it works:**
1. Keycloak Organizations is enabled on the broker realm.
2. A custom browser flow `broker-home-idp-discovery` is created with three ALTERNATIVE executors: `auth-cookie` → `identity-provider-redirector` → `organization`.
3. The broker realm's browser login is bound to this flow.
4. When a realm is provisioned (`setupBrokerFederation`), a Keycloak Organization is created in the broker realm with `realm` as both the name/alias and the email domain. Then the IdP is created (without `organizationId`), and finally linked to the org via `POST /organizations/{id}/identity-providers` with the alias as a JSON string body.
5. At login time, the `organization` authenticator shows an email form, extracts the domain, finds the Organization, and automatically redirects to its linked IdP — no manual IdP picker, no membership check required.

**IdP→Org linking — critical details:**
- Do NOT set `organizationId` on the IdP during creation. Keycloak stores it as a representation field but does NOT add the internal `kc.org` config key that the `organization` authenticator reads. `GET /organizations/{id}/identity-providers` returns `[]` and routing never fires.
- The correct link is `POST /admin/realms/{brokerRealm}/organizations/{orgId}/identity-providers` with body `"alias"` (a quoted JSON string — e.g. `"nexed.tech"`). This sets both `organizationId` on the IdP AND the `kc.org` config key.
- `PUT /organizations/{id}/identity-providers/{alias}` returns 405 in KC26 — endpoint does not exist.
- **clientSecret on IdP PUT**: KC GET returns `clientSecret: "**********"` (masked). Omitting it on PUT **clears** the stored secret; writing `"**********"` back **corrupts** it. Always write the real secret: use the value from `GET /clients/{id}/client-secret` (or regenerate via `POST /clients/{id}/client-secret`) and include it explicitly in every IdP PUT.

**Key IdP config:**
- `kc.org.domain: realm` — email domain this IdP handles.
- `kc.org.domain: realm` — **critical**: `OrganizationAuthenticator.redirect()` reads `broker.getConfig().get("kc.org.domain")` and compares it case-insensitively with the user's email domain. If missing, the comparison returns false and no redirect fires — the "Your email domain matches an organization but you don't have an account yet" page is shown instead. **The org-link (`POST /organizations/{id}/identity-providers`) strips this key from the config.** A PUT must be issued after the link to restore both `kc.org.domain` and `kc.org.broker.redirect.mode.email-matches`.
- `kc.org.broker.redirect.mode.email-matches: true` — `IdentityProviderRedirectMode.EMAIL_MATCH.isSet()` calls `Boolean.parseBoolean(config.get("kc.org.broker.redirect.mode.email-matches"))`. Must be the string `"true"`. Also stripped by the org-link and must be restored via PUT.
- `loginHint: true` — forwards the collected email as `login_hint` to the tenant realm so the username field is pre-filled.
- `hideOnLogin: true` — hides the manual IdP button from the login page (routing is automatic).

**KC 26.6+ requires execution config on the `organization` authenticator.** Set `requiresUserMembership: false` via `POST /authentication/executions/{id}/config` — without it the default (true) blocks non-members (new users) instead of redirecting them to their IdP, causing "Your email domain matches an organization but you don't have an account yet." Do NOT add `useHomeIdpDiscovery` — that was a KC 26.0 non-existent property that causes fall-through and "Invalid username or password".

**Realm provisioning adds:**
- An OIDC IdP in the broker realm pointing at the tenant realm
- A Keycloak Organization in the broker realm with `domain = realm` (e.g. `company.com`) and **`redirectMode: EMAIL_DOMAIN`** (KC 26.1+ requirement — without it the org authenticator defaults to `IMPLICIT` which only redirects existing members, showing "Your email domain matches an organization but you don't have an account yet" to new users)
- The IdP linked to that Organization via `POST /organizations/{orgId}/identity-providers` with body `"realm"` (JSON string)

**Realm deletion cleans up:**
- The Organization is deleted from the broker realm (which also unlinks the IdP)
- The IdP instance is deleted from the broker realm

**Non-fatal:** if Keycloak does not support Organizations (pre-25), the setup logs a warning and continues. The rest of the install still succeeds; Home IdP Discovery simply won't be active.

## Nextcloud access control via Keycloak groups

Nextcloud access is gated by membership in a `nextcloud` group in the user's tenant realm. The claim is passed through a 3-step chain so that user_oidc in Nextcloud can enforce it.

**Chain (set up at provisioning time):**
1. **Tenant realm** has a `nextcloud` KC group. `broker-realm-client` (the OIDC client the broker IdP uses to authenticate against the tenant) has an `oidc-group-membership-mapper` that adds all group names to a `groups` claim in the access token.
2. **Broker realm user profile** must declare `nc_groups` as a custom attribute (`multivalued: true`). KC 26.x uses declarative user profile — any attribute not declared in the realm's user profile schema is **silently dropped** even when set by an IdP mapper. `setupBrokerFederation()` and `repairFederation()` both ensure this via `PUT /admin/realms/{brokerRealm}/users/profile`.
3. **Broker realm IdP** (the IdP for this tenant) has an `oidc-user-attribute-idp-mapper` named `nc_groups` that reads the `groups` claim from the tenant token and stores it as the `nc_groups` user attribute on the broker realm user (syncMode FORCE, so it updates on every login).
4. **Broker realm `nextcloud` OIDC client** has an `oidc-usermodel-attribute-mapper` named `nc_groups` that reads the `nc_groups` user attribute and emits it as a multivalued `groups` claim in the ID token, access token, and userinfo. **Must have `id.token.claim: true`** — `user_oidc`'s `getSyncGroupsOfToken()` reads `$idTokenPayload`; if the claim is absent from the ID token, `foreach()` crashes with "argument must be of type array|object, null given" and the login fails with "Unexpected error".

**Nextcloud side:**
- `user_oidc` configured with `--mapping-groups=groups --group-restrict-login-to-whitelist=1 --group-whitelist-regex=nextcloud`.
- On login, user_oidc checks if the JWT `groups` claim contains `nextcloud`. If not, login is rejected before NC creates an account.
- If yes, NC auto-provisions the account on first login (no pre-provisioning needed).

**Admin operations (lintune-dash `UserController::toggleNextcloud()`):**
- Enable → `PUT /admin/realms/{realm}/users/{userId}/groups/{groupId}` (add to `nextcloud` KC group). No NC OCS API call.
- Disable → `DELETE /admin/realms/{realm}/users/{userId}/groups/{groupId}` (remove from KC group) + `DELETE ocs/v1.php/cloud/users/{email}` (clean up NC account).
- State is derived from KC group membership, NOT from the `nextcloud_users` DB table.

**Super admin NC count (`SuperRealmController::index()`):**
- Per-realm NC user count is fetched from KC: `GET /admin/realms/{realm}/groups?search=nextcloud` → `GET /admin/realms/{realm}/groups/{id}/members?max=1000`. Only done for NC-enabled realms.

**Where each piece is provisioned:**
- `nextcloud` group in tenant realm: `setupBrokerFederation()` in `SuperRealmController`
- `oidc-group-membership-mapper` on `broker-realm-client`: `setupBrokerFederation()`
- `oidc-user-attribute-idp-mapper` (`nc_groups`) on broker IdP: `setupBrokerFederation()`
- `oidc-usermodel-attribute-mapper` (`nc_groups`) on broker `nextcloud` client: `runNextcloudStage()` in `InstallController`

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
- Do not write `SETUP_COMPLETE=true` inside the SSE stream — write it in `done()` only. Writing it during the stream causes a 404 when the browser's GET to `/install/done` hits the constructor's `abort(404)` guard.
- Do not emit the error SSE event after attempting `writeInstallLog()` — emit error first, then wrap the log write in its own try/catch. If the log write throws before the error event, the browser sees a silent "lost connection".
