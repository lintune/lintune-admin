# Upgrading

## Steps

After every `git pull`, run the following:

### 1. Install/update dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### 2. Run migrations

```bash
php artisan migrate --force
```

### 3. Clear and rebuild caches

```bash
php artisan route:clear
php artisan config:clear
php artisan view:clear
php artisan route:cache
php artisan config:cache
php artisan view:cache
```

> If you skip the cache clear after a pull, you may see errors like `Route [x] not defined` even though the route exists in code. Always clear before rebuilding.
