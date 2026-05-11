<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\ServerService;
use App\Models\Setting;
use App\Services\BackupService;
use App\Services\HeadscaleService;
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

        // Test SSH connectivity for each unique remote host before committing to install.
        // Skips __local__ (host.docker.internal — can't test from within the container).
        $sshError = $this->validateSshCredentials($request, $type);
        if ($sshError) {
            return back()->withInput($request->except(['ssh_pass', 'kc_pass', 'mc_pass', 'nc_pass']))
                         ->withErrors(['ssh' => $sshError]);
        }

        $key = Str::uuid()->toString();
        Cache::put("install_params:{$key}", $request->except('_token'), now()->addHours(2));

        return redirect()->route('install.progress', $key);
    }

    // ── SSH validation ────────────────────────────────────────────────────────

    private function validateSshCredentials(Request $request, string $type): ?string
    {
        $tested = [];

        $tryConnect = function (string $label, string $host, string $user, string $pass) use (&$tested): ?string {
            if ($host === '__local__' || in_array($host, $tested, true)) {
                return null;
            }
            $tested[] = $host;
            try {
                new \App\Services\SshInstaller($host, $user, $pass, 22, 10);
            } catch (\Throwable $e) {
                return "Cannot connect to {$label} ({$user}@{$host}): {$e->getMessage()}";
            }
            return null;
        };

        if ($type === 'single') {
            $host = $request->input('ssh_host', '__local__');
            return $tryConnect('server', $host, $request->input('ssh_user'), $request->input('ssh_pass'));
        }

        // Multi-server: test each host that was provided
        if ($err = $tryConnect('Keycloak server', $request->input('kc_host', ''), $request->input('kc_user'), $request->input('kc_pass'))) {
            return $err;
        }
        if ($request->boolean('install_mailcow') && $request->filled('mc_host')) {
            if ($err = $tryConnect('Mailcow server', $request->input('mc_host'), $request->input('mc_user'), $request->input('mc_pass'))) {
                return $err;
            }
        }
        if ($request->boolean('install_nextcloud') && $request->filled('nc_host')) {
            if ($err = $tryConnect('Nextcloud server', $request->input('nc_host'), $request->input('nc_user'), $request->input('nc_pass'))) {
                return $err;
            }
        }
        return null;
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

        $stages = $this->getStages($params);
        $stage  = $request->query('stage', $stages[0] ?? 'keycloak');
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
                    'headscale' => $this->runHeadscaleStage($params, $cb, $emit),
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
        $this->initKuma($params, $base, $keycloakUrl, $kcAdminUsername, $kcAdminPassword, $log, $emit);

        $tailscaleIp = $this->maybeJoinHeadscale($params, $ssh, $cb);
        $this->maybeSetupBackup($params, $ssh, $cb);
        $this->recordServer($kcHost, 'keycloak', $keycloakUrl, $tailscaleIp);
        (new BackupService())->writeServersJson();
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

        $this->addKumaMonitor('Mailcow', "https://{$params['mailcow_hostname']}");

        $mcHost = $type === 'single'
            ? (($params['ssh_host'] ?? '') === '__local__' ? 'host.docker.internal' : ($params['ssh_host'] ?? ''))
            : ($params['mc_host'] ?? '');
        $tailscaleIp = $this->maybeJoinHeadscale($params, $ssh, $cb);
        $this->maybeSetupBackup($params, $ssh, $cb);
        $this->recordServer($mcHost, 'mailcow', "https://{$params['mailcow_hostname']}", $tailscaleIp);
        (new BackupService())->writeServersJson();
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

        $kcClientId = null;
        if ($clientRes->status() === 201) {
            $kcClientId = basename($clientRes->header('Location'));
        } elseif ($clientRes->status() === 409) {
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

        // Add nc_groups → groups attribute mapper on the nextcloud KC client so the broker
        // realm includes group names in Nextcloud tokens (enables user_oidc group restriction).
        if ($kcClientId) {
            $mappers     = \Http::withToken($kcToken)
                ->get("{$kcBase}/admin/realms/{$brokerRealm}/clients/{$kcClientId}/protocol-mappers/models")
                ->json();
            $mapperExists = collect((array) $mappers)->contains(fn($m) => ($m['name'] ?? '') === 'nc_groups');

            if (!$mapperExists) {
                \Http::withToken($kcToken)->post(
                    "{$kcBase}/admin/realms/{$brokerRealm}/clients/{$kcClientId}/protocol-mappers/models",
                    [
                        'name'           => 'nc_groups',
                        'protocol'       => 'openid-connect',
                        'protocolMapper' => 'oidc-usermodel-attribute-mapper',
                        'config'         => [
                            'user.attribute'       => 'nc_groups',
                            'claim.name'           => 'groups',
                            'jsonType.label'       => 'String',
                            'id.token.claim'       => 'true',
                            'access.token.claim'   => 'true',
                            'userinfo.token.claim' => 'true',
                            'multivalued'          => 'true',
                            'aggregate.attrs'      => 'false',
                        ],
                    ]
                );
            }
        }

        $ssh->configureNextcloudOidc($kcBase, $brokerRealm, 'nextcloud', $clientSecret);
        Setting::set('nextcloud.oidc_client_id', 'nextcloud');
        Setting::set('nextcloud.oidc_client_secret', $clientSecret, encrypted: true);

        $this->addKumaMonitor('Nextcloud', $ncUrl);
        $this->addKumaMonitor('Nextcloud AIO', "https://{$ncDomain}:8080", ignoreTls: true);

        $ncHost = $type === 'single'
            ? (($params['ssh_host'] ?? '') === '__local__' ? 'host.docker.internal' : ($params['ssh_host'] ?? ''))
            : ($params['nc_host'] ?? '');
        $tailscaleIp = $this->maybeJoinHeadscale($params, $ssh, $cb);
        $this->maybeSetupBackup($params, $ssh, $cb);
        $this->recordServer($ncHost, 'nextcloud', $ncUrl, $tailscaleIp);
        (new BackupService())->writeServersJson();
    }

    private function initKuma(array $params, string $kcInternalBase, string $keycloakUrl, string $kcAdminUsername, string $kcAdminPassword, array &$log, callable $emit): void
    {
        try {
            $emit('log', ['line' => '→ Setting up Uptime Kuma...']);

            $kumaUrl  = rtrim(env('KUMA_INTERNAL_URL', 'http://uptime-kuma:3001'), '/');
            $response = \Http::post("{$kumaUrl}/api/lintune/setup", [
                'username' => $kcAdminUsername,
                'password' => $kcAdminPassword,
            ]);

            if ($response->failed()) {
                throw new \RuntimeException("Setup returned HTTP {$response->status()}: {$response->body()}");
            }

            $apiKey = $response->json()['api_key'] ?? null;
            if (!$apiKey) {
                throw new \RuntimeException('No API key in setup response: ' . $response->body());
            }

            Setting::set('kuma.api_key', $apiKey, true);

            (new \App\Services\KumaService())->addMonitor('Keycloak', "{$keycloakUrl}/realms/master");

            $emit('log', ['line' => '  Uptime Kuma ready.']);
            $log[] = '  Uptime Kuma ready.';
        } catch (\Throwable $e) {
            $emit('log', ['line' => '  Warning: Kuma setup failed (non-fatal): ' . $e->getMessage()]);
            $log[] = '  Warning: Kuma setup failed: ' . $e->getMessage();
        }
    }

    private function recordServer(string $host, string $service, string $serviceUrl, ?string $internalHost = null): void
    {
        $server = Server::firstOrCreate(
            ['host' => $host, 'ssh_user' => 'lintune-backup'],
            ['label' => $host, 'internal_host' => $internalHost ?? $host, 'ssh_port' => 22]
        );

        // Update internal_host to the Tailscale IP if we have one and it's still the default
        if ($internalHost && $server->internal_host === $server->host) {
            $server->update(['internal_host' => $internalHost]);
        }

        $isFirst = !ServerService::where('service', $service)->exists();

        ServerService::updateOrCreate(
            ['server_id' => $server->id, 'service' => $service],
            ['service_url' => $serviceUrl, 'is_default' => $isFirst]
        );
    }

    private function runHeadscaleStage(array $params, callable $cb, callable $emit): void
    {
        $emit('log', ['line' => '→ Configuring VPN mesh (Headscale)...']);

        // API key written to .env by install.sh after container start — read file directly
        // because Laravel's immutable Dotenv won't see values added after container boot.
        $envContent = file_get_contents(base_path('.env'));
        preg_match('/^HEADSCALE_API_KEY=(.+)$/m', $envContent, $keyMatch);
        preg_match('/^HEADSCALE_URL=(.+)$/m', $envContent, $urlMatch);

        $apiKey       = trim($keyMatch[1] ?? '');
        $headscaleUrl = trim($urlMatch[1] ?? '');

        if (!$apiKey) {
            throw new \RuntimeException('Headscale API key not found in .env. Run install.sh again or check that Headscale started correctly.');
        }

        Setting::set('headscale.api_key', $apiKey, true);
        Setting::set('headscale.url', $headscaleUrl);
        Setting::set('headscale.internal_url', 'http://headscale:8080');
        Setting::set('headscale.enabled', '1');

        $hs = new HeadscaleService();

        if (!$hs->isReachable()) {
            throw new \RuntimeException('Cannot reach Headscale at http://headscale:8080. Is the container running?');
        }

        $emit('log', ['line' => '  Creating VPN user...']);
        $hs->ensureUser('lintune');

        $emit('log', ['line' => '  VPN mesh ready. Service servers will join automatically during installation.']);
        $cb('  Headscale configured.');
    }

    private function maybeJoinHeadscale(array $params, SshInstaller $ssh, callable $cb): ?string
    {
        if (empty($params['install_headscale'])) {
            return null;
        }

        $headscaleUrl = Setting::get('headscale.url');
        if (!$headscaleUrl) {
            return null;
        }

        try {
            $preAuthKey  = (new HeadscaleService())->createPreAuthKey('lintune');
            $tailscaleIp = $ssh->joinHeadscaleNetwork($headscaleUrl, $preAuthKey);

            if ($tailscaleIp) {
                $cb("  Joined VPN mesh — Tailscale IP: {$tailscaleIp}");
                return $tailscaleIp;
            }
        } catch (\Throwable $e) {
            $cb('  Warning: failed to join VPN mesh — ' . $e->getMessage());
        }

        return null;
    }

    private function maybeSetupBackup(array $params, SshInstaller $ssh, callable $cb): void
    {
        if (empty($params['install_backup'])) {
            return;
        }

        $backup    = new BackupService();
        $publicKey = $backup->getPublicKey() ?: $backup->generateKeyPair();

        try {
            $ssh->setupBackupUser($publicKey);
        } catch (\Throwable $e) {
            $cb('  Warning: backup user setup failed — ' . $e->getMessage());
        }
    }

    private function addKumaMonitor(string $name, string $url, bool $ignoreTls = false): void
    {
        try {
            (new \App\Services\KumaService())->addMonitor($name, $url, $ignoreTls);
        } catch (\Throwable) {
            // Non-fatal — monitoring is secondary to the actual install
        }
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
        $stages = [];

        if (!empty($params['install_headscale'])) {
            $stages[] = 'headscale';
        }

        $stages[] = 'keycloak';

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
        return [
            'headscale' => 'VPN Mesh',
            'keycloak'  => 'Keycloak',
            'mailcow'   => 'Mailcow',
            'nextcloud' => 'Nextcloud',
        ];
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
                'realm'       => $brokerRealm,
                'displayName' => $publicBase,
                'enabled'     => true,
                'loginTheme'  => 'lintune',
            ]);
            if ($brokerRes->failed()) {
                throw new \RuntimeException('Failed to create broker realm: ' . $brokerRes->body());
            }
            $msg   = "→ Created broker realm '{$brokerRealm}'.";
            $log[] = $msg;
            $emit('log', ['line' => $msg]);
        }

        // Enable Organizations + configure Home IdP Discovery browser flow on the broker realm.
        // Non-fatal: log a warning if this fails (requires Keycloak 25+).
        try {
            $this->configureBrokerHomeIdpDiscovery($internalBase, $token, $brokerRealm);
            $msg = '→ Broker realm configured for Home IdP Discovery.';
        } catch (\Throwable $e) {
            $msg = '→ NOTE: Home IdP Discovery setup skipped: ' . $e->getMessage();
        }
        $log[] = $msg;
        $emit('log', ['line' => $msg]);

        // Apply Lintune login theme to master realm. Broker realm already has it
        // from the creation POST above, but apply to both for safety/idempotency.
        // KC merges partial PUT bodies — sending only loginTheme is sufficient.
        $themeOk = true;
        foreach (['master', $brokerRealm] as $realmToTheme) {
            $res = \Http::withToken($token)->put("{$internalBase}/admin/realms/{$realmToTheme}", [
                'loginTheme' => 'lintune',
            ]);
            if ($res->failed()) {
                $themeOk = false;
                $msg = "→ NOTE: Could not apply theme to {$realmToTheme}: " . $res->body();
                $log[] = $msg;
                $emit('log', ['line' => $msg]);
            }
        }
        if ($themeOk) {
            $msg = '→ Lintune login theme applied to master and broker realms.';
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

    /**
     * Enable Keycloak Organizations on the broker realm and create a custom browser
     * authentication flow that uses the built-in `organization` authenticator for
     * automatic email-domain → IdP routing (Home IdP Discovery).
     *
     * Flow: Cookie (ALTERNATIVE) → IdP Redirector (ALTERNATIVE) → organization (ALTERNATIVE)
     *
     * The `organization` authenticator shows an email form, extracts the domain, finds the
     * matching Organization, and redirects to its linked IdP — no manual IdP selection.
     */
    private function configureBrokerHomeIdpDiscovery(string $base, string $token, string $brokerRealm): void
    {
        // Enable Organizations feature on the broker realm
        $res = \Http::withToken($token)->put("{$base}/admin/realms/{$brokerRealm}", [
            'organizationsEnabled' => true,
        ]);
        if ($res->failed()) {
            throw new \RuntimeException("Failed to enable Organizations: " . $res->body());
        }

        // Create (or replace) a custom browser flow for the broker realm
        $flowAlias  = 'broker-home-idp-discovery';
        $existFlows = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/authentication/flows")->json();
        $existFlow  = collect((array) $existFlows)->firstWhere('alias', $flowAlias);
        if ($existFlow) {
            \Http::withToken($token)->delete("{$base}/admin/realms/{$brokerRealm}/authentication/flows/{$existFlow['id']}");
        }

        $flowRes = \Http::withToken($token)->post("{$base}/admin/realms/{$brokerRealm}/authentication/flows", [
            'alias'      => $flowAlias,
            'providerId' => 'basic-flow',
            'topLevel'   => true,
            'builtIn'    => false,
        ]);
        if ($flowRes->failed()) {
            throw new \RuntimeException("Failed to create auth flow: " . $flowRes->body());
        }

        // Add Cookie, Identity Provider Redirector, and Organization authenticators
        foreach (['auth-cookie', 'identity-provider-redirector', 'organization'] as $provider) {
            $addRes = \Http::withToken($token)->post(
                "{$base}/admin/realms/{$brokerRealm}/authentication/flows/{$flowAlias}/executions/execution",
                ['provider' => $provider]
            );
            if ($addRes->failed()) {
                throw new \RuntimeException("Failed to add '{$provider}' executor: " . $addRes->body());
            }
        }

        // Set all executions to ALTERNATIVE so each step is tried in order, not required
        $executions = \Http::withToken($token)
            ->get("{$base}/admin/realms/{$brokerRealm}/authentication/flows/{$flowAlias}/executions")
            ->json();
        foreach ((array) $executions as $exec) {
            \Http::withToken($token)->put(
                "{$base}/admin/realms/{$brokerRealm}/authentication/flows/{$flowAlias}/executions",
                array_merge($exec, ['requirement' => 'ALTERNATIVE'])
            );
        }

        // KC 26.6+ added requiresUserMembership config to the organization authenticator.
        // Default is true — blocks non-members (new users) instead of redirecting them to
        // their IdP, causing "Your email domain matches an organization but you don't have
        // an account yet." Set to false so home IdP discovery redirects new users.
        $executions = \Http::withToken($token)
            ->get("{$base}/admin/realms/{$brokerRealm}/authentication/flows/{$flowAlias}/executions")
            ->json();
        $orgExec = collect((array) $executions)->firstWhere('providerId', 'organization');
        if ($orgExec && empty($orgExec['authenticationConfig'])) {
            \Http::withToken($token)->post(
                "{$base}/admin/realms/{$brokerRealm}/authentication/executions/{$orgExec['id']}/config",
                ['alias' => 'lintune-org-redirect', 'config' => ['requiresUserMembership' => 'false']]
            );
        }

        // Set first-broker-login Review Profile to OFF.
        // The broker realm is a pure pass-through — profile data comes from the tenant realm
        // via OIDC claims. Users should never be asked to manually fill in their profile here.
        $fbExecs = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/authentication/flows/first%20broker%20login/executions")->json();
        $rpExec  = collect((array) $fbExecs)->firstWhere('providerId', 'idp-review-profile');
        if ($rpExec) {
            $rpConfigId = $rpExec['authenticationConfig'] ?? null;
            if ($rpConfigId) {
                $rpCfg = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/authentication/config/{$rpConfigId}")->json();
                $rpCfg['config']['update.profile.on.first.login'] = 'off';
                \Http::withToken($token)->put("{$base}/admin/realms/{$brokerRealm}/authentication/config/{$rpConfigId}", $rpCfg);
            }
        }

        // Bind the broker realm's browser login to this flow
        \Http::withToken($token)->put("{$base}/admin/realms/{$brokerRealm}", [
            'browserFlow' => $flowAlias,
        ]);
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
