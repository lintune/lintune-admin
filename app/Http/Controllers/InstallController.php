<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\SshInstaller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class InstallController extends Controller
{
    public function __construct()
    {
        if ($this->isSetupComplete()) {
            abort(404);
        }
    }

    private function isSetupComplete(): bool
    {
        if (config('setup.complete')) {
            return true;
        }

        $env = @file_get_contents(base_path('.env'));
        return $env !== false && (bool) preg_match('/^SETUP_COMPLETE=true$/m', $env);
    }

    // ── Welcome ───────────────────────────────────────────────────────────────

    public function welcome()
    {
        return view('install.welcome');
    }

    // ── Guided: server type ───────────────────────────────────────────────────

    public function serverType()
    {
        return view('install.server-type');
    }

    // ── Guided: configure ─────────────────────────────────────────────────────

    public function configure(Request $request)
    {
        $type       = $request->query('type', session('install_server_type', 'single'));
        $baseDomain = env('BASE_DOMAIN');
        session(['install_server_type' => $type]);
        return view('install.configure', compact('type', 'baseDomain'));
    }

    // ── Guided: begin (validate, cache params, redirect to progress) ──────────

    public function run(Request $request)
    {
        $type = $request->input('server_type', 'single');

        $commonRules = [
            'admin_username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:50'],
            'admin_password' => [
                'required', 'string', 'min:10', 'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z0-9]).{10,}$/',
            ],
            'timezone' => ['required', 'string', 'max:64'],
            'nc_url'   => ['nullable', 'url', 'required_if:install_nextcloud,1'],
        ];

        if ($type === 'single') {
            $request->validate(array_merge($commonRules, [
                'ssh_host' => 'required|string',
                'ssh_user' => 'required|string',
                'ssh_pass' => 'required|string',
                'kc_port'  => 'required|integer|min:1|max:65535',
            ]));
        } else {
            $request->validate(array_merge($commonRules, [
                'kc_host' => 'required|string',
                'kc_user' => 'required|string',
                'kc_pass' => 'required|string',
                'kc_port' => 'required|integer|min:1|max:65535',
            ]));
        }

        $key = Str::uuid()->toString();
        Cache::put("install_params:{$key}", $request->except('_token'), now()->addHours(2));

        return redirect()->route('install.progress', $key);
    }

    // ── Guided: progress page ─────────────────────────────────────────────────

    public function progress(string $key)
    {
        $params = Cache::get("install_params:{$key}");
        if (!$params) {
            return redirect()->route('install.welcome');
        }
        $stages      = $this->getStages($params);
        $stageLabels = $this->stageLabels();
        return view('install.progress', compact('key', 'stages', 'stageLabels'));
    }

    // ── Guided: SSE stream ────────────────────────────────────────────────────

    /**
     * Streams a single install stage. Query params:
     *   stage  = keycloak | mailcow | nextcloud  (default: keycloak)
     *   retry  = 1  — wipe the stage directory before re-installing
     */
    public function stream(Request $request, string $key): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $params = Cache::get("install_params:{$key}");
        if (!$params) {
            abort(404);
        }

        $stage  = $request->query('stage', 'keycloak');
        $retry  = (bool) $request->query('retry', false);
        $stages = $this->getStages($params);

        if (!in_array($stage, $stages, true)) {
            abort(404);
        }

        return response()->stream(function () use ($key, $stage, $retry, $stages, $params) {
            set_time_limit(0);
            ignore_user_abort(true);

            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $emit = function (string $event, array $data) {
                echo "event: {$event}\n";
                echo 'data: ' . json_encode($data) . "\n\n";
                flush();
            };

            $priorLog        = Cache::get("install_log:{$key}", []);
            $log             = $priorLog;
            $type            = $params['server_type'] ?? 'single';
            $kcAdminUsername = $params['admin_username'];
            $kcAdminPassword = $params['admin_password'];

            $cb = function (string $line) use (&$log, $emit) {
                $log[] = $line;
                $emit('log', ['line' => $line]);
            };

            $stageIndex = array_search($stage, $stages, true);
            $nextStage  = $stages[$stageIndex + 1] ?? null;
            $isLast     = $nextStage === null;

            try {
                match ($stage) {
                    'keycloak'  => $this->runKeycloakStage($params, $type, $kcAdminUsername, $kcAdminPassword, $cb, $emit, $log, $retry),
                    'mailcow'   => $this->runMailcowStage($params, $type, $cb, $retry),
                    'nextcloud' => $this->runNextcloudStage($params, $type, $cb, $emit, $log, $retry),
                    default     => throw new \RuntimeException("Unknown stage: {$stage}"),
                };
            } catch (\Throwable $e) {
                $emit('error', ['message' => ucfirst($stage) . ' installation failed: ' . $e->getMessage()]);
                try {
                    $this->writeInstallLog($key, $stage, array_slice($log, count($priorLog)), $e->getMessage());
                } catch (\Throwable) {}
                return;
            }

            try {
                $this->writeInstallLog($key, $stage, array_slice($log, count($priorLog)), null);
            } catch (\Throwable) {}
            Cache::put("install_log:{$key}", $log, now()->addHours(2));

            if ($isLast) {
                Cache::forget("install_params:{$key}");
                Cache::put("install_result:{$key}", [
                    'log'           => $log,
                    'kcUrl'         => Setting::get('keycloak.url', ''),
                    'adminUsername' => $kcAdminUsername,
                ], now()->minutes(30));
                Cache::forget("install_log:{$key}");
                $emit('done', ['redirect' => route('install.done') . '?key=' . $key]);
            } else {
                $emit('done', ['next_stage' => $nextStage]);
            }

        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // ── Manual setup ──────────────────────────────────────────────────────────

    public function manual()
    {
        $repairMode  = !config('setup.complete')
            && config('keycloak.admin_user')
            && config('keycloak.admin_password');
        $keycloakUrl = Setting::get('keycloak.url', config('keycloak.base_url', ''));
        return view('install.manual', compact('repairMode', 'keycloakUrl'));
    }

    public function runManual(Request $request)
    {
        $request->validate([
            'keycloak_url' => 'required|url',
            'username'     => 'required|string',
            'password'     => 'required|string',
        ]);

        $base = rtrim($request->keycloak_url, '/');

        $tokenRes = \Http::asForm()->post("{$base}/realms/master/protocol/openid-connect/token", [
            'grant_type' => 'password',
            'client_id'  => 'admin-cli',
            'username'   => $request->username,
            'password'   => $request->password,
        ]);

        if ($tokenRes->failed()) {
            return back()->withErrors(['auth' => 'Invalid Keycloak credentials or URL.'])->withInput($request->except('password'));
        }

        $token = $tokenRes->json()['access_token'];
        $log   = ['→ Keycloak credentials verified.'];
        $emit  = fn(string $e, array $d) => null;

        try {
            $this->setupKeycloakWithToken($base, $base, $token, $log, $emit);
        } catch (\Throwable $e) {
            return back()->withErrors(['auth' => 'Keycloak setup failed: ' . $e->getMessage()])->withInput($request->except('password'));
        }

        session(['install_log' => $log, 'install_kc_url' => $base]);
        return redirect()->route('install.done');
    }

    // ── Done ──────────────────────────────────────────────────────────────────

    public function done(Request $request)
    {
        $key = $request->query('key');

        if ($key && Cache::has("install_result:{$key}")) {
            $result        = Cache::pull("install_result:{$key}");
            $log           = $result['log'] ?? [];
            $kcUrl         = $result['kcUrl'] ?? '';
            $adminUsername = $result['adminUsername'] ?? '';
        } else {
            $log           = session('install_log', []);
            $kcUrl         = session('install_kc_url', '');
            $adminUsername = '';
        }

        // Write SETUP_COMPLETE here rather than in the SSE stream so the
        // done page itself is accessible (stream writes it → constructor
        // sees it on the very next request → abort(404) before done() runs).
        $this->writeEnv(['SETUP_COMPLETE' => 'true']);
        Setting::set('wizard.complete', '1');

        return view('install.done', compact('log', 'kcUrl', 'adminUsername'));
    }

    // ── Stage runners ─────────────────────────────────────────────────────────

    private function runKeycloakStage(array $params, string $type, string $kcAdminUsername, string $kcAdminPassword, callable $cb, callable $emit, array &$log, bool $retry): void
    {
        if ($type === 'single') {
            $kcHost     = ($params['ssh_host'] ?? '') === '__local__' ? 'host.docker.internal' : ($params['ssh_host'] ?? '');
            $kcPort     = (int) ($params['kc_port'] ?? 8080);
            $kcHostname = !empty($params['kc_public_url']) ? parse_url(rtrim($params['kc_public_url'], '/'), PHP_URL_HOST) : null;
            $ssh        = new SshInstaller($kcHost, $params['ssh_user'], $params['ssh_pass']);
        } else {
            $kcHost     = $params['kc_host'] ?? '';
            $kcPort     = (int) ($params['kc_port'] ?? 8080);
            $kcHostname = !empty($params['kc_public_url']) ? parse_url(rtrim($params['kc_public_url'], '/'), PHP_URL_HOST) : null;
            $ssh        = new SshInstaller($kcHost, $params['kc_user'], $params['kc_pass']);
        }

        $ssh->setOutputCallback($cb);
        $ssh->ensureDocker();
        $ssh->installKeycloak($kcAdminUsername, $kcAdminPassword, $kcPort, $kcHostname, $retry);

        $kcInternalUrl = "http://{$kcHost}:{$kcPort}";
        $keycloakUrl   = !empty($params['kc_public_url']) ? rtrim($params['kc_public_url'], '/') : $kcInternalUrl;
        $base          = rtrim($kcInternalUrl, '/');

        $emit('log', ['line' => '→ Waiting for Keycloak to become ready (up to 5 min)...']);
        $log[] = '→ Waiting for Keycloak to become ready...';
        $ready = false;

        for ($i = 0; $i < 60; $i++) {
            try {
                $res = \Http::timeout(4)->get("{$base}/realms/master");
                if ($res->status() < 500) {
                    $ready = true;
                    break;
                }
            } catch (\Throwable) {}
            $emit('log', ['line' => "  Waiting... attempt " . ($i + 1) . " / 60 (5 s each)"]);
            sleep(5);
        }

        if (!$ready) {
            throw new \RuntimeException('Keycloak did not become ready in 5 min. Check the server and try manual setup.');
        }

        $emit('log', ['line' => '  Keycloak is ready.']);
        $log[] = '  Keycloak is ready.';

        $this->setupKeycloak($base, $keycloakUrl, $kcAdminUsername, $kcAdminPassword, $log, $emit);
    }

    private function runMailcowStage(array $params, string $type, callable $cb, bool $retry): void
    {
        $timezone = $params['timezone'] ?? 'UTC';

        if ($type === 'single') {
            $host = ($params['ssh_host'] ?? '') === '__local__' ? 'host.docker.internal' : ($params['ssh_host'] ?? '');
            $ssh  = new SshInstaller($host, $params['ssh_user'], $params['ssh_pass']);
        } else {
            $ssh = new SshInstaller($params['mc_host'], $params['mc_user'], $params['mc_pass']);
        }

        $ssh->setOutputCallback($cb);
        $ssh->ensureDocker();
        $ssh->installMailcow($params['mailcow_hostname'], $timezone, $retry);
        Setting::set('mailcow.url', "https://{$params['mailcow_hostname']}");

        $ssh->postConfigureMailcow($params['admin_username'], $params['admin_password']);
        $apiKey = $ssh->getCaptured('mailcow_api_key');
        if ($apiKey) {
            Setting::set('mailcow.api_key', $apiKey, true);
        }
    }

    private function runNextcloudStage(array $params, string $type, callable $cb, callable $emit, array &$log, bool $retry): void
    {
        $timezone = $params['timezone'] ?? 'UTC';
        $ncUrl    = rtrim($params['nc_url'], '/');
        $ncDomain = parse_url($ncUrl, PHP_URL_HOST);

        if ($type === 'single') {
            $host = ($params['ssh_host'] ?? '') === '__local__' ? 'host.docker.internal' : ($params['ssh_host'] ?? '');
            $ssh  = new SshInstaller($host, $params['ssh_user'], $params['ssh_pass']);
        } else {
            $ssh = new SshInstaller($params['nc_host'], $params['nc_user'], $params['nc_pass']);
        }

        $ssh->setOutputCallback($cb);
        $ssh->ensureDocker();
        $ssh->installNextcloud($ncDomain, $timezone, $retry, $params['admin_username'] ?? '', $params['admin_password'] ?? '');
        Setting::set('nextcloud.url', $ncUrl);
        if ($aioPass = $ssh->getCaptured('nc_aio_pass')) {
            Setting::set('nextcloud.aio_passphrase', $aioPass, encrypted: true);
        }
        if ($adminPass = $ssh->getCaptured('nc_admin_pass')) {
            Setting::set('nextcloud.admin_password', $adminPass, encrypted: true);
        }
        if ($svcPass = $ssh->getCaptured('nc_svc_pass')) {
            Setting::set('nextcloud.service_user', 'lintune-svc');
            Setting::set('nextcloud.service_password', $svcPass, encrypted: true);
        }

        // Create Nextcloud OIDC client in Keycloak and configure user_oidc app
        $kcBase      = rtrim(Setting::get('keycloak.url') ?? config('keycloak.base_url'), '/');
        $brokerRealm = Setting::get('keycloak.broker_realm') ?? config('keycloak.broker_realm');
        $kcToken     = $this->keycloakAdminToken($kcBase);
        $clientSecret = Str::random(40);

        $clientRes = \Http::withToken($kcToken)->post("{$kcBase}/admin/realms/{$brokerRealm}/clients", [
            'clientId'                  => 'nextcloud',
            'enabled'                   => true,
            'publicClient'              => false,
            'standardFlowEnabled'       => true,
            'directAccessGrantsEnabled' => false,
            'secret'                    => $clientSecret,
            'redirectUris'              => ["{$ncUrl}/apps/user_oidc/code", "{$ncUrl}/*"],
            'webOrigins'                => [$ncUrl],
        ]);

        if ($clientRes->status() === 409) {
            // Already exists (retry) — fetch the current secret
            $clients = \Http::withToken($kcToken)
                ->get("{$kcBase}/admin/realms/{$brokerRealm}/clients", ['clientId' => 'nextcloud'])
                ->json();
            $kcClientId = $clients[0]['id'] ?? null;
            if ($kcClientId) {
                $secretData   = \Http::withToken($kcToken)->get("{$kcBase}/admin/realms/{$brokerRealm}/clients/{$kcClientId}/client-secret")->json();
                $clientSecret = $secretData['value'];
            }
        } elseif ($clientRes->failed()) {
            throw new \RuntimeException('Failed to create Nextcloud OIDC client in Keycloak: ' . $clientRes->body());
        }

        $ssh->configureNextcloudOidc($kcBase, $brokerRealm, 'nextcloud', $clientSecret);
        Setting::set('nextcloud.oidc_client_id', 'nextcloud');
        Setting::set('nextcloud.oidc_client_secret', $clientSecret, encrypted: true);
    }

    private function keycloakAdminToken(string $base): string
    {
        $res = \Http::asForm()->post("{$base}/realms/master/protocol/openid-connect/token", [
            'grant_type' => 'password',
            'client_id'  => 'admin-cli',
            'username'   => config('keycloak.admin_user'),
            'password'   => decrypt(base64_decode(config('keycloak.admin_password'))),
        ]);

        if ($res->failed() || empty($res->json()['access_token'])) {
            throw new \RuntimeException('Could not obtain Keycloak admin token for OIDC setup.');
        }

        return $res->json()['access_token'];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getStages(array $params): array
    {
        $type   = $params['server_type'] ?? 'single';
        $stages = ['keycloak'];

        if ($type === 'single') {
            if (!empty($params['install_mailcow']) && !empty($params['mailcow_hostname'])) {
                $stages[] = 'mailcow';
            }
            if (!empty($params['install_nextcloud'])) {
                $stages[] = 'nextcloud';
            }
        } else {
            if (!empty($params['install_mailcow']) && !empty($params['mc_host']) && !empty($params['mailcow_hostname'])) {
                $stages[] = 'mailcow';
            }
            if (!empty($params['install_nextcloud']) && !empty($params['nc_host'])) {
                $stages[] = 'nextcloud';
            }
        }

        return $stages;
    }

    private function stageLabels(): array
    {
        return ['keycloak' => 'Keycloak', 'mailcow' => 'Mailcow', 'nextcloud' => 'Nextcloud'];
    }

    private function setupKeycloak(string $internalBase, string $publicBase, string $adminUsername, string $adminPassword, array &$log, callable $emit): void
    {
        $tokenRes = \Http::asForm()->post("{$internalBase}/realms/master/protocol/openid-connect/token", [
            'grant_type' => 'password',
            'client_id'  => 'admin-cli',
            'username'   => $adminUsername,
            'password'   => $adminPassword,
        ]);

        if ($tokenRes->failed()) {
            throw new \RuntimeException('Failed to get admin token from Keycloak: ' . $tokenRes->body());
        }

        $token = $tokenRes->json()['access_token'];
        $msg   = '→ Obtained Keycloak admin token.';
        $log[] = $msg;
        $emit('log', ['line' => $msg]);

        $this->setupKeycloakWithToken($internalBase, $publicBase, $token, $log, $emit);
    }

    private function setupKeycloakWithToken(string $internalBase, string $publicBase, string $token, array &$log, callable $emit): void
    {
        $appUrl = rtrim(config('app.url'), '/');

        // Create (or recreate) lintune-admin OIDC client
        $existing       = \Http::withToken($token)->get("{$internalBase}/admin/realms/master/clients", ['clientId' => 'lintune-admin'])->json();
        $existingClient = collect($existing)->firstWhere('clientId', 'lintune-admin');
        if ($existingClient) {
            \Http::withToken($token)->delete("{$internalBase}/admin/realms/master/clients/{$existingClient['id']}");
        }

        $clientRes = \Http::withToken($token)->post("{$internalBase}/admin/realms/master/clients", [
            'clientId'                  => 'lintune-admin',
            'name'                      => 'Lintune Admin',
            'enabled'                   => true,
            'publicClient'              => false,
            'standardFlowEnabled'       => true,
            'directAccessGrantsEnabled' => false,
            'serviceAccountsEnabled'    => false,
            'redirectUris'              => ["{$appUrl}/super/auth/callback"],
            'webOrigins'                => [$appUrl],
            'attributes'                => ['post.logout.redirect.uris' => "{$appUrl}/super/login"],
        ]);

        if ($clientRes->failed()) {
            throw new \RuntimeException('Failed to create OIDC client: ' . $clientRes->body());
        }

        $clientUuid   = basename($clientRes->header('Location'));
        $secretRes    = \Http::withToken($token)->get("{$internalBase}/admin/realms/master/clients/{$clientUuid}/client-secret");
        if ($secretRes->failed()) {
            throw new \RuntimeException('Failed to fetch client secret.');
        }
        $clientSecret = $secretRes->json()['value'];
        $msg          = '→ Created lintune-admin OIDC client.';
        $log[]        = $msg;
        $emit('log', ['line' => $msg]);

        // Broker realm
        $brokerRealm = config('keycloak.broker_realm');
        if (!$brokerRealm) {
            $brokerRealm = 'broker-' . Str::lower(Str::random(8));
            $brokerRes   = \Http::withToken($token)->post("{$internalBase}/admin/realms", [
                'realm'   => $brokerRealm,
                'enabled' => true,
            ]);
            if ($brokerRes->failed()) {
                throw new \RuntimeException('Failed to create broker realm: ' . $brokerRes->body());
            }
            $msg   = "→ Created broker realm '{$brokerRealm}'.";
            $log[] = $msg;
            $emit('log', ['line' => $msg]);
        }

        // Create lintune-service account
        $serviceUsername = 'lintune-service';
        $servicePassword = Str::password(32, symbols: false);

        $existing2 = \Http::withToken($token)->get("{$internalBase}/admin/realms/master/users", ['username' => $serviceUsername, 'exact' => true]);
        if ($existing2->successful() && !empty($existing2->json())) {
            \Http::withToken($token)->delete("{$internalBase}/admin/realms/master/users/{$existing2->json()[0]['id']}");
        }

        $userRes = \Http::withToken($token)->post("{$internalBase}/admin/realms/master/users", [
            'username'      => $serviceUsername,
            'enabled'       => true,
            'emailVerified' => true,
            'credentials'   => [['type' => 'password', 'value' => $servicePassword, 'temporary' => false]],
        ]);

        if ($userRes->failed()) {
            throw new \RuntimeException('Failed to create service user: ' . $userRes->body());
        }

        $serviceUserId = basename($userRes->header('Location'));
        $adminRole     = \Http::withToken($token)->get("{$internalBase}/admin/realms/master/roles/admin")->json();

        \Http::withToken($token)
            ->withBody(json_encode([[
                'id'          => $adminRole['id'],
                'name'        => $adminRole['name'],
                'composite'   => $adminRole['composite'],
                'clientRole'  => $adminRole['clientRole'],
                'containerId' => $adminRole['containerId'],
            ]]), 'application/json')
            ->post("{$internalBase}/admin/realms/master/users/{$serviceUserId}/role-mappings/realm");

        $msg   = '→ Created lintune-service account.';
        $log[] = $msg;
        $emit('log', ['line' => $msg]);

        // Save to .env + settings
        $this->writeEnv([
            'KEYCLOAK_BASE_URL'            => $publicBase,
            'KEYCLOAK_ADMIN_CLIENT_SECRET' => $clientSecret,
            'KEYCLOAK_ADMIN_USER'          => $serviceUsername,
            'KEYCLOAK_ADMIN_PASSWORD'      => base64_encode(encrypt($servicePassword)),
            'KEYCLOAK_BROKER_REALM'        => $brokerRealm,
        ]);

        Setting::set('keycloak.url', $publicBase);
        Setting::set('keycloak.broker_realm', $brokerRealm);

        \Artisan::call('config:clear');
        $msg   = '→ Configuration saved.';
        $log[] = $msg;
        $emit('log', ['line' => $msg]);
    }

    private function writeInstallLog(string $key, string $stage, array $lines, ?string $error): void
    {
        $dir = storage_path('logs/install');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $shortKey = substr(str_replace('-', '', $key), 0, 8);
        $path     = "{$dir}/{$shortKey}_{$stage}.log";

        $status = $error ? "FAILED: {$error}" : 'OK';
        $header = sprintf("[%s] stage=%s status=%s\n%s\n", now()->toIso8601String(), $stage, $status, str_repeat('-', 72));

        file_put_contents($path, $header . implode("\n", $lines) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function writeEnv(array $values): void
    {
        $path    = base_path('.env');
        $content = file_get_contents($path);

        foreach ($values as $key => $value) {
            $escaped = preg_quote($key, '/');
            if (preg_match("/^{$escaped}=/m", $content)) {
                $content = preg_replace("/^{$escaped}=.*/m", "{$key}={$value}", $content);
            } else {
                $content .= "\n{$key}={$value}";
            }
        }

        file_put_contents($path, $content);
    }
}
