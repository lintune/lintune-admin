# Installation

## Requirements

- PHP 8.3+
- Composer
- MySQL database
- Keycloak instance (with at least one master realm admin user)
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
| `KEYCLOAK_CLIENT_ID` | Client ID used in tenant realms (default: `lintune-frontend`) |
| `DASH_URL` | Public URL of lintune-dash |

> `KEYCLOAK_ADMIN_CLIENT_SECRET`, `KEYCLOAK_BROKER_REALM`, `KEYCLOAK_ADMIN_USER`, `KEYCLOAK_ADMIN_PASSWORD` and `SETUP_COMPLETE` are all written automatically by the setup page. Do not set them manually.

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

### 6. Run initial setup

Visit `https://admin.yourdomain.com/super/setup` in your browser and enter your Keycloak master realm admin credentials. Setup will automatically:

- Create the `lintune-admin` OIDC client in the master realm
- Create the broker realm with a randomly generated name
- Create a dedicated `lintune-service` account for Admin API calls
- Write all generated values to `.env` and lock the setup page

After setup completes you will be redirected to the Keycloak login page.

## Repair mode

If the `lintune-service` account is ever deleted or its password changed:

1. Set `SETUP_COMPLETE=` (empty) in `.env`
2. Run `php artisan config:clear`
3. Visit `/super/setup` — it will detect the existing configuration and offer to recreate the service account only, without touching the broker realm or OIDC client

## Proxy / Reverse Proxy

If running behind Cloudflare or another reverse proxy, see [proxy.md](proxy.md) for Keycloak configuration.
