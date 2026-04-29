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
        if (config('setup.complete')) {
            abort(404);
        }
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
        $type = $request->query('type', session('install_server_type', 'single'));
        session(['install_server_type' => $type]);
        return view('install.configure', compact('type'));
    }

    // ── Guided: begin (validate, cache params, redirect to progress) ──────────

    public function run(Request $request)
    {
        $type = $request->input('server_type', 'single');

        if ($type === 'single') {
            $request->validate([
                'ssh_host' => 'required|string',
                'ssh_user' => 'required|string',
                'ssh_pass' => 'required|string',
                'kc_port'  => 'required|integer|min:1|max:65535',
            ]);
        } else {
            $request->validate([
                'kc_host' => 'required|string',
                'kc_user' => 'required|string',
                'kc_pass' => 'required|string',
                'kc_port' => 'required|integer|min:1|max:65535',
            ]);
        }

        $key = Str::uuid()->toString();
        Cache::put("install_params:{$key}", $request->except('_token'), now()->addHour());

        return redirect()->route('install.progress', $key);
    }

    // ── Guided: progress page ─────────────────────────────────────────────────

    public function progress(string $key)
    {
        if (!Cache::has("install_params:{$key}")) {
            return redirect()->route('install.welcome');
        }
        return view('install.progress', compact('key'));
    }

    // ── Guided: SSE stream ────────────────────────────────────────────────────

    public function stream(string $key): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $params = Cache::get("install_params:{$key}");
        if (!$params) {
            abort(404);
        }
        Cache::forget("install_params:{$key}");

        return response()->stream(function () use ($key, $params) {
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

            $log             = [];
            $type            = $params['server_type'] ?? 'single';
            $kcAdminPassword = Str::random(24);

            $cb = function (string $line) use (&$log, $emit) {
                $log[] = $line;
                $emit('log', ['line' => $line]);
            };

            try {
                if ($type === 'single') {
                    // "__local__" → host machine running the Docker container
                    $kcHost     = ($params['ssh_host'] ?? '') === '__local__'
                        ? 'host.docker.internal'
                        : ($params['ssh_host'] ?? '');
                    $kcPort     = (int) ($params['kc_port'] ?? 8080);
                    $kcHostname = !empty($params['kc_public_url'])
                        ? parse_url(rtrim($params['kc_public_url'], '/'), PHP_URL_HOST)
                        : null;

                    $ssh = new SshInstaller($kcHost, $params['ssh_user'], $params['ssh_pass']);
                    $ssh->setOutputCallback($cb);
                    $ssh->ensureDocker();
                    $ssh->installKeycloak($kcAdminPassword, $kcPort, $kcHostname);

                    if (!empty($params['install_mailcow']) && !empty($params['mailcow_hostname'])) {
                        $ssh->installMailcow($params['mailcow_hostname'], $params['mailcow_tz'] ?? 'UTC');
                        Setting::set('mailcow.url', "https://{$params['mailcow_hostname']}");
                    }

                    if (!empty($params['install_nextcloud'])) {
                        $ssh->installNextcloud();
                        $ncUrl = !empty($params['nc_url']) ? rtrim($params['nc_url'], '/') : "http://{$kcHost}:11000";
                        Setting::set('nextcloud.url', $ncUrl);
                    }
                } else {
                    $kcHost     = $params['kc_host'] ?? '';
                    $kcPort     = (int) ($params['kc_port'] ?? 8080);
                    $kcHostname = !empty($params['kc_public_url'])
                        ? parse_url(rtrim($params['kc_public_url'], '/'), PHP_URL_HOST)
                        : null;

                    $ssh = new SshInstaller($kcHost, $params['kc_user'], $params['kc_pass']);
                    $ssh->setOutputCallback($cb);
                    $ssh->ensureDocker();
                    $ssh->installKeycloak($kcAdminPassword, $kcPort, $kcHostname);

                    if (!empty($params['install_mailcow']) && !empty($params['mc_host']) && !empty($params['mailcow_hostname'])) {
                        $mcSsh = new SshInstaller($params['mc_host'], $params['mc_user'], $params['mc_pass']);
                        $mcSsh->setOutputCallback($cb);
                        $mcSsh->ensureDocker();
                        $mcSsh->installMailcow($params['mailcow_hostname'], $params['mailcow_tz'] ?? 'UTC');
                        Setting::set('mailcow.url', "https://{$params['mailcow_hostname']}");
                    }

                    if (!empty($params['install_nextcloud']) && !empty($params['nc_host'])) {
                        $ncSsh = new SshInstaller($params['nc_host'], $params['nc_user'], $params['nc_pass']);
                        $ncSsh->setOutputCallback($cb);
                        $ncSsh->ensureDocker();
                        $ncSsh->installNextcloud();
                        $ncUrl = !empty($params['nc_url']) ? rtrim($params['nc_url'], '/') : "http://{$params['nc_host']}:11000";
                        Setting::set('nextcloud.url', $ncUrl);
                    }
                }
            } catch (\Throwable $e) {
                $emit('error', ['message' => 'Installation failed: ' . $e->getMessage()]);
                return;
            }

            // Internal URL for health checks (always direct IP:port)
            $kcInternalUrl = "http://{$kcHost}:{$kcPort}";
            // Public URL saved to settings
            $keycloakUrl   = !empty($params['kc_public_url'])
                ? rtrim($params['kc_public_url'], '/')
                : $kcInternalUrl;

            // Wait for Keycloak (up to 90 s)
            $emit('log', ['line' => '→ Waiting for Keycloak to become ready...']);
            $log[] = '→ Waiting for Keycloak to become ready...';
            $ready = false;
            $base  = rtrim($kcInternalUrl, '/');

            for ($i = 0; $i < 45; $i++) {
                try {
                    if (\Http::timeout(3)->get("{$base}/health/ready")->successful()) {
                        $ready = true;
                        break;
                    }
                } catch (\Throwable) {}
                $emit('log', ['line' => "  Attempt " . ($i + 1) . " / 45..."]);
                sleep(2);
            }

            if (!$ready) {
                $emit('error', ['message' => 'Keycloak installed but did not become ready in 90 s. Check the server and try manual setup.']);
                return;
            }

            $emit('log', ['line' => '  Keycloak is ready.']);
            $log[] = '  Keycloak is ready.';

            try {
                $this->setupKeycloak($base, $keycloakUrl, $kcAdminPassword, $log, $emit);
            } catch (\Throwable $e) {
                $emit('error', ['message' => 'Keycloak configuration failed: ' . $e->getMessage()]);
                return;
            }

            Cache::put("install_result:{$key}", ['log' => $log, 'kcUrl' => $keycloakUrl], now()->minutes(10));
            $emit('done', ['redirect' => route('install.done') . '?key=' . $key]);

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
            $result = Cache::pull("install_result:{$key}");
            $log    = $result['log'] ?? [];
            $kcUrl  = $result['kcUrl'] ?? '';
        } else {
            $log   = session('install_log', []);
            $kcUrl = session('install_kc_url', '');
        }

        return view('install.done', compact('log', 'kcUrl'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function setupKeycloak(string $internalBase, string $publicBase, string $adminPassword, array &$log, callable $emit): void
    {
        $tokenRes = \Http::asForm()->post("{$internalBase}/realms/master/protocol/openid-connect/token", [
            'grant_type' => 'password',
            'client_id'  => 'admin-cli',
            'username'   => 'admin',
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
            'SETUP_COMPLETE'               => 'true',
        ]);

        Setting::set('keycloak.url', $publicBase);
        Setting::set('keycloak.broker_realm', $brokerRealm);

        \Artisan::call('config:clear');
        $msg   = '→ Configuration saved.';
        $log[] = $msg;
        $emit('log', ['line' => $msg]);
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
