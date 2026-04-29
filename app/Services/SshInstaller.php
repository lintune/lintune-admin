<?php

namespace App\Services;

use phpseclib3\Net\SSH2;

class SshInstaller
{
    private SSH2 $ssh;
    private bool $useSudo;
    private array $log = [];
    private \Closure|null $outputCallback = null;

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
            if (trim($line) !== '') {
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
    public function installKeycloak(string $adminPassword, int $externalPort = 8080, ?string $hostname = null): void
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
            '      KEYCLOAK_ADMIN: admin',
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
docker compose pull -q
docker compose up -d
echo "  Mailcow started."
BASH);
    }

    public function installNextcloud(): void
    {
        $this->emit('→ Installing Nextcloud AIO...');
        // Port 9080 for AIO management UI (avoids conflict with Keycloak on 8080).
        // APACHE_PORT=11000: Nextcloud web interface port once AIO setup completes.
        $this->execScript(<<<'BASH'
docker rm -f nextcloud-aio-mastercontainer 2>/dev/null || true
docker run -d \
    --name nextcloud-aio-mastercontainer \
    -p 9080:8080 \
    --add-host=host.docker.internal:host-gateway \
    -e APACHE_PORT=11000 \
    -e APACHE_IP_BINDING=0.0.0.0 \
    -e SKIP_DOMAIN_VALIDATION=true \
    -v nextcloud_aio_mastercontainer:/mnt/docker-aio-config \
    -v /var/run/docker.sock:/var/run/docker.sock:ro \
    nextcloud/all-in-one:latest
echo "  Nextcloud AIO started. AIO admin interface on port 9080."
BASH);
    }
}
