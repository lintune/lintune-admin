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

    public function __construct(string $host, string $user, string $password, int $port = 22)
    {
        $this->useSudo = ($user !== 'root');
        $ssh = new SSH2($host, $port);
        $ssh->setTimeout(0); // no timeout — installs can take many minutes
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

        // Build compose YAML as a plain string (no heredoc nesting confusion)
        $composeYaml = implode("\n", array_filter([
            'services:',
            '  keycloak:',
            '    image: quay.io/keycloak/keycloak:latest',
            "    command: {$kcCommand}",
            '    environment:',
            "      KEYCLOAK_ADMIN: {$adminUsername}",
            "      KEYCLOAK_ADMIN_PASSWORD: {$adminPassword}",
            $extraEnv ?: null,
            '    ports:',
            "      - \"{$externalPort}:8080\"",
            '    volumes:',
            '      - keycloak_data:/opt/keycloak/data',
            '    restart: unless-stopped',
            '',
            'volumes:',
            '  keycloak_data:',
        ]));

        $this->execScript(<<<BASH
mkdir -p /opt/keycloak
cat > /opt/keycloak/docker-compose.yml << 'EOLYAML'
{$composeYaml}
EOLYAML
cd /opt/keycloak
docker compose up -d
echo "  Keycloak container started."

# Suppress the "temporary admin" warning by re-applying the password via kcadm.sh
# with --temporary false. Keycloak's bootstrap env-var path always marks the first
# account as temporary; this clears that flag once the container is ready.
echo "  Waiting for Keycloak to accept kcadm connections..."
KC_READY=0
for i in \$(seq 1 24); do
    docker compose exec -T keycloak /opt/keycloak/bin/kcadm.sh \
        config credentials \
        --server http://localhost:8080 \
        --realm master \
        --user '{$adminUsername}' \
        --password '{$adminPassword}' \
        >/dev/null 2>&1 && KC_READY=1 && break
    sleep 5
done
if [ "\$KC_READY" = "1" ]; then
    docker compose exec -T keycloak /opt/keycloak/bin/kcadm.sh \
        set-password \
        --username '{$adminUsername}' \
        --new-password '{$adminPassword}' \
        --temporary false >/dev/null 2>&1 \
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
# Pull each service individually so the terminal shows clear per-image progress.
echo "  Pulling Mailcow images (one by one)..."
for svc in \$(docker compose config --services); do
    echo "  Pulling \$svc..."
    docker compose pull "\$svc"
done
docker compose up -d
echo "  Mailcow started."
BASH);
    }

    public function installNextcloud(string $domain, string $timezone = 'UTC', bool $clean = false): void
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

        $this->execScript(<<<BASH
# Ensure wget and curl are present (needed for AIO page fetch and API calls)
for pkg in wget curl; do
    command -v "\$pkg" >/dev/null 2>&1 || apt-get install -y -qq "\$pkg"
done

mkdir -p /opt/nextcloud-aio
cat > /opt/nextcloud-aio/docker-compose.yml << 'EOLYAML'
{$composeYaml}
EOLYAML
cd /opt/nextcloud-aio
docker compose up -d
echo "  Nextcloud AIO mastercontainer started."

# Poll with wget until AIO responds, and parse the passphrase directly from the
# rendered HTML — it appears inline as:
#   <span id="initial-password" class="monospace">PASSPHRASE</span>
# This is simpler and more reliable than reading configuration.json.
echo "  Waiting for AIO to become ready..."
PASSPHRASE=""
for i in \$(seq 1 24); do
    PAGE=\$(wget -qO- --no-check-certificate https://localhost:9080/ 2>/dev/null || echo "")
    if [ -n "\$PAGE" ]; then
        PASSPHRASE=\$(printf '%s' "\$PAGE" \\
            | sed -n 's/.*id="initial-password"[^>]*>\([^<]*\)<.*/\1/p' \\
            | head -1 | tr -d '\\r')
        if [ -n "\$PASSPHRASE" ]; then
            echo "  AIO ready. Passphrase captured from page (attempt \$i)."
            break
        fi
        echo "  Page returned but passphrase not found yet... attempt \$i/24"
    else
        echo "  Attempt \$i/24..."
    fi
    sleep 5
done

# Fallback: read passphrase from configuration.json inside the container.
# The key is "password" (with a space after the colon in pretty-printed JSON).
if [ -z "\$PASSPHRASE" ]; then
    echo "  Trying configuration.json for passphrase..."
    PASSPHRASE=\$(docker exec nextcloud-aio-mastercontainer \\
        sh -c 'cat /mnt/docker-aio-config/data/configuration.json 2>/dev/null' \\
        | sed -n 's/.*"password" *: *"\([^"]*\)".*/\1/p' | head -1)
fi

if [ -z "\$PASSPHRASE" ]; then
    echo "  WARNING: Could not get AIO passphrase. Configure manually at https://<server>:9080"
    exit 0
fi

# Nextcloud admin password is written to configuration.json on first page fetch —
# which happened in the loop above, so it should be available now.
NC_PASS=\$(docker exec nextcloud-aio-mastercontainer \\
    sh -c 'cat /mnt/docker-aio-config/data/configuration.json 2>/dev/null' \\
    | sed -n 's/.*"NEXTCLOUD_PASSWORD" *: *"\([^"]*\)".*/\1/p' | head -1)

# Login to AIO API
LOGIN_OUT=\$(curl -sk -c /tmp/aio.jar \\
    -X POST https://localhost:9080/api/auth/login \\
    -H "Content-Type: application/json" \\
    -d "{\"password\":\"\$PASSPHRASE\"}" 2>&1)
echo "  Login: \$LOGIN_OUT"

# Configure domain and timezone
CFG_OUT=\$(curl -sk -b /tmp/aio.jar \\
    -X POST https://localhost:9080/api/configuration \\
    -H "Content-Type: application/json" \\
    -d '{"nextcloud_domain":"{$domain}","timezone":"{$timezone}"}' 2>&1)
echo "  Config: \$CFG_OUT"

# Start all child containers
START_OUT=\$(curl -sk -b /tmp/aio.jar \\
    -X POST https://localhost:9080/api/start 2>&1)
echo "  Start: \$START_OUT"

rm -f /tmp/aio.jar
echo "  Nextcloud AIO setup complete. Domain: {$domain} — Timezone: {$timezone}"

if [ -n "\$NC_PASS" ]; then
    echo "CAPTURE:nc_admin_pass:\$NC_PASS"
    echo "  Nextcloud admin password captured."
else
    echo "  NOTE: Nextcloud admin password not yet in config."
    echo "  Retrieve later: docker exec nextcloud-aio-mastercontainer sh -c 'grep NEXTCLOUD_PASSWORD /mnt/docker-aio-config/data/configuration.json'"
fi
BASH);
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

# Read MySQL root password from mailcow.conf (generated by generate_config.sh)
DBROOT=\$(grep '^DBROOT=' mailcow.conf | cut -d'=' -f2-)
[ -n "\$DBROOT" ] || { echo "  ERROR: Could not read DBROOT from mailcow.conf"; exit 1; }

# Wait for Mailcow's init scripts to finish creating the default admin row.
# Polling MySQL ping alone is not enough — the admin table is populated by
# Mailcow PHP scripts that run after MySQL accepts connections, and inserting
# into the api table (FK -> admin.username) fails if admin doesn't exist yet.
echo "  Waiting for Mailcow to finish initializing..."
MC_INIT_READY=0
for i in \$(seq 1 36); do
    COUNT=\$(docker compose exec -T mysql-mailcow mysql -u root -p"\$DBROOT" mailcow \
        --skip-column-names -e "SELECT COUNT(*) FROM admin WHERE username='admin'" 2>/dev/null | tr -d '[:space:]\\r\\n')
    if [ "\$COUNT" = "1" ]; then
        MC_INIT_READY=1
        echo "  Mailcow initialized (attempt \$i)."
        break
    fi
    echo "  Waiting... attempt \$i/36"
    sleep 5
done
[ "\$MC_INIT_READY" = "1" ] || { echo "  ERROR: Mailcow admin account not found after 3 min"; exit 1; }

# Generate bootstrap API key for the default admin account
BOOTSTRAP_KEY=\$(openssl rand -hex 24)
docker compose exec -T mysql-mailcow mysql -u root -p"\$DBROOT" mailcow -e \\
    "INSERT INTO api (username, api_key, active, skip_ip_check, allow_from) \\
     VALUES ('admin', '\$BOOTSTRAP_KEY', 1, 1, '') \\
     ON DUPLICATE KEY UPDATE api_key='\$BOOTSTRAP_KEY', active=1, skip_ip_check=1"
echo "  Bootstrap API key set."

# Wait for the Mailcow HTTP API to respond
echo "  Waiting for Mailcow API..."
MC_API_READY=0
for i in \$(seq 1 24); do
    HTTP=\$(curl -sk -o /dev/null -w "%{http_code}" \\
        -H "X-API-Key: \$BOOTSTRAP_KEY" \\
        https://localhost/api/v1/get/domain/all 2>/dev/null || echo "0")
    if [ "\$HTTP" = "200" ]; then
        echo "  Mailcow API ready."
        MC_API_READY=1
        break
    fi
    echo "  Attempt \$i/24 (HTTP \$HTTP)..."
    sleep 5
done
[ "\$MC_API_READY" = "1" ] || { echo "  ERROR: Mailcow API not ready after 2 min"; exit 1; }

# Bcrypt-hash the new admin password using Mailcow's PHP-FPM container
MC_HASH=\$(docker compose exec -T php-fpm-mailcow \\
    php -r "echo password_hash('\$MC_PASS', PASSWORD_BCRYPT, ['cost' => 12]);" 2>/dev/null | tr -d '\\r\\n')
[ -n "\$MC_HASH" ] || { echo "  ERROR: Could not generate bcrypt hash"; exit 1; }

# Create new superadmin in the database (idempotent)
docker compose exec -T mysql-mailcow mysql -u root -p"\$DBROOT" mailcow -e \\
    "INSERT INTO admin (username, password, superadmin, active) \\
     VALUES ('\$MC_USER', '\$MC_HASH', 1, 1) \\
     ON DUPLICATE KEY UPDATE password='\$MC_HASH', active=1"
echo "  Admin '\$MC_USER' created."

# Generate and register API key for the new admin
NEW_API_KEY=\$(openssl rand -hex 24)
docker compose exec -T mysql-mailcow mysql -u root -p"\$DBROOT" mailcow -e \\
    "INSERT INTO api (username, api_key, active, skip_ip_check, allow_from) \\
     VALUES ('\$MC_USER', '\$NEW_API_KEY', 1, 1, '') \\
     ON DUPLICATE KEY UPDATE api_key='\$NEW_API_KEY', active=1, skip_ip_check=1"

# Remove default admin:moohoo account
docker compose exec -T mysql-mailcow mysql -u root -p"\$DBROOT" mailcow -e \\
    "DELETE FROM api WHERE username='admin'; DELETE FROM admin WHERE username='admin';"
echo "  Default admin removed."

echo "CAPTURE:mailcow_api_key:\$NEW_API_KEY"
echo "  Mailcow post-configuration complete."
BASH);
    }
}
