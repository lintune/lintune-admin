<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Setting;

class BackupService
{
    private string $shared;
    private string $storage;

    public function __construct()
    {
        $this->shared  = rtrim(env('BACKUP_SHARED_PATH', '/var/lintune-backup'), '/');
        $this->storage = rtrim(env('BACKUP_STORAGE_PATH', '/opt/lintune-backup/backups'), '/');
    }

    public function isEnabled(): bool
    {
        return Setting::get('backup.enabled', '0') === '1';
    }

    public function getSchedule(): string
    {
        return Setting::get('backup.cron', '0 2 * * *');
    }

    public function getLastStatus(): ?array
    {
        $file = $this->shared . '/last_backup.json';
        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public function setEnabled(bool $enabled): void
    {
        Setting::set('backup.enabled', $enabled ? '1' : '0');
    }

    public function setSchedule(string $cron): void
    {
        Setting::set('backup.cron', $cron);
        $this->writeFile('backup_cron', $cron);
    }

    public function triggerNow(): void
    {
        $this->writeFile('backup_now', '1');
    }

    public function writeServersJson(): void
    {
        $servers = Server::with('services')->get()->map(fn($server) => [
            'id'            => $server->id,
            'label'         => $server->label,
            'internal_host' => $server->internal_host,
            'ssh_user'      => $server->ssh_user,
            'ssh_port'      => $server->ssh_port,
            'services'      => $server->services->pluck('service')->values(),
        ]);

        $this->writeFile('servers.json', $servers->toJson(JSON_PRETTY_PRINT));
    }

    public function generateKeyPair(): string
    {
        $key = \phpseclib3\Crypt\RSA::createKey(4096);

        Setting::set('backup.private_key', $key->toString('OpenSSH'), true);
        Setting::set('backup.public_key', $key->getPublicKey()->toString('OpenSSH'));

        $this->writePrivateKey();

        return Setting::get('backup.public_key');
    }

    public function getPublicKey(): string
    {
        return Setting::get('backup.public_key', '');
    }

    public function writePrivateKey(): void
    {
        $key = Setting::get('backup.private_key', '');
        if ($key) {
            $this->writeFile('id_backup', $key, 0600);
        }
    }

    private function writeFile(string $name, string $content, int $mode = 0644): void
    {
        if (!is_dir($this->shared)) {
            return;
        }

        $path = $this->shared . '/' . $name;
        file_put_contents($path, $content);
        chmod($path, $mode);
    }
}
