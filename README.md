# Lintune Admin

Lintune Admin is the super admin portal for the Lintune platform — an open-source alternative to Microsoft Azure AD / Entra ID, Exchange, and OneDrive, built on open-source components.

| Component | Role |
|---|---|
| [Keycloak](https://www.keycloak.org/) | Identity provider — realms, users, SSO |
| [Mailcow](https://mailcow.email/) | Email server — domains, mailboxes |
| Nextcloud *(planned)* | File storage & collaboration |

This repo is the **super admin layer**. It is not customer-facing. Only platform operators use it.

## What it does

- Creates a new **Keycloak realm** per tenant (organisation)
- Maps a **domain** to that realm so users can log in via their own domain
- Creates the **initial admin user** for the tenant in Keycloak
- Optionally enables **Mailcow** for the tenant — provisioning the email domain
- Manages the `domain_realm_map` table that both this app and [lintune-dash](../lintune-dash) rely on for realm lookups
- Provides enable/disable toggles per realm and per Mailcow integration

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
4. If Mailcow is enabled, the email domain is added to Mailcow
5. The tenant admin can now log in to lintune-dash using their domain

## Installation

See [docs/install.md](docs/install.md).
