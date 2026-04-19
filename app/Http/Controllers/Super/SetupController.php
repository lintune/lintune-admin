<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SetupController extends Controller
{
    public function show()
    {
        $repairMode = !config('setup.complete')
            && config('keycloak.admin_user')
            && config('keycloak.admin_password');

        $serviceAccountOk = false;
        if ($repairMode) {
            try {
                $base = config('keycloak.base_url');
                $res  = \Http::asForm()->post("{$base}/realms/master/protocol/openid-connect/token", [
                    'grant_type' => 'password',
                    'client_id'  => 'admin-cli',
                    'username'   => config('keycloak.admin_user'),
                    'password'   => decrypt(base64_decode(config('keycloak.admin_password'))),
                ]);
                $serviceAccountOk = $res->successful() && !empty($res->json()['access_token']);
            } catch (\Throwable) {
                $serviceAccountOk = false;
            }
        }

        return view('super.setup', compact('repairMode', 'serviceAccountOk'));
    }

    public function run(Request $request)
    {
        $request->validate([
            'username' => 'required',
            'password' => 'required',
        ]);

        $base   = config('keycloak.base_url');
        $appUrl = rtrim(config('app.url'), '/');

        // 1. Verify provided admin credentials
        $tokenRes = \Http::asForm()->post("{$base}/realms/master/protocol/openid-connect/token", [
            'grant_type' => 'password',
            'client_id'  => 'admin-cli',
            'username'   => $request->username,
            'password'   => $request->password,
        ]);

        if ($tokenRes->failed()) {
            return back()->withErrors(['auth' => 'Invalid Keycloak credentials.']);
        }

        $token = $tokenRes->json()['access_token'];

        $isRepair = $request->boolean('repair');

        if (!$isRepair) {
            // 2. Create lintune-admin OIDC client in master realm (fresh setup only)
            $clientRes = \Http::withToken($token)->post("{$base}/admin/realms/master/clients", [
                'clientId'                  => 'lintune-admin',
                'name'                      => 'Lintune Admin',
                'description'               => 'Automatically created by Lintune Admin setup. Used for super admin authentication.',
                'enabled'                   => true,
                'publicClient'              => false,
                'standardFlowEnabled'       => true,
                'directAccessGrantsEnabled' => false,
                'serviceAccountsEnabled'    => false,
                'redirectUris'              => ["{$appUrl}/super/auth/callback"],
                'webOrigins'                => [$appUrl],
                'attributes'                => [
                    'post.logout.redirect.uris' => "{$appUrl}/super/login",
                ],
            ]);

            if ($clientRes->failed()) {
                return back()->withErrors(['auth' => 'Failed to create Keycloak client: ' . $clientRes->body()]);
            }

            $clientUuid = basename($clientRes->header('Location'));

            $secretRes = \Http::withToken($token)->get("{$base}/admin/realms/master/clients/{$clientUuid}/client-secret");
            if ($secretRes->failed()) {
                return back()->withErrors(['auth' => 'Failed to fetch client secret.']);
            }
            $clientSecret = $secretRes->json()['value'];

            // 3. Create broker realm with random name
            $brokerRealm = 'broker-' . Str::lower(Str::random(8));
            $brokerRes   = \Http::withToken($token)->post("{$base}/admin/realms", [
                'realm'   => $brokerRealm,
                'enabled' => true,
            ]);

            if ($brokerRes->failed()) {
                return back()->withErrors(['auth' => 'Failed to create broker realm: ' . $brokerRes->body()]);
            }
        }

        // 4. Create (or recreate) the lintune-service user with a random password
        $serviceUsername = 'lintune-service';
        $servicePassword = Str::password(32, symbols: false);

        // Delete existing service user if present (repair mode)
        $existingRes = \Http::withToken($token)->get("{$base}/admin/realms/master/users", ['username' => $serviceUsername, 'exact' => true]);
        if ($existingRes->successful() && !empty($existingRes->json())) {
            $existingId = $existingRes->json()[0]['id'];
            \Http::withToken($token)->delete("{$base}/admin/realms/master/users/{$existingId}");
        }

        $userRes = \Http::withToken($token)->post("{$base}/admin/realms/master/users", [
            'username'      => $serviceUsername,
            'enabled'       => true,
            'emailVerified' => true,
            'credentials'   => [[
                'type'      => 'password',
                'value'     => $servicePassword,
                'temporary' => false,
            ]],
        ]);

        if ($userRes->failed()) {
            return back()->withErrors(['auth' => 'Failed to create service user: ' . $userRes->body()]);
        }

        // 5. Assign admin role to service user
        $serviceUserId  = basename($userRes->header('Location'));
        $adminRoleRes   = \Http::withToken($token)->get("{$base}/admin/realms/master/roles/admin");

        if ($adminRoleRes->failed()) {
            return back()->withErrors(['auth' => 'Failed to fetch admin role.']);
        }

        $role = $adminRoleRes->json();
        $rolePayload = json_encode([[
            'id'          => $role['id'],
            'name'        => $role['name'],
            'composite'   => $role['composite'],
            'clientRole'  => $role['clientRole'],
            'containerId' => $role['containerId'],
        ]]);

        $roleAssignRes = \Http::withToken($token)
            ->withBody($rolePayload, 'application/json')
            ->post("{$base}/admin/realms/master/users/{$serviceUserId}/role-mappings/realm");

        if ($roleAssignRes->failed()) {
            return back()->withErrors(['auth' => 'Failed to assign admin role to service user: ' . $roleAssignRes->body()]);
        }

        // 6. Write to .env
        $envValues = [
            'KEYCLOAK_ADMIN_USER'     => $serviceUsername,
            'KEYCLOAK_ADMIN_PASSWORD' => base64_encode(encrypt($servicePassword)),
            'SETUP_COMPLETE'          => 'true',
        ];

        if (!$isRepair) {
            $envValues['KEYCLOAK_ADMIN_CLIENT_SECRET'] = $clientSecret;
            $envValues['KEYCLOAK_BROKER_REALM']        = $brokerRealm;
        }

        $this->writeEnv($envValues);

        // 7. Clear config cache
        \Artisan::call('config:clear');

        return redirect()->route('super.login');
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
