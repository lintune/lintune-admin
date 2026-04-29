<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\SshInstaller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InstallController extends Controller
{
    public function __construct()
    {
        if (config('setup.complete')) {
            abort(redirect()->route('super.login'));
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

    // ── Guided: run installation ──────────────────────────────────────────────

    public function run(Request $request)
    {
        $type = $request->input('server_type', 'single');

        if ($type === 'single') {
            $request->validate([
                'ssh_host'     => 'required|string',
                'ssh_user'     => 'required|string',
                'ssh_pass'     => 'required|string',
                'kc_port'      => 'required|integer|min:1|max:65535',
            ]);
        } else {
            $request->validate([
                'kc_host' => 'required|string',
                'kc_user' => 'required|string',
                'kc_pass' => 'required|string',
                'kc_port' => 'required|integer|min:1|max:65535',
            ]);
        }

        set_time_limit(0);

        $kcAdminPassword = Str::random(24);
        $log             = [];

        try {
            if ($type === 'single') {
                $kcHost = $request->ssh_host === '__local__' ? '127.0.0.1' : $request->ssh_host;
                $kcPort = (int) $request->kc_port;
                $ssh    = new SshInstaller($kcHost, $request->ssh_user, $request->ssh_pass);
                $ssh->ensureDocker();
                $ssh->installKeycloak($kcAdminPassword);

                // Optional Mailcow on same server
                if ($request->boolean('install_mailcow') && $request->filled('mailcow_hostname')) {
                    $ssh->installMailcow($request->mailcow_hostname);
                }
                // Optional Nextcloud on same server
                if ($request->boolean('install_nextcloud')) {
                    $ssh->installNextcloud();
                }
                $log = $ssh->getLog();
            } else {
                $kcHost = $request->kc_host;
                $kcPort = (int) $request->kc_port;
                $ssh    = new SshInstaller($kcHost, $request->kc_user, $request->kc_pass);
                $ssh->ensureDocker();
                $ssh->installKeycloak($kcAdminPassword);
                $log = $ssh->getLog();

                // Optional Mailcow on separate server
                if ($request->boolean('install_mailcow') && $request->filled('mc_host', 'mailcow_hostname')) {
                    $mcSsh = new SshInstaller($request->mc_host, $request->mc_user, $request->mc_pass);
                    $mcSsh->ensureDocker();
                    $mcSsh->installMailcow($request->mailcow_hostname);
                    $log = array_merge($log, $mcSsh->getLog());
                }

                // Optional Nextcloud on separate server
                if ($request->boolean('install_nextcloud') && $request->filled('nc_host')) {
                    $ncSsh = new SshInstaller($request->nc_host, $request->nc_user, $request->nc_pass);
                    $ncSsh->ensureDocker();
                    $ncSsh->installNextcloud();
                    $log = array_merge($log, $ncSsh->getLog());
                }
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['ssh' => 'Connection or installation failed: ' . $e->getMessage()])
                         ->withInput($request->except('ssh_pass', 'kc_pass', 'mc_pass', 'nc_pass'));
        }

        $keycloakUrl = "http://{$kcHost}:{$kcPort}";

        // Wait for Keycloak to be ready (up to 90 seconds)
        $log[] = '→ Waiting for Keycloak to be ready...';
        $ready = false;
        $base  = rtrim($keycloakUrl, '/');
        for ($i = 0; $i < 45; $i++) {
            try {
                $resp = \Http::timeout(3)->get("{$base}/health/ready");
                if ($resp->successful()) { $ready = true; break; }
            } catch (\Throwable) {}
            sleep(2);
        }

        if (!$ready) {
            return back()->withErrors(['ssh' => 'Keycloak installed but did not become ready in time. Check the server and try manual setup.']);
        }
        $log[] = '  Keycloak is ready.';

        // Run Keycloak setup (create OIDC client + service account)
        try {
            $this->setupKeycloak($base, $kcAdminPassword, $log);
        } catch (\Throwable $e) {
            return back()->withErrors(['ssh' => 'Keycloak configuration failed: ' . $e->getMessage()]);
        }

        session(['install_log' => $log, 'install_kc_url' => $keycloakUrl]);
        return redirect()->route('install.done');
    }

    // ── Manual setup ──────────────────────────────────────────────────────────

    public function manual()
    {
        $repairMode = !config('setup.complete')
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

        $base   = rtrim($request->keycloak_url, '/');
        $appUrl = rtrim(config('app.url'), '/');

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

        try {
            $this->setupKeycloakWithToken($base, $token, $log);
        } catch (\Throwable $e) {
            return back()->withErrors(['auth' => 'Keycloak setup failed: ' . $e->getMessage()])->withInput($request->except('password'));
        }

        session(['install_log' => $log, 'install_kc_url' => $base]);
        return redirect()->route('install.done');
    }

    // ── Done ──────────────────────────────────────────────────────────────────

    public function done()
    {
        $log   = session('install_log', []);
        $kcUrl = session('install_kc_url', '');
        return view('install.done', compact('log', 'kcUrl'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function setupKeycloak(string $base, string $adminPassword, array &$log): void
    {
        $tokenRes = \Http::asForm()->post("{$base}/realms/master/protocol/openid-connect/token", [
            'grant_type' => 'password',
            'client_id'  => 'admin-cli',
            'username'   => 'admin',
            'password'   => $adminPassword,
        ]);

        if ($tokenRes->failed()) {
            throw new \RuntimeException('Failed to get admin token from Keycloak: ' . $tokenRes->body());
        }

        $token = $tokenRes->json()['access_token'];
        $log[] = '→ Obtained Keycloak admin token.';

        $this->setupKeycloakWithToken($base, $token, $log);
    }

    private function setupKeycloakWithToken(string $base, string $token, array &$log): void
    {
        $appUrl = rtrim(config('app.url'), '/');

        // Create (or recreate) lintune-admin OIDC client
        $existing = \Http::withToken($token)->get("{$base}/admin/realms/master/clients", ['clientId' => 'lintune-admin'])->json();
        $existingClient = collect($existing)->firstWhere('clientId', 'lintune-admin');
        if ($existingClient) {
            \Http::withToken($token)->delete("{$base}/admin/realms/master/clients/{$existingClient['id']}");
        }

        $clientRes = \Http::withToken($token)->post("{$base}/admin/realms/master/clients", [
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

        $clientUuid = basename($clientRes->header('Location'));
        $secretRes  = \Http::withToken($token)->get("{$base}/admin/realms/master/clients/{$clientUuid}/client-secret");
        if ($secretRes->failed()) {
            throw new \RuntimeException('Failed to fetch client secret.');
        }
        $clientSecret = $secretRes->json()['value'];
        $log[]        = '→ Created lintune-admin OIDC client.';

        // Broker realm
        $brokerRealm = config('keycloak.broker_realm');
        if (!$brokerRealm) {
            $brokerRealm = 'broker-' . Str::lower(Str::random(8));
            $brokerRes   = \Http::withToken($token)->post("{$base}/admin/realms", [
                'realm'   => $brokerRealm,
                'enabled' => true,
            ]);
            if ($brokerRes->failed()) {
                throw new \RuntimeException('Failed to create broker realm: ' . $brokerRes->body());
            }
            $log[] = "→ Created broker realm '{$brokerRealm}'.";
        }

        // Create lintune-service account
        $serviceUsername = 'lintune-service';
        $servicePassword = Str::password(32, symbols: false);

        $existing2 = \Http::withToken($token)->get("{$base}/admin/realms/master/users", ['username' => $serviceUsername, 'exact' => true]);
        if ($existing2->successful() && !empty($existing2->json())) {
            \Http::withToken($token)->delete("{$base}/admin/realms/master/users/{$existing2->json()[0]['id']}");
        }

        $userRes = \Http::withToken($token)->post("{$base}/admin/realms/master/users", [
            'username'      => $serviceUsername,
            'enabled'       => true,
            'emailVerified' => true,
            'credentials'   => [['type' => 'password', 'value' => $servicePassword, 'temporary' => false]],
        ]);

        if ($userRes->failed()) {
            throw new \RuntimeException('Failed to create service user: ' . $userRes->body());
        }

        $serviceUserId = basename($userRes->header('Location'));
        $adminRole     = \Http::withToken($token)->get("{$base}/admin/realms/master/roles/admin")->json();

        \Http::withToken($token)
            ->withBody(json_encode([[
                'id'          => $adminRole['id'],
                'name'        => $adminRole['name'],
                'composite'   => $adminRole['composite'],
                'clientRole'  => $adminRole['clientRole'],
                'containerId' => $adminRole['containerId'],
            ]]), 'application/json')
            ->post("{$base}/admin/realms/master/users/{$serviceUserId}/role-mappings/realm");

        $log[] = '→ Created lintune-service account.';

        // Save everything to .env + settings
        $this->writeEnv([
            'KEYCLOAK_BASE_URL'            => $base,
            'KEYCLOAK_ADMIN_CLIENT_SECRET' => $clientSecret,
            'KEYCLOAK_ADMIN_USER'          => $serviceUsername,
            'KEYCLOAK_ADMIN_PASSWORD'      => base64_encode(encrypt($servicePassword)),
            'KEYCLOAK_BROKER_REALM'        => $brokerRealm,
            'SETUP_COMPLETE'               => 'true',
        ]);

        Setting::set('keycloak.url', $base);
        Setting::set('keycloak.broker_realm', $brokerRealm);

        \Artisan::call('config:clear');
        $log[] = '→ Configuration saved.';
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
