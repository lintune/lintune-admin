# lintune-admin rules

## Architecture
- All database migrations live exclusively in lintune-admin. Never suggest or create migrations in lintune-dash.
- The `settings` table is the single source of truth for platform-wide configuration. Never hardcode platform config values in controllers or views.
- lintune-admin is for platform operators only. Never build customer-facing features here.

## Security
- Sensitive values stored in the `settings` table must have `encrypted = true` and be encrypted/decrypted via Laravel's `encrypt()`/`decrypt()` helpers.
- Never store plaintext credentials, API keys, or passwords anywhere in the codebase or database.

## Input
- Always validate and sanitize user input in controllers. Use Laravel's `validate()` with explicit rules.
- Lowercase and trim realm names and email addresses before using them.

## API calls
- Always use `rtrim($base, '/')` when building Keycloak or Mailcow API URLs from config. Never assume the base URL has no trailing slash.

## Scope
- lintune-dash is scoped to a single realm per session. Never build cross-realm data access into lintune-dash.
- Realm provisioning, domain registration, and Mailcow domain setup are exclusively lintune-admin concerns. lintune-dash manages resources within an already-provisioned realm only.
