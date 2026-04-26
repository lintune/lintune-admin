# Lintune Admin

> ⚠️ This project is under active development and is not yet production ready. Expect breaking changes.

Lintune Admin is the super admin portal for the Lintune platform — an open-source alternative to Microsoft Azure AD / Entra ID, Exchange, and OneDrive, built on open-source components.

| Component | Role |
|---|---|
| [Keycloak](https://www.keycloak.org/) | Identity provider — realms, users, SSO |
| [Mailcow](https://mailcow.email/) | Email server — domains, mailboxes |
| Nextcloud *(planned)* | File storage & collaboration |
| Vaultwarden *(planned)* | Password manager — per-tenant organisations |
| PowerDNS / Cloudflare *(planned)* | DNS management — zones, records |
| SSSD / Keycloak LDAP *(planned)* | Workstation login — Linux & Windows |

This repo is the **super admin layer**. It is not customer-facing. Only platform operators use it.

## What it does

- Creates a new **Keycloak realm** per tenant (organisation)
- Maps a **domain** to that realm so users can log in via their own domain
- Creates the **initial admin user** for the tenant in Keycloak
- Registers the new realm as an **Identity Provider in the broker realm**, so all tenants are federated under a single Keycloak entry point
- Optionally enables **Mailcow** for the tenant — provisioning the email domain
- Manages the `domain_realm_map` table that both this app and [lintune-dash](../lintune-dash) rely on for realm lookups
- Provides enable/disable toggles per realm and per Mailcow integration
- Logs all admin actions to an audit log, viewable in the UI

## How it fits in the platform

```
[ Super Admin ]
      |
      | provisions
      v
[ lintune-admin ] ──► Keycloak (create realm, create user)
                  ──► Mailcow  (add domain)
                  ──► MySQL    (domain_realm_map)
                                    |
                                    | read by
                                    v
                             [ lintune-dash ]  ◄──► Keycloak (tenant SSO)
                                               ◄──► Mailcow  (manage mailboxes)
```

When a super admin creates a realm in lintune-admin:
1. A Keycloak realm is created for the tenant
2. The domain is registered in `domain_realm_map`
3. An initial admin user is created in that realm
4. A confidential client (`broker-realm-client`) is created in the new realm
5. An OIDC Identity Provider is added to the broker realm pointing to the new tenant realm
6. If Mailcow is enabled, the email domain is added to Mailcow
7. The tenant admin can now log in to lintune-dash using their domain

## Keycloak broker realm

The broker realm is a dedicated Keycloak realm that acts as a central federation hub. Every time a new tenant realm is provisioned, lintune-admin automatically registers it as an OIDC Identity Provider in the broker realm. This means:

- All tenant realms are reachable from a single Keycloak entry point
- The broker realm handles cross-realm authentication without manual Keycloak configuration
- The broker realm is created automatically during initial setup

## Authentication

Super admins log in via **Keycloak OIDC** (PKCE) against the master realm. This means:

- Full Keycloak login UI, including 2FA if configured on the master realm
- Any master realm user with the `admin` role can log in
- Credentials never pass through lintune-admin

Admin API calls (creating realms, users etc.) are made using a dedicated `lintune-service` account in the master realm, created automatically during setup with a random password stored encrypted in `.env`.

## Initial setup

On first visit, lintune-admin shows a one-time setup page that:
1. Verifies your Keycloak master admin credentials
2. Creates the `lintune-admin` OIDC client in the master realm
3. Creates the broker realm with a randomly generated name
4. Creates the `lintune-service` account with a random password and assigns it the `admin` role
5. Writes all generated values to `.env` and locks the setup page

If the service account is ever deleted or its password changed, set `SETUP_COMPLETE=` (empty) in `.env` and run `php artisan config:clear` to trigger repair mode, which recreates the service account without touching the broker realm or OIDC client.

## Installation

See [docs/install.md](docs/install.md) for installation instructions and [docs/architecture.md](docs/architecture.md) for a full overview of the repository structure and deployment paths.

## Roadmap

The platform is being built incrementally. Contributions and ideas are welcome.

### In progress
- [x] Nextcloud integration — provision a Nextcloud user space per tenant on realm creation

### Planned
- [x] **Mailcow domain limits** — configurable default limits (mailbox count, alias count, quota per mailbox) stored in a `settings` table and manageable via a platform settings page. Defaults are pre-filled when provisioning a new realm but can be overridden per tenant
- [ ] **Welcome email on realm creation** — send a welcome email to the initial admin user when a realm is provisioned. Sender is configurable in platform settings (e.g. `noreply@msp.com`), falling back to the logged-in super admin's email. MSP sender email is stored in the `settings` table and shared with lintune-dash for fallback use
- [ ] **DNS integration** — auto-create DNS zones per tenant domain and seed MX, SPF, DKIM and DMARC records on realm creation. Supports **PowerDNS** (self-hosted) and **Cloudflare** (managed DNS)
- [ ] **Workstation login** — expose a read-only, realm-scoped Keycloak LDAP endpoint per tenant so workstations (Linux via SSSD, Windows via Kerberos) can authenticate against the same user directory without Active Directory
- [ ] **Dockerization** — package lintune-admin, lintune-dash and MySQL into a Docker Compose stack for easy deployment. A single bootstrap script installs Docker and starts the full platform on a fresh VPS. The provisioning wizard then manages all other servers from within the stack
- [ ] **Server provisioning** — SSH-based automated provisioning of new servers via a guided wizard in lintune-admin. Two provisioning types:
  - **Initial setup** — provision a fresh server with Keycloak + selected services (Mailcow, Nextcloud, Vaultwarden). Configures Keycloak via `kcadm.sh`, sets the admin password, deploys services via Docker Compose, sets up **Caddy** as the reverse proxy with automatic HTTPS via Let's Encrypt, and wires everything into Lintune automatically
  - **Add service server** — provision an additional Mailcow or Nextcloud instance on a new server, set up Caddy, and register it as an available server in the platform settings, ready to be assigned to new realms
  - Supports **single-server** (all services on one VPS) and **multi-server** (each service on its own VPS) topologies
  - Live log output streamed to the browser during provisioning via Laravel Broadcasting
  - Provisioning status tracked per server (`provisioning`, `active`, `failed`) with full log history
  - SSH credentials (host, user, private key) stored encrypted in the DB
  - Goal: the only manual step is running the bootstrap script to install lintune-admin itself — everything else is managed through the UI
- [ ] **ISPConfig integration** — provision a per-tenant web hosting account via the ISPConfig API on realm creation. Creates an ISPConfig client, website and FTP account scoped to the tenant domain. FTP credentials are emailed to the tenant admin. Tenants never access the ISPConfig dashboard directly. ISPConfig is installed without its email module (Mailcow handles email). Optional MySQL database creation per realm manageable via lintune-admin. ISPConfig DNS module is skipped — Cloudflare handles DNS
- [ ] **Vaultwarden integration** — provision a per-tenant Vaultwarden organisation on realm creation, allowing users to share passwords securely within their organisation. Tenant admins manage organisation membership via lintune-dash. Replaces LastPass / 1Password for MSP-managed customers on a fully self-hosted stack
- [ ] **Headscale integration** — per-tenant managed VPN mesh so MSPs can offer secure remote access to workstations without opening firewall ports
- [ ] **Tenant billing / usage overview** — per-realm user count, mailbox count, storage usage in one view for MSP billing purposes
- [ ] **Bulk onboarding** — import multiple tenants from CSV
- [ ] **Webhook support** — notify external systems when a realm is created or deleted
- [ ] **Queueing API calls** — Queue any API calls using the Laravel queue worker

### On the radar
These are not yet planned but worth watching as the ecosystem matures:
- **Mobile / device management (MDM)** — open source MDM is fragmented today (MicroMDM for Apple, Flyve for Android) but if a solid cross-platform option emerges it fits naturally here
- **Rudder** — open source IT automation and compliance for workstations, potential fit for policy enforcement once workstation login is in place
- **Passkey / FIDO2 enforcement** — Keycloak supports WebAuthn natively, exposing this per-tenant in lintune-dash would allow MSPs to enforce passwordless login

### The bigger picture
The goal is to make it possible for an MSP to onboard a new customer — email, file storage, user directory, workstation login, VPN access, DNS — with a single form submission, on a fully self-hosted open source stack. No Active Directory. No Microsoft 365. No per-seat licensing.

If you have ideas for components or integrations that fit this vision, open an issue or start a discussion on GitHub.

### Ideas welcome
Have an idea for a component or integration that fits the vision? Open an issue or start a discussion on GitHub. The goal is to make Lintune a complete, self-hosted alternative to Microsoft 365 for MSPs and self-hosters — if something fits that vision, it's worth discussing.
