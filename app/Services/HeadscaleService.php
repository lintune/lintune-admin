<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

class HeadscaleService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(Setting::get('headscale.internal_url', 'http://headscale:8080'), '/');
        $this->apiKey  = Setting::get('headscale.api_key', '');

        if (!$this->apiKey) {
            // Read from .env file directly — the key is written by install.sh after container
            // start, so it won't be in the PHP env vars loaded at container boot time.
            $env = @file_get_contents(base_path('.env'));
            if ($env && preg_match('/^HEADSCALE_API_KEY=(.+)$/m', $env, $m)) {
                $this->apiKey = trim($m[1]);
            }
        }
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->apiKey)->timeout(10);
    }

    public function isReachable(): bool
    {
        try {
            return $this->http()->get("{$this->baseUrl}/api/v1/user")->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ensureUser(string $name): void
    {
        $res   = $this->http()->get("{$this->baseUrl}/api/v1/user");
        $users = $res->json('users') ?? [];

        $exists = collect($users)->contains(fn($u) => ($u['name'] ?? '') === $name);
        if ($exists) {
            return;
        }

        $res = $this->http()->post("{$this->baseUrl}/api/v1/user", ['name' => $name]);
        if ($res->failed()) {
            throw new \RuntimeException("Failed to create Headscale user '{$name}': " . $res->body());
        }
    }

    public function createPreAuthKey(string $user, bool $ephemeral = true): string
    {
        $res = $this->http()->post("{$this->baseUrl}/api/v1/preauthkey", [
            'user'       => $user,
            'reusable'   => false,
            'ephemeral'  => $ephemeral,
            'expiration' => now()->addHours(2)->toRfc3339String(),
        ]);

        if ($res->failed()) {
            throw new \RuntimeException("Failed to create pre-auth key: " . $res->body());
        }

        $key = $res->json('preAuthKey.key') ?? $res->json('key') ?? null;
        if (!$key) {
            throw new \RuntimeException("No key in pre-auth key response: " . $res->body());
        }

        return $key;
    }

    public function getNodes(): array
    {
        return $this->http()->get("{$this->baseUrl}/api/v1/node")->json('nodes') ?? [];
    }
}
