<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

class KumaService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('KUMA_INTERNAL_URL', 'http://uptime-kuma:3001'), '/');
        $this->apiKey  = Setting::get('kuma.api_key', '');
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $http = Http::withHeaders([
            'Authorization' => 'Basic ' . base64_encode('api:' . $this->apiKey),
        ])->timeout(10);

        $url = $this->baseUrl . $path;

        $response = match (strtolower($method)) {
            'get'    => $http->get($url),
            'post'   => $http->post($url, $body),
            'delete' => $http->delete($url),
            default  => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };

        if ($response->failed()) {
            throw new \RuntimeException("Kuma API error: HTTP {$response->status()} on {$method} {$path}");
        }

        return $response->json() ?? [];
    }

    public function addMonitor(string $name, string $url, bool $ignoreTls = false): int
    {
        $monitors = $this->request('get', '/api/lintune/monitors');
        foreach ($monitors as $m) {
            if ($m['name'] === $name) {
                return (int) $m['id'];
            }
        }

        $result = $this->request('post', '/api/lintune/monitors', [
            'name'      => $name,
            'url'       => $url,
            'ignoreTls' => $ignoreTls,
        ]);

        return (int) ($result['id'] ?? 0);
    }

    public function removeMonitor(string $name): void
    {
        try {
            $monitors = $this->request('get', '/api/lintune/monitors');
            foreach ($monitors as $m) {
                if ($m['name'] === $name) {
                    $this->request('delete', '/api/lintune/monitors/' . $m['id']);
                    return;
                }
            }
        } catch (\Throwable) {}
    }

    public function getStatus(): array
    {
        try {
            return $this->request('get', '/api/lintune/monitors');
        } catch (\Throwable) {
            return [];
        }
    }
}
