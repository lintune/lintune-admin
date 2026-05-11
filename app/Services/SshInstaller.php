<?php

namespace App\Services;

use phpseclib3\Net\SSH2;

class SshInstaller
{
    private SSH2 $ssh;
    private bool $useSudo;
    private array $log = [];
    private \Closure|null $outputCallback = null;
    private array $captured = [];

    /**
     * @param int $connectTimeout  Seconds for the TCP + auth handshake. 0 = phpseclib default (10s).
     *                             Pass a non-zero value for fast pre-install credential checks.
     */
    public function __construct(string $host, string $user, string $password, int $port = 22, int $connectTimeout = 0)
    {
        $this->useSudo = ($user !== 'root');
        $ssh = $connectTimeout > 0 ? new SSH2($host, $port, $connectTimeout) : new SSH2($host, $port);
        $ssh->setTimeout(0); // no timeout on exec — installs can take many minutes
        if (!$ssh->login($user, $password)) {
            throw new \RuntimeException("SSH authentication failed for {$user}@{$host}");
        }
        $this->ssh = $ssh;
    }

    public function setOutputCallback(\Closure $cb): void
    {
        $this->outputCallback = $cb;
    }

    public function getLog(): array
    {
        return $this->log;
    }

    public function getCaptured(string $key): ?string
    {
        return isset($this->captured[$key]) && $this->captured[$key] !== '' ? $this->captured[$key] : null;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function emit(string $line): void
    {
        $this->log[] = $line;
        if ($this->outputCallback) {
            ($this->outputCallback)($line);
        }
    }

    /**
     * Run a multi-line bash script in a single exec() call.
     * Using one exec per operation avoids phpseclib3's channel-reuse bug
     * where sequential exec() calls on the same connection fail with
     * "Please close the channel before trying to open it again".
     */
    private function execScript(string $script): void
    {
        $prefix     = $this->useSudo ? 'sudo ' : '';
        $lineBuffer = '';

        // Use the phpseclib callback form so output is emitted as it arrives rather
        // than buffered until the script exits. Critical for long-running steps like
        // docker image pulls, where the old approach showed nothing until completion.
        $this->ssh->exec(
            "{$prefix}bash << 'LINTUNE_EOF'\nset -e\nexec 2>&1\n{$script}\nLINTUNE_EOF",
            function (string $chunk) use (&$lineBuffer): void {
                $lineBuffer .= $chunk;
                while (($pos = strpos($lineBuffer, "\n")) !== false) {
                    $line       = substr($lineBuffer, 0, $pos);
                    $lineBuffer = substr($lineBuffer, $pos + 1);
                    if (preg_match('/^CAPTURE:([^:]+):(.*)$/', $line, $m)) {
                        $this->captured[$m[1]] = $m[2];
                    } elseif (trim($line) !== '') {
                        $this->emit($line);
                    }
                }
            }
        );

        // Flush any remaining content that arrived without a trailing newline
        if (trim($lineBuffer) !== '') {
            if (preg_match('/^CAPTURE:([^:]+):(.*)$/', $lineBuffer, $m)) {
                $this->captured[$m[1]] = $m[2];
            } else {
                $this->emit($lineBuffer);
            }
        }

        $exit = $this->ssh->getExitStatus();
        if ($exit !== false && $exit !== 0) {
            throw new \RuntimeException("Remote script exited with status {$exit}");
        }
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public function ensureDocker(): void
    {
        $this->emit('→ Checking Docker...');
        $this->execScript(<<<'BASH'
if command -v docker >/dev/null 2>&1; then
    echo "  Docker already installed."
else
    echo "  Installing Docker via get.docker.com..."
    curl -fsSL https://get.docker.com | sh
    echo "  Docker installed."
fi
BASH);
    }

    /**
     * @param int         $externalPort  Host port mapped to Keycloak's container port 8080.
     * @param string|null $hostname      Public hostname → enables production mode + reverse-proxy env vars.
     */
    public function installKeycloak(string $adminUsername, string $adminPassword, int $externalPort = 8080, ?string $hostname = null, bool $clean = false): void
    {
        $this->emit('→ Installing Keycloak...');
        if ($clean) {
            $this->emit('  Cleaning up previous Keycloak installation...');
            $this->execScript(<<<'BASH'
cd /opt/keycloak 2>/dev/null && docker compose down --remove-orphans 2>/dev/null || true
rm -rf /opt/keycloak
BASH);
        }

        if ($hostname) {
            $kcCommand = 'start';
            $extraEnv  = <<<YAML
      KC_PROXY_HEADERS: xforwarded
      KC_HOSTNAME: {$hostname}
      KC_HTTP_ENABLED: "true"
      KC_HOSTNAME_STRICT_HTTPS: "false"
YAML;
        } else {
            $kcCommand = 'start-dev';
            $extraEnv  = '';
        }

        // Random hex password safe in YAML and bash without quoting concerns.
        $kcDbPassword = bin2hex(random_bytes(16));

        // Build compose YAML: MariaDB first, Keycloak depends on it.
        $composeYaml = implode("\n", array_filter([
            'services:',
            '  mariadb:',
            '    image: mariadb:10.11',
            '    environment:',
            '      MYSQL_DATABASE: keycloak',
            '      MYSQL_USER: keycloak',
            "      MYSQL_PASSWORD: {$kcDbPassword}",
            "      MYSQL_RANDOM_ROOT_PASSWORD: 'yes'",
            '    volumes:',
            '      - keycloak_db:/var/lib/mysql',
            '    restart: unless-stopped',
            '',
            '  keycloak:',
            '    image: quay.io/keycloak/keycloak:' . env('KEYCLOAK_VERSION', '26.6.1'),
            "    command: {$kcCommand}",
            '    depends_on:',
            '      - mariadb',
            '    environment:',
            "      KEYCLOAK_ADMIN: {$adminUsername}",
            "      KEYCLOAK_ADMIN_PASSWORD: {$adminPassword}",
            '      KC_DB: mariadb',
            '      KC_DB_URL: jdbc:mariadb://mariadb:3306/keycloak',
            '      KC_DB_USERNAME: keycloak',
            "      KC_DB_PASSWORD: {$kcDbPassword}",
            $extraEnv ?: null,
            '    ports:',
            "      - \"{$externalPort}:8080\"",
            '    volumes:',
            '      - keycloak_data:/opt/keycloak/data',
            '      - /opt/keycloak/themes:/opt/keycloak/themes',
            '    restart: unless-stopped',
            '',
            'volumes:',
            '  keycloak_db:',
            '  keycloak_data:',
        ]));

        $this->execScript(<<<BASH
mkdir -p /opt/keycloak /opt/keycloak/themes
cat > /opt/keycloak/docker-compose.yml << 'EOLYAML'
{$composeYaml}
EOLYAML

# Deploy Lintune KC theme
echo "  Downloading Lintune Keycloak theme..."
curl -fsSL https://get.lintune.xyz/keycloak-theme.tar.gz | tar -xz -C /opt/keycloak/themes/
echo "  Theme deployed."

cd /opt/keycloak

# Start all services. Keycloak has built-in DB retry so it handles MariaDB
# not being immediately ready without any explicit polling on our side.
docker compose up -d
echo "  Keycloak and MariaDB containers started."

# Suppress the "temporary admin" warning by re-applying the password via kcadm.sh.
# Keycloak's bootstrap env-var path always marks the first account as temporary;
# this clears that flag once the container is ready.
echo "  Waiting for Keycloak to accept kcadm connections (up to 5 min)..."
KC_READY=0
for i in \$(seq 1 30); do
    if docker compose exec -T keycloak /opt/keycloak/bin/kcadm.sh \
            config credentials \
            --server http://localhost:8080 \
            --realm master \
            --user '{$adminUsername}' \
            --password '{$adminPassword}' \
            >/dev/null 2>&1; then
        KC_READY=1
        break
    fi
    echo "  KC not ready yet (attempt \$i/30)..."
    sleep 10
done
if [ "\$KC_READY" = "1" ]; then
    docker compose exec -T keycloak /opt/keycloak/bin/kcadm.sh \
        set-password \
        --username '{$adminUsername}' \
        --new-password '{$adminPassword}' \
        >/dev/null 2>&1 \
        && echo "  Temp-admin flag cleared." \
        || echo "  NOTE: Could not clear temp-admin flag (non-fatal)."
else
    echo "  NOTE: Keycloak not ready in time — temp-admin flag not cleared."
fi
BASH);
    }

    public function installMailcow(string $hostname, string $timezone = 'UTC', bool $clean = false): void
    {
        $this->emit('→ Installing Mailcow...');
        if ($clean) {
            $this->emit('  Cleaning up previous Mailcow installation...');
            $this->execScript(<<<'BASH'
cd /opt/mailcow-dockerized 2>/dev/null && docker compose down --remove-orphans 2>/dev/null || true
rm -rf /opt/mailcow-dockerized
BASH);
        }
        $this->execScript(<<<BASH
# Install missing dependencies (Mailcow requires git, curl, jq, openssl)
MISSING=""
for pkg in git curl jq openssl; do
    command -v "\$pkg" >/dev/null 2>&1 || MISSING="\$MISSING \$pkg"
done
if [ -n "\$MISSING" ]; then
    echo "  Installing missing packages:\$MISSING"
    apt-get update -qq
    apt-get install -y -qq \$MISSING
fi

# Mailcow needs port 25 — stop and remove any MTA that might be holding it,
# then forcibly kill whatever process is still bound to the port.
echo "  Freeing port 25 for Mailcow..."
for svc in postfix sendmail exim4 exim; do
    systemctl stop "\$svc"    2>/dev/null || true
    systemctl disable "\$svc" 2>/dev/null || true
done
apt-get remove -y --purge postfix sendmail exim4 exim4-base 2>/dev/null || true
# Belt-and-suspenders: kill any remaining process bound to port 25
if command -v fuser >/dev/null 2>&1; then
    fuser -k 25/tcp 2>/dev/null || true
fi
echo "  Port 25 cleared."

test -d /opt/mailcow-dockerized || git clone https://github.com/mailcow/mailcow-dockerized /opt/mailcow-dockerized
cd /opt/mailcow-dockerized
MAILCOW_HOSTNAME={$hostname} MAILCOW_TZ={$timezone} bash generate_config.sh
# Inject API key and IP allowlist before containers start so the API is
# immediately secured; 172.16.0.0/12 covers all default Docker bridge ranges.
MC_API_KEY=\$(tr -dc 'A-Z0-9' < /dev/urandom | head -c 30 | sed 's/.\{6\}/&-/g' | sed 's/-\$//')
printf '\nAPI_KEY=%s\nAPI_ALLOW_FROM=127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16\n' "\$MC_API_KEY" >> mailcow.conf
echo "CAPTURE:mailcow_api_key:\$MC_API_KEY"
echo "  Mailcow API key written to mailcow.conf."
# Pull each service individually so the terminal shows clear per-image progress.
echo "  Pulling Mailcow images (one by one)..."
for svc in \$(docker compose config --services); do
    echo "  Pulling \$svc..."
    docker compose pull "\$svc"
done
docker compose up -d
# Mailcow reads API_KEY/API_ALLOW_FROM on first start and persists them to its DB.
# Remove from the conf file now so credentials don't sit in plaintext on disk.
sed -i '/^API_KEY=/d;/^API_ALLOW_FROM=/d' mailcow.conf
echo "  Mailcow started."
BASH);
    }

    public function installNextcloud(string $domain, string $timezone = 'UTC', bool $clean = false, string $adminUsername = '', string $adminPassword = ''): void
    {
        $this->emit('→ Installing Nextcloud AIO...');
        if ($clean) {
            $this->emit('  Cleaning up previous Nextcloud installation...');
            $this->execScript(<<<'BASH'
cd /opt/nextcloud-aio 2>/dev/null && docker compose down --remove-orphans 2>/dev/null || true
docker rm -f nextcloud-aio-mastercontainer 2>/dev/null || true
docker volume rm nextcloud_aio_mastercontainer 2>/dev/null || true
rm -rf /opt/nextcloud-aio
BASH);
        }

        $composeYaml = implode("\n", [
            'services:',
            '  nextcloud-aio-mastercontainer:',
            '    image: nextcloud/all-in-one:latest',
            '    container_name: nextcloud-aio-mastercontainer',
            '    restart: unless-stopped',
            '    ports:',
            '      - "9080:8080"',
            '    environment:',
            '      APACHE_PORT: "11000"',
            '      APACHE_IP_BINDING: "0.0.0.0"',
            '      SKIP_DOMAIN_VALIDATION: "true"',
            '    extra_hosts:',
            '      - "host.docker.internal:host-gateway"',
            '    volumes:',
            '      - nextcloud_aio_mastercontainer:/mnt/docker-aio-config',
            '      - /var/run/docker.sock:/var/run/docker.sock:ro',
            '',
            'volumes:',
            '  nextcloud_aio_mastercontainer:',
            '    name: nextcloud_aio_mastercontainer',
        ]);

        $b64AdminUser = base64_encode($adminUsername);
        $b64AdminPass = base64_encode($adminPassword);

        $this->execScript(<<<BASH
# Ensure required tools (wget for polling, jq for JSON manipulation)
for pkg in wget jq; do
    command -v "\$pkg" >/dev/null 2>&1 || apt-get install -y -qq "\$pkg"
done

mkdir -p /opt/nextcloud-aio
cat > /opt/nextcloud-aio/docker-compose.yml << 'EOLYAML'
{$composeYaml}
EOLYAML
cd /opt/nextcloud-aio
docker compose up -d
echo "  Nextcloud AIO mastercontainer started."

# Poll until AIO is ready — first page load generates the passphrase in configuration.json
echo "  Waiting for AIO to become ready..."
AIO_UP=0
for i in \$(seq 1 24); do
    RESPONSE=\$(wget -qO- --no-check-certificate https://localhost:9080/ 2>/dev/null || true)
    if [ -n "\$RESPONSE" ]; then
        echo "  AIO is up (attempt \$i)."
        AIO_UP=1
        break
    fi
    echo "  Attempt \$i/24 — not ready yet..."
    sleep 5
done
[ "\$AIO_UP" = "1" ] || { echo "  ERROR: AIO did not respond after 2 minutes."; exit 1; }

# Read/write configuration.json via docker exec — works on any Docker setup including
# Docker Desktop where /var/lib/docker/volumes/ is not accessible from the host filesystem.
AIO_CFG_PATH="/mnt/docker-aio-config/data/configuration.json"

PASSPHRASE=\$(docker exec nextcloud-aio-mastercontainer cat "\$AIO_CFG_PATH" | jq -r '.password')
[ -n "\$PASSPHRASE" ] || { echo "  ERROR: Could not read AIO passphrase."; exit 1; }
echo "CAPTURE:nc_aio_pass:\$PASSPHRASE"
echo "  Passphrase captured."

# Write domain, timezone, ports, and wasStartButtonClicked into configuration.json.
# No secrets section — AIO generates those when containers first start.
CURRENT_CFG=\$(docker exec nextcloud-aio-mastercontainer cat "\$AIO_CFG_PATH")
NEW_CFG=\$(echo "\$CURRENT_CFG" | jq --arg domain "{$domain}" --arg tz "{$timezone}" \\
    '. + {"domain": \$domain, "timezone": \$tz, "apache_port": "11000", "apache_ip_binding": "0.0.0.0", "borg_restore_password": "", "wasStartButtonClicked": true} | del(.secrets)')
echo "\$NEW_CFG" | docker exec -i nextcloud-aio-mastercontainer sh -c "cat > \$AIO_CFG_PATH"
docker exec -u root nextcloud-aio-mastercontainer chown www-data:www-data "\$AIO_CFG_PATH"
echo "  Config written: domain={$domain}, timezone={$timezone}."

# ── Phase 1: Pull images ──────────────────────────────────────────────────────
# PullContainerImages.php has no terminal output; docker events shows each image
# as it finishes downloading.
echo "  Pulling Nextcloud AIO images (this may take several minutes)..."
docker events \\
    --filter 'type=image' --filter 'event=pull' \\
    --format '  [pull] {{.Actor.Attributes.name}}' &
PULL_EVENTS=\$!
docker exec -u www-data nextcloud-aio-mastercontainer \\
    php /var/www/docker-aio/php/src/Cron/PullContainerImages.php 2>&1 || true
sleep 2
kill \$PULL_EVENTS 2>/dev/null; wait \$PULL_EVENTS 2>/dev/null || true
echo "  Images pulled."

# ── Phase 2: Start containers ────────────────────────────────────────────────
# Ensure the AIO network exists before StartContainers.php tries to attach to it.
docker network create nextcloud-aio 2>/dev/null || true
echo "  Network nextcloud-aio ready."
# StartContainers.php also has no output; docker events shows create/start per container.
echo "  Starting Nextcloud AIO containers..."
docker events \\
    --filter 'type=container' \\
    --filter 'event=create' \\
    --filter 'event=start' \\
    --format '  [{{.Action}}] {{.Actor.Attributes.name}}' &
START_EVENTS=\$!
docker exec -u www-data nextcloud-aio-mastercontainer \\
    php /var/www/docker-aio/php/src/Cron/StartContainers.php 2>&1 || true
sleep 2
kill \$START_EVENTS 2>/dev/null; wait \$START_EVENTS 2>/dev/null || true
echo "  Container start triggered."

# ── Phase 3: Wait for Nextcloud to initialize ────────────────────────────────
echo "  Waiting for Nextcloud to initialize — this typically takes 10–15 minutes, please be patient..."
NC_READY=0
for i in \$(seq 1 90); do
    STATUS=\$(docker inspect --format '{{.State.Status}}' nextcloud-aio-nextcloud 2>/dev/null || echo "missing")
    if [ "\$STATUS" = "running" ]; then
        OCC_OK=\$(docker exec -u www-data nextcloud-aio-nextcloud \\
            php occ status --output=json 2>/dev/null \\
            | jq -r '.installed // false' 2>/dev/null || echo "false")
        if [ "\$OCC_OK" = "true" ]; then
            echo "  Nextcloud is ready (attempt \$i)."
            NC_READY=1
            break
        fi
        echo "  Attempt \$i/90 — Nextcloud initializing..."
    else
        echo "  Attempt \$i/90 — container: \$STATUS..."
    fi
    sleep 10
done
[ "\$NC_READY" = "1" ] || { echo "  ERROR: Nextcloud did not initialize in time."; exit 1; }

# Capture the auto-generated admin password from the secrets section
NC_ADMIN_PASS=\$(jq -r '.secrets.NEXTCLOUD_PASSWORD // empty' "\$CONFIG_FILE" 2>/dev/null || true)
[ -n "\$NC_ADMIN_PASS" ] && echo "CAPTURE:nc_admin_pass:\$NC_ADMIN_PASS"
echo "  Admin password captured."

# Give Nextcloud a moment to fully stabilize before running occ commands.
sleep 15

# Pause the AIO watchtower during account creation to prevent it from
# restarting containers while occ commands are in flight.
WATCHTOWER_ID=\$(docker ps -q -f name=nextcloud-aio-watchtower 2>/dev/null || true)
if [ -n "\$WATCHTOWER_ID" ]; then
    echo "  Pausing AIO watchtower during account setup..."
    docker pause "\$WATCHTOWER_ID" >/dev/null 2>&1 || true
fi

# ── Phase 4: Create lintune service account ──────────────────────────────────
NC_SVC_PASS=\$(openssl rand -hex 24)
OCC_OK=0
for attempt in 1 2 3; do
    docker exec -u www-data -e OC_PASS="\$NC_SVC_PASS" nextcloud-aio-nextcloud \\
        php occ user:add --password-from-env --display-name="Lintune Service" --group="admin" lintune-svc \\
        && OCC_OK=1 && break
    echo "  occ user:add attempt \$attempt failed, retrying in 15s..."
    sleep 15
done
[ "\$OCC_OK" = "1" ] || { echo "  ERROR: Could not create lintune-svc account."; exit 1; }
echo "CAPTURE:nc_svc_pass:\$NC_SVC_PASS"
echo "  Service account 'lintune-svc' created."

# ── Phase 5: Create MSP operator account ─────────────────────────────────────
NC_ADMIN_USER=\$(printf '%s' '{$b64AdminUser}' | base64 -d)
NC_ADMIN_PASS=\$(printf '%s' '{$b64AdminPass}' | base64 -d)
if [ -n "\$NC_ADMIN_USER" ] && [ -n "\$NC_ADMIN_PASS" ]; then
    OCC_OK=0
    for attempt in 1 2 3; do
        docker exec -u www-data -e OC_PASS="\$NC_ADMIN_PASS" nextcloud-aio-nextcloud \\
            php occ user:add --password-from-env --display-name="\$NC_ADMIN_USER" --group="admin" "\$NC_ADMIN_USER" \\
            && OCC_OK=1 && break
        echo "  occ user:add attempt \$attempt failed, retrying in 15s..."
        sleep 15
    done
    [ "\$OCC_OK" = "1" ] && echo "  Operator account '\$NC_ADMIN_USER' created." \
        || echo "  WARNING: Could not create operator account (non-fatal — create it manually)."
fi

# Resume watchtower if we paused it
if [ -n "\$WATCHTOWER_ID" ]; then
    echo "  Resuming AIO watchtower."
    docker unpause "\$WATCHTOWER_ID" >/dev/null 2>&1 || true
fi

echo "  Nextcloud AIO setup complete. Domain: {$domain}"
BASH);
    }

    public function configureNextcloudOidc(string $kcBaseUrl, string $brokerRealm, string $clientId, string $clientSecret): void
    {
        $this->emit('→ Configuring Nextcloud OpenID Connect...');

        $b64ClientId     = base64_encode($clientId);
        $b64ClientSecret = base64_encode($clientSecret);
        $discoveryUri    = rtrim($kcBaseUrl, '/') . "/realms/{$brokerRealm}/.well-known/openid-configuration";
        $b64Discovery    = base64_encode($discoveryUri);

        $this->execScript(<<<BASH
NC_CLIENT_ID=\$(printf '%s' '{$b64ClientId}' | base64 -d)
NC_CLIENT_SECRET=\$(printf '%s' '{$b64ClientSecret}' | base64 -d)
NC_DISCOVERY_URI=\$(printf '%s' '{$b64Discovery}' | base64 -d)

echo "  Installing user_oidc app..."
docker exec -u www-data nextcloud-aio-nextcloud php occ app:install user_oidc < /dev/null || \
    docker exec -u www-data nextcloud-aio-nextcloud php occ app:enable user_oidc < /dev/null || \
    echo "  user_oidc already active."

echo "  Configuring OpenID Connect provider..."
docker exec -u www-data nextcloud-aio-nextcloud php occ user_oidc:provider Keycloak \
    --clientid="\$NC_CLIENT_ID" \
    --clientsecret="\$NC_CLIENT_SECRET" \
    --discoveryuri="\$NC_DISCOVERY_URI" \
    --check-bearer=1 \
    --send-id-token-hint=1 \
    --mapping-uid=email \
    --unique-uid=0 \
    --mapping-groups=groups \
    --group-restrict-login-to-whitelist=1 \
    --group-whitelist-regex=nextcloud < /dev/null
echo "  OpenID Connect configured."
BASH);
    }

    public function setupBackupUser(string $publicKey): void
    {
        $this->emit('→ Setting up backup user...');
        $b64Key = base64_encode($publicKey);

        // PHP nowdoc (no interpolation) → safe to include $, $() without escaping
        $wrapperScript = <<<'WRAPPER'
#!/bin/bash
set -euo pipefail
MAILCOW_DIR=/opt/mailcow-dockerized
if [[ ! -d "$MAILCOW_DIR" ]]; then
    echo "mailcow directory not found" >&2
    exit 1
fi
MCTMP=$(mktemp -d)
chmod 777 "$MCTMP"
trap "rm -rf $MCTMP" EXIT
cd "$MAILCOW_DIR"
MAILCOW_BACKUP_LOCATION="$MCTMP" bash helper-scripts/backup_and_restore.sh backup all > /dev/null
tar czf - -C "$MCTMP" .
WRAPPER;

        $b64Wrapper = base64_encode($wrapperScript);

        $this->execScript(<<<BASH
PUB_KEY="\$(printf '%s' '{$b64Key}' | base64 -d)"

if ! id lintune-backup >/dev/null 2>&1; then
    useradd -r -m -s /bin/bash -d /home/lintune-backup lintune-backup
fi

usermod -aG docker lintune-backup

mkdir -p /home/lintune-backup/.ssh
chmod 700 /home/lintune-backup/.ssh

if ! grep -qF "\$PUB_KEY" /home/lintune-backup/.ssh/authorized_keys 2>/dev/null; then
    echo "\$PUB_KEY" >> /home/lintune-backup/.ssh/authorized_keys
fi

chmod 600 /home/lintune-backup/.ssh/authorized_keys
chown -R lintune-backup:lintune-backup /home/lintune-backup/.ssh

printf '%s' '{$b64Wrapper}' | base64 -d > /usr/local/sbin/lintune-mailcow-backup.sh
chmod 700 /usr/local/sbin/lintune-mailcow-backup.sh

printf 'Defaults:lintune-backup !requiretty, !use_pty\nlintune-backup ALL=(root) NOPASSWD: /usr/local/sbin/lintune-mailcow-backup.sh\n' > /etc/sudoers.d/lintune-backup
chmod 440 /etc/sudoers.d/lintune-backup

echo "  Backup user ready."
BASH);
    }

    public function joinHeadscaleNetwork(string $headscaleUrl, string $preAuthKey): string
    {
        $this->emit('→ Joining VPN mesh...');
        $b64Url = base64_encode($headscaleUrl);
        $b64Key = base64_encode($preAuthKey);

        $this->execScript(<<<BASH
HS_URL=\$(printf '%s' '{$b64Url}' | base64 -d)
HS_KEY=\$(printf '%s' '{$b64Key}' | base64 -d)

if ! command -v tailscale >/dev/null 2>&1; then
    echo "  Installing Tailscale..."
    curl -fsSL https://tailscale.com/install.sh | sh
fi

if command -v systemctl >/dev/null 2>&1; then
    systemctl enable --now tailscaled 2>/dev/null || true
fi

tailscale up --login-server="\$HS_URL" --authkey="\$HS_KEY" --accept-routes --accept-dns=false --reset

TAILSCALE_IP=\$(tailscale ip -4 2>/dev/null | head -1)
echo "  Tailscale IP: \$TAILSCALE_IP"
echo "CAPTURE:tailscale_ip:\$TAILSCALE_IP"
BASH);

        return $this->getCaptured('tailscale_ip') ?? '';
    }

    public function postConfigureMailcow(string $adminUsername, string $adminPassword): void
    {
        $this->emit('→ Configuring Mailcow admin account...');

        // Inject credentials via base64 to safely handle any special characters.
        $b64User = base64_encode($adminUsername);
        $b64Pass = base64_encode($adminPassword);

        $this->execScript(<<<BASH
MC_USER=\$(printf '%s' '{$b64User}' | base64 -d)
MC_PASS=\$(printf '%s' '{$b64Pass}' | base64 -d)

cd /opt/mailcow-dockerized

DBROOT=\$(grep '^DBROOT=' mailcow.conf | cut -d'=' -f2-)
[ -n "\$DBROOT" ] || { echo "  ERROR: Could not read DBROOT from mailcow.conf"; exit 1; }

# Wait for Mailcow's init scripts to finish creating the default admin row
echo "  Waiting for Mailcow to finish initializing..."
MC_INIT_READY=0
for i in \$(seq 1 36); do
    COUNT=\$(docker compose exec -T mysql-mailcow mysql -u root -p"\$DBROOT" mailcow \
        --skip-column-names -e "SELECT COUNT(*) FROM admin WHERE username='admin'" < /dev/null 2>/dev/null | tr -d '[:space:]\\r\\n')
    if [ "\$COUNT" = "1" ]; then
        MC_INIT_READY=1
        echo "  Mailcow initialized (attempt \$i)."
        break
    fi
    echo "  Waiting... attempt \$i/36"
    sleep 5
done
[ "\$MC_INIT_READY" = "1" ] || { echo "  ERROR: Mailcow did not initialize after 3 min"; exit 1; }

# Hash password using dovecot — SSHA256 is the format Mailcow expects
MC_HASH=\$(docker compose exec -T dovecot-mailcow doveadm pw -s SSHA256 -p "\$MC_PASS" < /dev/null | tr -d '\\r\\n')
[ -n "\$MC_HASH" ] || { echo "  ERROR: Could not generate SSHA256 hash"; exit 1; }

# Insert new superadmin
docker compose exec -T mysql-mailcow mysql -u root -p"\$DBROOT" mailcow -e \\
    "INSERT INTO admin (username, password, superadmin, active) \\
     VALUES ('\$MC_USER', '\$MC_HASH', 1, 1) \\
     ON DUPLICATE KEY UPDATE password='\$MC_HASH', active=1" < /dev/null
echo "  Admin '\$MC_USER' created."

# Remove default admin:moohoo and all associated rows
docker compose exec -T mysql-mailcow mysql -u root -p"\$DBROOT" mailcow -e \\
    "DELETE FROM tfa WHERE username='admin';
     DELETE FROM domain_admins WHERE username='admin';
     DELETE FROM admin WHERE username='admin';" < /dev/null
echo "  Default admin removed."
echo "  Mailcow post-configuration complete."
BASH);
    }
}
