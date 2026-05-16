<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

class VaultwardenService
{
    private string $baseUrl;
    private string $adminToken;

    public function __construct()
    {
        $this->baseUrl    = rtrim(Setting::get('vaultwarden.internal_url', config('vaultwarden.internal_url', 'http://vaultwarden:80')), '/');
        $this->adminToken = Setting::get('vaultwarden.admin_token', '');
    }

    public function isReachable(): bool
    {
        try {
            return Http::timeout(5)->get("{$this->baseUrl}/")->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function session(): string
    {
        $response = Http::timeout(10)->asForm()
            ->post("{$this->baseUrl}/admin/", ['token' => $this->adminToken]);

        if ($response->failed()) {
            throw new \RuntimeException("Vaultwarden admin login failed: HTTP {$response->status()}");
        }

        foreach ((array) ($response->headers()['Set-Cookie'] ?? []) as $header) {
            if (preg_match('/VW_ADMIN=([^;]+)/', $header, $m)) {
                return $m[1];
            }
        }

        throw new \RuntimeException('Vaultwarden admin login returned no session cookie — check admin token');
    }

    private function adminRequest(string $method, string $path, array $body = []): mixed
    {
        $session  = $this->session();
        $http     = Http::timeout(15)->withHeaders(['Cookie' => "VW_ADMIN={$session}"]);
        $url      = "{$this->baseUrl}{$path}";

        $response = match ($method) {
            'GET'    => $http->get($url),
            'POST'   => $http->post($url, $body),
            'DELETE' => $http->delete($url),
            default  => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };

        if ($response->failed()) {
            throw new \RuntimeException("Vaultwarden API error: HTTP {$response->status()} on {$method} {$path}");
        }

        return $response->json() ?? true;
    }

    public function getConfig(): array
    {
        return (array) $this->adminRequest('GET', '/admin/config');
    }

    public function pushConfig(array $config): void
    {
        $this->adminRequest('POST', '/admin/config', $config);
    }

    public function addDomainToWhitelist(string $domain): void
    {
        $config  = $this->getConfig();
        $current = array_filter(explode(',', $config['signups_domains_whitelist'] ?? ''));

        if (!in_array($domain, $current, true)) {
            $current[] = $domain;
            $this->pushConfig(['signups_domains_whitelist' => implode(',', array_values($current))]);
        }
    }

    public function removeDomainFromWhitelist(string $domain): void
    {
        $config  = $this->getConfig();
        $current = array_filter(explode(',', $config['signups_domains_whitelist'] ?? ''));
        $updated = array_values(array_filter($current, fn($d) => $d !== $domain));
        $this->pushConfig(['signups_domains_whitelist' => implode(',', $updated)]);
    }
}
