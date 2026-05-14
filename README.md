# lintune-admin

Super-admin portal for the Lintune platform — an open-source, self-hosted alternative to Microsoft 365 for MSPs.

MSP operators use this to provision and manage customer environments:

- Installs Keycloak, Mailcow, and Nextcloud on remote servers via SSH from a browser UI
- Manages Keycloak realms (one per customer) with per-tenant SSO, email, and file storage
- Assigns service instances per realm in multi-server setups
- Full audit log of all operator actions

> This is the **operator layer** — not customer-facing. Customers use [lintune-dash](../lintune-dash).

**Documentation:** [lintune.xyz/docs](https://lintune.xyz/docs)

## Quick start

```bash
curl -fsSL https://get.lintune.xyz | bash
```

Then open the admin URL and follow the setup wizard. Root SSH access to target servers is required for the guided install.

## Stack

Laravel 11 · PHP 8.4 · MariaDB 11 · Docker
