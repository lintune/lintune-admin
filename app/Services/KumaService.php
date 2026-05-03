<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class KumaService
{
    private string $url;

    public function __construct()
    {
        $this->url = rtrim(env('KUMA_INTERNAL_URL', 'http://uptime-kuma:3001'), '/');
    }

    public function waitForReady(int $attempts = 30, int $sleepSeconds = 2): bool
    {
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $res = Http::timeout(3)->get("{$this->url}/");
                if ($res->status() < 500) {
                    return true;
                }
            } catch (\Throwable) {}
            sleep($sleepSeconds);
        }
        return false;
    }

    // Returns false if already set up (which is fine — idempotent)
    public function setup(string $username, string $password): bool
    {
        $res = Http::timeout(10)->post("{$this->url}/setup", [
            'username' => $username,
            'password' => $password,
        ]);
        return $res->successful();
    }

    public function login(string $username, string $password): string
    {
        $res = Http::timeout(10)->post("{$this->url}/api/auth/login", [
            'username' => $username,
            'password' => $password,
            'otp'      => '',
        ]);

        if ($res->failed() || !$res->json('ok')) {
            throw new \RuntimeException('Kuma login failed: ' . $res->body());
        }

        return $res->json('token');
    }

    public function createApiKey(string $token, string $name): string
    {
        $res = Http::withToken($token)->timeout(10)->post("{$this->url}/api/api-keys", [
            'name'      => $name,
            'expiredAt' => null,
        ]);

        if ($res->failed() || !$res->json('ok')) {
            throw new \RuntimeException('Kuma API key creation failed: ' . $res->body());
        }

        return $res->json('key');
    }

    // Returns the new monitor ID
    public function addMonitor(string $apiKey, string $name, string $url, string $type = 'http'): int
    {
        $res = Http::withHeaders(['Authorization' => "apikey {$apiKey}"])
            ->timeout(10)
            ->post("{$this->url}/api/monitors", [
                'type'          => $type,
                'name'          => $name,
                'url'           => $url,
                'interval'      => 60,
                'retryInterval' => 60,
                'maxretries'    => 1,
                'active'        => true,
            ]);

        if ($res->failed() || !$res->json('ok')) {
            throw new \RuntimeException("Failed to add monitor '{$name}': " . $res->body());
        }

        return (int) $res->json('monitorId');
    }

    // Configure OIDC — non-fatal, endpoint may vary by Kuma version
    public function configureOidc(string $token, string $discoveryUrl, string $clientId, string $clientSecret, string $redirectUrl): void
    {
        Http::withToken($token)->timeout(10)->patch("{$this->url}/api/settings", [
            'oidcEnabled'      => true,
            'oidcAutoLogin'    => false,
            'oidcButtonText'   => 'Login with Keycloak',
            'oidcDiscoveryUrl' => $discoveryUrl,
            'oidcClientId'     => $clientId,
            'oidcClientSecret' => $clientSecret,
            'oidcRedirectUrl'  => $redirectUrl,
            'oidcScopes'       => 'openid profile email',
        ]);
    }

    // Returns [{id, name, status, url, admin_only}]
    // status: 0=down, 1=up, 2=pending/unknown
    // admin_only: true for monitors that should not appear in the tenant dash
    public function getStatus(string $apiKey): array
    {
        try {
            $res = Http::withHeaders(['Authorization' => "apikey {$apiKey}"])
                ->timeout(5)
                ->get("{$this->url}/api/monitors");

            if ($res->failed()) {
                return [];
            }

            $monitors = [];
            foreach ((array) $res->json('monitors') as $m) {
                if (empty($m['active'])) {
                    continue;
                }
                $monitors[] = [
                    'id'         => $m['id'],
                    'name'       => $m['name'],
                    'status'     => $m['status'] ?? 2,
                    'url'        => $m['url'] ?? '',
                    'admin_only' => str_contains(strtolower($m['name'] ?? ''), 'aio'),
                ];
            }
            return $monitors;
        } catch (\Throwable) {
            return [];
        }
    }
}
