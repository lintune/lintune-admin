# Installation

There are two ways to install Lintune: the recommended **Docker installer** (one script + a web wizard), or a **manual install** on an existing server stack.

---

## Option A — Docker (recommended)

### 1. Run the installer script

On the server that will host Lintune admin and the tenant dashboard, run:

```bash
curl -fsSL https://get.lintune.xyz | bash
```

The script will:

- Install Docker if not already present
- Ask whether to use **Caddy** as an automatic SSL reverse proxy, or expose ports for your own proxy
- Prompt for your public domain names (or URLs)
- Generate secrets and write `admin.env`, `dash.env`, and `docker-compose.yml` to `/opt/lintune/`
- Pull the images and start the stack

> Secrets are saved to `/opt/lintune/.env` (root-readable only). Application env files are `/opt/lintune/admin.env` and `/opt/lintune/dash.env`.

### 2. Complete setup in the browser

Once the script finishes, open your admin URL in a browser. You will be taken to the **setup wizard** automatically.

---

## The setup wizard

The wizard runs in the browser at `/install` and handles everything that would otherwise require touching a command line.

### Step 1 — Choose a path

**Install for me** — the wizard SSHes into your server(s) and installs Keycloak (and optionally Mailcow and Nextcloud) for you. Choose this if you're starting fresh.

**I'll set it up myself** — you already have Keycloak running somewhere. The wizard will configure Lintune to use it. Skip to [Manual Keycloak setup](#manual-keycloak-setup) below.

---

### Guided install (SSH)

The wizard connects to your servers over SSH to install services via Docker.

#### Single server

All services run on one machine. You can choose:

- **This server** — installs Keycloak on the same host running Lintune admin (using `host.docker.internal` to reach the host from inside the Docker container). SSH must be accessible on the host.
- **Different server** — enter a remote IP address.

Provide SSH credentials (username + password), the Keycloak port (default `8080`), and optionally a **public URL** if Keycloak will sit behind a reverse proxy (e.g. `https://keycloak.company.com`). When a public URL is given, Keycloak is started in production mode with reverse-proxy headers enabled.

Mailcow and Nextcloud are optional. Toggle them on to install on the same server.

#### Multi server

Each service runs on a separate machine. You provide SSH credentials per server:

| Service | Default SSH host field | Notes |
|---|---|---|
| Keycloak | `kc_host` | Required |
| Mailcow | `mc_host` | Optional — needs its own box |
| Nextcloud | `nc_host` | Optional — needs its own box |

The wizard installs each service in sequence, reporting live output as it runs.

#### What gets installed

| Service | How | Default port |
|---|---|---|
| Keycloak | Docker Compose at `/opt/keycloak/` | `8080` (configurable) |
| Mailcow | Cloned from GitHub to `/opt/mailcow-dockerized/` | Standard Mailcow ports |
| Nextcloud AIO | Docker container | AIO admin on `9080`, web on `11000` |

> Docker is installed automatically on any server that doesn't already have it.
> Mailcow requires `git`, `curl`, `jq`, and `openssl` — the wizard installs any that are missing.

#### After installation

Once all services are running the wizard:

1. Waits for Keycloak's `/health/ready` endpoint (up to 90 s)
2. Creates the `lintune-admin` OIDC client in the Keycloak master realm
3. Creates a dedicated `lintune-service` account with admin role
4. Creates a broker realm for tenant SSO
5. Writes all generated credentials to `.env` and the settings table
6. Redirects you to the login page

---

### Manual Keycloak setup

Use this if you already have Keycloak running (self-installed, managed service, etc.).

1. Choose **I'll set it up myself** on the welcome screen
2. Enter your Keycloak URL (e.g. `https://keycloak.company.com`) and master realm admin credentials
3. The wizard authenticates, then creates the same OIDC client, service account, and broker realm as the guided path — without touching your server

---

## Post-login wizard

After you log in for the first time, a second wizard at `/super/wizard` lets you connect Mailcow and Nextcloud if you didn't install them during setup, or if they're hosted separately from Keycloak. This wizard requires existing servers — it does not install Keycloak.

---

## Option B — Manual install (no Docker)

Use this if you're running admin and dash directly on a server with PHP-FPM + Nginx/Apache, or on a platform like DirectAdmin.

### Requirements

- PHP 8.4+ with extensions: `pdo_mysql`, `opcache`, `openssl`
- Composer
- MariaDB / MySQL 10.6+
- A running Keycloak instance

### Steps

```bash
git clone https://github.com/scraane/lintune-admin.git
cd lintune-admin
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

| Variable | Description |
|---|---|
| `APP_URL` | Public URL of the admin app (e.g. `https://admin.company.com`) |
| `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | MariaDB connection |
| `DASH_URL` | Public URL of lintune-dash |
| `SESSION_SECURE_COOKIE` | `true` if served over HTTPS |

Leave `KEYCLOAK_*` and `SETUP_COMPLETE` empty — the setup wizard fills them in.

```bash
php artisan migrate --force
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Then open `https://admin.company.com/install` in your browser and follow the wizard.

### Sharing the database with lintune-dash

Admin and dash use the **same MariaDB database**. All migrations live in lintune-admin — never run migrations from lintune-dash.

Each app has its own `.env` with its own `APP_URL`. They share `APP_KEY`, `DB_*`, and `SESSION_*` values but differ in `APP_URL` (and `DASH_URL` on the admin side). Do not symlink the env files — keep them separate.

---

## Repair mode

If the `lintune-service` account is deleted or its password changes:

1. Set `SETUP_COMPLETE=` (empty) in `.env`
2. Run `php artisan config:clear`
3. Visit `/install/manual` — enter your Keycloak admin credentials to recreate the service account without touching the broker realm or OIDC client

---

## Further reading

- [Reverse proxy setup](proxy.md)
- [Architecture overview](architecture.md)
- [Troubleshooting](troubleshooting.md)
- [Upgrading](upgrade.md)
