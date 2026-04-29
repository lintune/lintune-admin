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
        $ssh = new SSH2($host, $port, 30);
        if (!$ssh->login($user, $password)) {
            throw new \RuntimeException("SSH authentication failed for {$user}@{$host}");
        }
        $this->ssh = $ssh;
    }

    public function setOutputCallback(\Closure $cb): void
    {
        $this->outputCallback = $cb;
    }

    public function run(string $command): string
    {
        $cmd = $this->useSudo ? "sudo -n {$command}" : $command;
        $this->emit("$ {$command}");

        $collected = '';
        $self      = $this;

        $this->ssh->exec($cmd, function (string $data) use (&$collected, $self) {
            $collected .= $data;
            foreach (explode("\n", $data) as $line) {
                if (trim($line) !== '') {
                    $self->emit($line);
                }
            }
        });

        return $collected;
    }

    /** @internal called from closure above */
    public function emit(string $line): void
    {
        $this->log[] = $line;
        if ($this->outputCallback) {
            ($this->outputCallback)($line);
        }
    }

    public function getLog(): array
    {
        return $this->log;
    }

    public function ensureDocker(): void
    {
        $this->emit('→ Checking Docker...');
        $has = trim($this->ssh->exec('command -v docker && echo yes || echo no'));
        if (str_contains($has, 'yes')) {
            $this->emit('  Docker already installed.');
            return;
        }
        $this->emit('  Installing Docker via get.docker.com...');
        $this->run('curl -fsSL https://get.docker.com | sh');
        $this->emit('  Docker installed.');
    }

    /**
     * @param int    $externalPort  Host port that maps to container port 8080.
     * @param string|null $hostname Public hostname for KC_HOSTNAME (enables production mode + reverse-proxy config).
     */
    public function installKeycloak(string $adminPassword, int $externalPort = 8080, ?string $hostname = null): void
    {
        $this->emit('→ Installing Keycloak...');
        $this->run('mkdir -p /opt/keycloak');

        if ($hostname) {
            // Production mode with reverse-proxy headers
            $command = 'start';
            $extraEnv = <<<YAML
      KC_PROXY_HEADERS: xforwarded
      KC_HOSTNAME: {$hostname}
      KC_HTTP_ENABLED: "true"
      KC_HOSTNAME_STRICT_HTTPS: "false"
YAML;
        } else {
            // Dev mode — direct IP:port access, no hostname required
            $command  = 'start-dev';
            $extraEnv = '';
        }

        $yaml = <<<YAML
services:
  keycloak:
    image: quay.io/keycloak/keycloak:latest
    command: {$command}
    environment:
      KEYCLOAK_ADMIN: admin
      KEYCLOAK_ADMIN_PASSWORD: {$adminPassword}
{$extraEnv}
    ports:
      - "{$externalPort}:8080"
    restart: unless-stopped
YAML;

        $this->ssh->exec("cat > /opt/keycloak/docker-compose.yml << 'EOLYAML'\n{$yaml}\nEOLYAML");
        $this->run('cd /opt/keycloak && docker compose up -d');
        $this->emit('  Keycloak container started.');
    }

    public function installMailcow(string $hostname, string $timezone = 'UTC'): void
    {
        $this->emit('→ Installing Mailcow...');
        $this->run('which git || (apt-get update -qq && apt-get install -y -qq git)');
        $this->run('test -d /opt/mailcow-dockerized || git clone https://github.com/mailcow/mailcow-dockerized /opt/mailcow-dockerized');
        $this->run("cd /opt/mailcow-dockerized && MAILCOW_HOSTNAME={$hostname} MAILCOW_TZ={$timezone} bash generate_config.sh");
        $this->run('cd /opt/mailcow-dockerized && docker compose pull -q');
        $this->run('cd /opt/mailcow-dockerized && docker compose up -d');
        $this->emit('  Mailcow started.');
    }

    public function installNextcloud(): void
    {
        $this->emit('→ Installing Nextcloud AIO...');
        $this->run('docker rm -f nextcloud-aio-mastercontainer 2>/dev/null || true');
        // AIO management UI on port 9080 (avoids conflict with Keycloak's 8080).
        // APACHE_PORT=11000: Nextcloud's actual web interface after AIO setup completes.
        $this->run(
            'docker run -d --name nextcloud-aio-mastercontainer ' .
            '-p 9080:8080 ' .
            '--add-host=host.docker.internal:host-gateway ' .
            '-e APACHE_PORT=11000 ' .
            '-e APACHE_IP_BINDING=0.0.0.0 ' .
            '-e SKIP_DOMAIN_VALIDATION=true ' .
            '-v nextcloud_aio_mastercontainer:/mnt/docker-aio-config ' .
            '-v /var/run/docker.sock:/var/run/docker.sock:ro ' .
            'nextcloud/all-in-one:latest'
        );
        $this->emit('  Nextcloud AIO started. AIO admin interface on port 9080.');
    }
}
