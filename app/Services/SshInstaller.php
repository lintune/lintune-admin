<?php

namespace App\Services;

use phpseclib3\Net\SSH2;

class SshInstaller
{
    private SSH2 $ssh;
    private bool $useSudo;
    private array $log = [];

    public function __construct(string $host, string $user, string $password, int $port = 22)
    {
        $this->useSudo = ($user !== 'root');
        $ssh = new SSH2($host, $port, 30);
        if (!$ssh->login($user, $password)) {
            throw new \RuntimeException("SSH authentication failed for {$user}@{$host}");
        }
        $this->ssh = $ssh;
    }

    public function run(string $command): string
    {
        $cmd    = $this->useSudo ? "sudo -n {$command}" : $command;
        $this->log[] = "$ {$command}";
        $output = $this->ssh->exec($cmd);
        if ($out = trim($output)) {
            $this->log[] = $out;
        }
        return $output;
    }

    public function getLog(): array
    {
        return $this->log;
    }

    public function ensureDocker(): void
    {
        $this->log[] = '→ Checking Docker...';
        $has = trim($this->ssh->exec('command -v docker && echo yes || echo no'));
        if (str_contains($has, 'yes')) {
            $this->log[] = '  Docker already installed.';
            return;
        }
        $this->log[] = '  Installing Docker via get.docker.com...';
        $this->run('curl -fsSL https://get.docker.com | sh');
        $this->log[] = '  Docker installed.';
    }

    public function installKeycloak(string $adminPassword): void
    {
        $this->log[] = '→ Installing Keycloak...';
        $this->run('mkdir -p /opt/keycloak');

        $yaml = "services:\n  keycloak:\n    image: quay.io/keycloak/keycloak:latest\n    command: start-dev\n    environment:\n      KEYCLOAK_ADMIN: admin\n      KEYCLOAK_ADMIN_PASSWORD: {$adminPassword}\n    ports:\n      - \"8080:8080\"\n    restart: unless-stopped\n";

        // Write compose file via heredoc (avoids quoting issues)
        $this->ssh->exec("cat > /opt/keycloak/docker-compose.yml << 'EOLYAML'\n{$yaml}\nEOLYAML");
        $this->run('cd /opt/keycloak && docker compose up -d');
        $this->log[] = '  Keycloak container started.';
    }

    public function installMailcow(string $hostname, string $timezone = 'UTC'): void
    {
        $this->log[] = '→ Installing Mailcow...';
        $this->run('which git || (apt-get update -qq && apt-get install -y -qq git)');
        $this->run('test -d /opt/mailcow-dockerized || git clone https://github.com/mailcow/mailcow-dockerized /opt/mailcow-dockerized');
        $this->run("cd /opt/mailcow-dockerized && MAILCOW_HOSTNAME={$hostname} MAILCOW_TZ={$timezone} bash generate_config.sh");
        $this->run('cd /opt/mailcow-dockerized && docker compose pull -q');
        $this->run('cd /opt/mailcow-dockerized && docker compose up -d');
        $this->log[] = '  Mailcow started.';
    }

    public function installNextcloud(): void
    {
        $this->log[] = '→ Installing Nextcloud AIO...';
        $this->run('docker rm -f nextcloud-aio-mastercontainer 2>/dev/null || true');
        $this->run(
            'docker run -d --name nextcloud-aio-mastercontainer ' .
            '-p 8080:8080 ' .
            '--add-host=host.docker.internal:host-gateway ' .
            '-e APACHE_PORT=11000 ' .
            '-v nextcloud_aio_mastercontainer:/mnt/docker-aio-config ' .
            '-v /var/run/docker.sock:/var/run/docker.sock:ro ' .
            'nextcloud/all-in-one:latest'
        );
        $this->log[] = '  Nextcloud AIO started.';
    }
}
