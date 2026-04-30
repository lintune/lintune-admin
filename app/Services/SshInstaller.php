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
    private function execScript(string $script): string
    {
        $prefix = $this->useSudo ? 'sudo ' : '';
        $output = $this->ssh->exec("{$prefix}bash << 'LINTUNE_EOF'\nset -e\n{$script}\nLINTUNE_EOF");
        foreach (explode("\n", $output) as $line) {
            // Lines matching CAPTURE:key:value are stored silently, never sent to the terminal.
            if (preg_match('/^CAPTURE:([^:]+):(.*)$/', $line, $m)) {
                $this->captured[$m[1]] = $m[2];
            } elseif (trim($line) !== '') {
                $this->emit($line);
            }
        }
        return $output;
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
    public function installKeycloak(string $adminUsername, string $adminPassword, int $externalPort = 8080, ?string $hostname = null): void
    {
        $this->emit('→ Installing Keycloak...');

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
            '    restart: unless-stopped',
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

    public function installMailcow(string $hostname, string $timezone = 'UTC'): void
    {
        $this->emit('→ Installing Mailcow...');
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

    public function installNextcloud(string $domain, string $timezone = 'UTC'): void
    {
        $this->emit('→ Installing Nextcloud AIO...');
        // Port 9080 for AIO management UI (avoids conflict with Keycloak on 8080).
        // APACHE_PORT=11000: Nextcloud web interface port once AIO setup completes.
        // After the mastercontainer is up we use the AIO REST API to configure the
        // domain and timezone and start all child containers automatically.
        $this->execScript(<<<BASH
# Ensure jq and curl are present (needed for AIO API calls)
for pkg in curl jq; do
    command -v "\$pkg" >/dev/null 2>&1 || apt-get install -y -qq "\$pkg"
done

docker rm -f nextcloud-aio-mastercontainer 2>/dev/null || true
docker run -d \\
    --name nextcloud-aio-mastercontainer \\
    -p 9080:8080 \\
    --add-host=host.docker.internal:host-gateway \\
    -e APACHE_PORT=11000 \\
    -e APACHE_IP_BINDING=0.0.0.0 \\
    -e SKIP_DOMAIN_VALIDATION=true \\
    -v nextcloud_aio_mastercontainer:/mnt/docker-aio-config \\
    -v /var/run/docker.sock:/var/run/docker.sock:ro \\
    nextcloud/all-in-one:latest
echo "  Nextcloud AIO mastercontainer started."

# Wait for AIO management UI to respond (HTTPS, self-signed cert → -k)
echo "  Waiting for AIO to become ready..."
AIO_READY=0
for i in \$(seq 1 24); do
    HTTP=\$(curl -sk -o /dev/null -w "%{http_code}" https://localhost:9080/ 2>/dev/null || echo "0")
    if [ "\$HTTP" = "200" ] || [ "\$HTTP" = "302" ] || [ "\$HTTP" = "301" ]; then
        echo "  AIO ready (HTTP \$HTTP)."
        AIO_READY=1
        break
    fi
    echo "  Attempt \$i/24..."
    sleep 5
done
if [ "\$AIO_READY" = "0" ]; then
    echo "  WARNING: AIO did not respond in time. Configure manually at https://<server>:9080"
    exit 0
fi

# Extract passphrase from AIO config (stored in the named volume)
PASSPHRASE=""
for i in \$(seq 1 10); do
    PASSPHRASE=\$(docker exec nextcloud-aio-mastercontainer \\
        sh -c 'cat /mnt/docker-aio-config/data/configuration.json 2>/dev/null' \\
        | jq -r '.AIOPassword // empty' 2>/dev/null || echo "")
    [ -n "\$PASSPHRASE" ] && break
    sleep 3
done

if [ -z "\$PASSPHRASE" ]; then
    echo "  WARNING: Could not extract AIO passphrase. Configure Nextcloud manually at https://<server>:9080"
    exit 0
fi
echo "  Got AIO passphrase."

# Login — sets a session cookie used for subsequent API calls
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

# Capture the auto-generated Nextcloud admin password so Lintune can store it
# as the service credential without showing it in the terminal.
# AIO stores it in its config JSON; try the most common key names across versions.
NC_PASS=\$(docker exec nextcloud-aio-mastercontainer \\
    sh -c 'cat /mnt/docker-aio-config/data/configuration.json 2>/dev/null' \\
    | jq -r '.nextcloud_password // .NcPassword // .nextcloud-password // empty' 2>/dev/null || echo "")
if [ -n "\$NC_PASS" ]; then
    echo "CAPTURE:nc_admin_pass:\$NC_PASS"
else
    echo "  NOTE: Could not capture Nextcloud admin password from config. Set service credentials manually in Settings."
fi
BASH);
    }
}
