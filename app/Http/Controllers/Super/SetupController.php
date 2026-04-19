<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SetupController extends Controller
{
    public function show()
    {
        return view('super.setup');
    }

    public function run(Request $request)
    {
        $request->validate([
            'username' => 'required',
            'password' => 'required',
        ]);

        $base = config('keycloak.base_url');
        $appUrl = rtrim(config('app.url'), '/');

        // 1. Get token via password grant (one-time bootstrap)
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

        // 2. Create lintune-admin client in master realm
        $clientRes = \Http::withToken($token)->post("{$base}/admin/realms/master/clients", [
            'clientId'                     => 'lintune-admin',
            'name'                         => 'Lintune Admin',
            'description'                  => 'Automatically created by Lintune Admin setup. Used for super admin authentication and Keycloak Admin API access.',
            'enabled'                      => true,
            'publicClient'                 => false,
            'standardFlowEnabled'          => true,
            'directAccessGrantsEnabled'    => false,
            'serviceAccountsEnabled'       => true,
            'redirectUris'                 => ["{$appUrl}/super/auth/callback"],
            'webOrigins'                   => [$appUrl],
            'attributes'                   => [
                'post.logout.redirect.uris' => "{$appUrl}/super/login",
            ],
        ]);

        if ($clientRes->failed()) {
            return back()->withErrors(['auth' => 'Failed to create Keycloak client: ' . $clientRes->body()]);
        }

        $clientUuid = basename($clientRes->header('Location'));

        // 3. Fetch client secret
        $secretRes = \Http::withToken($token)->get("{$base}/admin/realms/master/clients/{$clientUuid}/client-secret");
        if ($secretRes->failed()) {
            return back()->withErrors(['auth' => 'Failed to fetch client secret.']);
        }
        $clientSecret = $secretRes->json()['value'];

        // 4. Assign admin role to the service account so client credentials can use Admin API
        $serviceAccountRes = \Http::withToken($token)->get("{$base}/admin/realms/master/clients/{$clientUuid}/service-account-user");
        if ($serviceAccountRes->failed()) {
            return back()->withErrors(['auth' => 'Failed to fetch service account user.']);
        }

        $serviceAccountId = $serviceAccountRes->json()['id'];

        $adminRoleRes = \Http::withToken($token)->get("{$base}/admin/realms/master/roles/admin");
        if ($adminRoleRes->failed()) {
            return back()->withErrors(['auth' => 'Failed to fetch admin role.']);
        }

        $role = $adminRoleRes->json();

        $roleAssignRes = \Http::withToken($token)
            ->withBody(json_encode([$role]), 'application/json')
            ->post("{$base}/admin/realms/master/users/{$serviceAccountId}/role-mappings/realm");

        if ($roleAssignRes->failed()) {
            return back()->withErrors(['auth' => 'Failed to assign admin role to service account: ' . $roleAssignRes->body()]);
        }

        // 5. Create broker realm with random name
        $brokerRealm = 'broker-' . Str::lower(Str::random(8));
        $brokerRes = \Http::withToken($token)->post("{$base}/admin/realms", [
            'realm'   => $brokerRealm,
            'enabled' => true,
        ]);

        if ($brokerRes->failed()) {
            return back()->withErrors(['auth' => 'Failed to create broker realm: ' . $brokerRes->body()]);
        }

        // 6. Write to .env and lock setup
        $this->writeEnv([
            'KEYCLOAK_ADMIN_CLIENT_SECRET' => $clientSecret,
            'KEYCLOAK_BROKER_REALM'        => $brokerRealm,
            'SETUP_COMPLETE'               => 'true',
        ]);

        // 7. Clear config cache so new values are picked up
        \Artisan::call('config:clear');

        return redirect()->route('super.login');
    }

    private function writeEnv(array $values): void
    {
        $path = base_path('.env');
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
