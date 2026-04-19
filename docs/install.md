# Installation

## Requirements

- PHP 8.3+
- Composer
- MySQL database
- Keycloak instance
- Mailcow instance (optional)

## Steps

### 1. Clone & install dependencies

```bash
git clone <repo-url> lintune-admin
cd lintune-admin
composer install --no-dev --optimize-autoloader
```

### 2. Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and fill in the required values:

| Variable | Description |
|---|---|
| `APP_URL` | Public URL of this admin app (e.g. `https://admin.yourdomain.com`) |
| `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | MySQL connection details |
| `KEYCLOAK_BASE_URL` | Public URL of your Keycloak instance |
| `KEYCLOAK_CLIENT_ID` | Keycloak client ID (default: `lintune-frontend`) |
| `KEYCLOAK_ADMIN_CLI_CLIENT` | Keycloak admin CLI client (default: `admin-cli`) |
| `KEYCLOAK_BROKER_REALM` | Broker realm name |
| `FRONTEND_URL` | Public URL of lintune-dash |
| `MAILCOW_URL` | Mailcow base URL |
| `MAILCOW_API_KEY` | Mailcow API key |

### 3. Run migrations

```bash
php artisan migrate --force
```

### 4. Set storage permissions

```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### 5. Optimize for production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Keycloak broker realm setup

Before provisioning any tenants, you must create the broker realm in Keycloak manually:

1. Log in to the Keycloak admin console
2. Create a new realm — name it whatever you like (e.g. `broker-realm`)
3. Set `KEYCLOAK_BROKER_REALM` in `.env` to that name

When lintune-admin provisions a new tenant realm, it will automatically:
- Create a confidential client (`broker-realm-client`) in the tenant realm
- Register that realm as an OIDC Identity Provider in the broker realm
- Add an email attribute mapper to the IdP

No further manual Keycloak configuration is needed per tenant.

## Proxy / Reverse Proxy

If running behind Cloudflare or another reverse proxy, see [proxy.md](proxy.md) for Keycloak configuration.
