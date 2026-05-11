# Lintune Admin

> ⚠️ Heavy alpha — not production ready. Expect breaking changes.

Lintune Admin is the super admin portal for the Lintune platform — an open-source, self-hosted alternative to Microsoft 365 / Azure AD for MSPs and self-hosters, built entirely on open-source components.

| Component | Role | Status |
|---|---|---|
| [Keycloak](https://www.keycloak.org/) | Identity provider — realms, users, SSO | Working |
| [Mailcow](https://mailcow.email/) | Email server — domains, mailboxes | Working |
| [Nextcloud](https://nextcloud.com/) | File storage & collaboration | Basic integration working |
| Vaultwarden | Password manager — per-tenant organisations | Planned |
| PowerDNS / Cloudflare | DNS management — zones, records | Planned |
| SSSD / Keycloak LDAP | Workstation login — Linux & Windows | Planned |

This repo is the **super admin layer**. It is not customer-facing. Only platform operators (MSPs) use it.

---

## What it does

- Runs a **browser-based setup wizard** that installs Keycloak (and optionally Mailcow and Nextcloud) on remote servers via SSH — no command line needed after the initial bootstrap script
- Creates a new **Keycloak realm** per tenant (organisation)
- Maps a **domain** to that realm so users can log in via their own domain
- Creates the **initial admin user** for the tenant in Keycloak
- Registers the new realm as an **Identity Provider in the broker realm**, federating all tenants under one Keycloak entry point
- Optionally enables **Mailcow** for the tenant — provisioning the email domain and per-tenant mailbox limits
- Optionally enables **Nextcloud** for the tenant — provisioning a user group
- Manages the `domain_realm_map` table that both this app and [lintune-dash](../lintune-dash) rely on for realm lookups
- Provides enable/disable toggles per realm and per integration
- Logs all operator actions to a full audit log

---

## How it fits in the platform

```
[ Bootstrap script ]
        |
        | installs
        v
[ lintune-admin ] ── web wizard ──► Keycloak  (install via SSH + configure)
[ lintune-dash  ]                ──► Mailcow   (install via SSH + add domain)
        |                        ──► Nextcloud (install via SSH + provision user)
        |                        ──► MariaDB   (domain_realm_map)
        |                                            |
        | auth (Keycloak OIDC)                       | read by
        v                                            v
[ Super admin logs in ]                      [ lintune-dash ]  ◄──► Keycloak (tenant SSO)
                                                               ◄──► Mailcow  (manage mailboxes)
                                                               ◄──► Nextcloud (manage files)
```

---

## Authentication

Super admins log in via **Keycloak OIDC (PKCE)** against the master realm:

- Full Keycloak login UI, including 2FA if configured on the master realm
- Any master realm user with the `admin` role can access the admin panel
- Credentials never pass through lintune-admin

Admin API calls (creating realms, users, etc.) use a dedicated `lintune-service` account in the master realm with a random password stored encrypted in `.env` and the `settings` table.

---

## Keycloak broker realm

A dedicated broker realm acts as a central federation hub. Every new tenant realm is automatically registered as an OIDC Identity Provider in the broker realm, so:

- All tenant realms are reachable from a single Keycloak entry point
- Cross-realm authentication is handled automatically
- The broker realm is created and named randomly during initial setup

---

## Installation

See [docs/install.md](docs/install.md) for full installation instructions.

### Quick start (Docker)

```bash
curl -fsSL https://get.lintune.xyz | bash
```

Then open the admin URL in your browser and follow the wizard.

---

## Roadmap

### Done
- [x] **Docker installer** — `curl get.lintune.xyz | bash` bootstraps the full stack (MariaDB, lintune-admin, lintune-dash, optional Caddy with auto-SSL). Generates separate `admin.env` and `dash.env` per service
- [x] **Browser-based setup wizard** (`/install`) — runs before login, no command line required after the bootstrap script:
  - SSHes into server(s) and installs Keycloak via Docker Compose
  - Configures Keycloak automatically: creates OIDC client, broker realm, and `lintune-service` account
  - Optional Mailcow install (checks and installs `git`, `curl`, `jq`, `openssl` automatically)
  - Optional Nextcloud AIO install
  - Supports single-server and multi-server topologies
  - Live terminal output streamed to the browser via SSE during installation
  - Manual path for operators who already have Keycloak running elsewhere
- [x] **Post-login configuration wizard** (`/super/wizard`) — connects Mailcow and Nextcloud to an existing Keycloak install after first login
- [x] **Realm management** — create, edit, enable/disable realms; per-tenant Mailcow and Nextcloud settings; configurable user limits (max users, max mailboxes, max Nextcloud users)
- [x] **Mailcow integration** — domain provisioning, mailbox and alias limit management per realm, per-realm Mailcow URL override
- [x] **Nextcloud integration** — user group provisioning per realm, per-realm Nextcloud URL override
- [x] **Platform settings page** — manage Keycloak URL, Mailcow URL, Nextcloud URL, default limits from the UI
- [x] **Audit log** — all operator actions logged and viewable in the UI
- [x] **Session timer** — shows remaining session time with auto-redirect to login on expiry

### In progress
- [ ] **Repair mode** — if the `lintune-service` account is ever deleted, visiting `/install/manual` with master admin credentials recreates it without touching the OIDC client or broker realm
- [ ] **Nextcloud full provisioning** — AIO install working; deep per-realm provisioning (user accounts, quota) still basic

### Planned
- [ ] **Platform SMTP settings** — dedicated settings block (host, port, encryption, username, password, from address) for Lintune system emails (welcome emails, notifications, alerts). Stored encrypted. Wired into Laravel's mailer at runtime via `Config::set()` so no cache flush needed. "Use Mailcow" shortcut pre-fills from stored Mailcow credentials for MSPs happy to use it for platform email too
- [ ] **"Use master realm domain" toggle** — MSP's own company domain (from the master realm) used as the platform sender identity. When enabled, Lintune sets everything up: SMTP pre-filled from Mailcow, SPF/MX/DKIM/DMARC records shown for manual setup (or set automatically once Cloudflare integration lands). Toggle off = manual SMTP config, no assumptions. Forward-compatible: same toggle gains DNS automation when Cloudflare is connected, no redesign needed
- [ ] **Welcome email on realm creation** — send a welcome email to the initial admin user when a realm is provisioned. Sender configurable in platform settings, falling back to the logged-in super admin's email
- [ ] **DNS integration** — auto-create DNS zones per tenant domain and seed MX, SPF, DKIM, and DMARC records on realm creation. Supports PowerDNS (self-hosted) and Cloudflare (managed DNS)
- [ ] **Workstation login** — expose a read-only, realm-scoped Keycloak LDAP endpoint per tenant so Linux (SSSD) and Windows (Kerberos) workstations can authenticate without Active Directory
- [ ] **Add-service wizard** — provision an additional Mailcow or Nextcloud instance on a new server and register it in platform settings, ready to be assigned to new realms
- [ ] **Realm migration** — move a tenant realm from one Mailcow or Nextcloud instance to another. Mailcow: recreate domain/users via API + imapsync for email, then flip MX. Nextcloud: maintenance mode → rsync data dir → update assignment (users re-wire via Keycloak OIDC automatically). Data model already supports this via `realms.mailcow_service_id` / `realms.nextcloud_service_id` FKs
- [ ] **ISPConfig integration** — per-tenant web hosting account via ISPConfig API. Creates client, website, and FTP account per realm. Tenants never access ISPConfig directly
- [ ] **Vaultwarden integration** — per-tenant Vaultwarden organisation on realm creation for shared password management
- [ ] **Headscale integration** — per-tenant managed VPN mesh for secure remote workstation access without opening firewall ports
- [ ] **Tenant billing / usage overview** — per-realm user count, mailbox count, and storage usage in one view for MSP billing
- [ ] **Bulk onboarding** — import multiple tenants from CSV
- [ ] **Webhook support** — notify external systems on realm create/delete
- [ ] **Queue API calls** — move long-running Keycloak / Mailcow / Nextcloud API calls to a Laravel queue worker

### On the radar
- **Mobile / device management (MDM)** — fragmented today, but fits here when a solid cross-platform open-source option emerges
- **Rudder** — open-source IT automation for workstations; natural fit for policy enforcement once workstation login lands
- **Passkey / FIDO2 enforcement** — Keycloak supports WebAuthn natively; exposing this per-tenant in lintune-dash would let MSPs enforce passwordless login

---

### The bigger picture

The goal: an MSP should be able to onboard a new customer — email, file storage, user directory, workstation login, VPN, DNS — with a single form submission, on a fully self-hosted open-source stack. No Active Directory. No Microsoft 365. No per-seat licensing.

If you have ideas for components or integrations that fit this vision, open an issue or start a discussion.
