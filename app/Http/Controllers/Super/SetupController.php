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
            'clientId'                  => 'lintune-admin',
            'enabled'                   => true,
            'publicClient'              => false,
            'standardFlowEnabled'       => true,
            'directAccessGrantsEnabled' => false,
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

        // 3. Fetch client secret
        $secretRes = \Http::withToken($token)->get("{$base}/admin/realms/master/clients/{$clientUuid}/client-secret");
        if ($secretRes->failed()) {
            return back()->withErrors(['auth' => 'Failed to fetch client secret.']);
        }
        $clientSecret = $secretRes->json()['value'];

        // 4. Create broker realm with random name
        $brokerRealm = 'broker-' . Str::lower(Str::random(8));
        $brokerRes = \Http::withToken($token)->post("{$base}/admin/realms", [
            'realm'   => $brokerRealm,
            'enabled' => true,
        ]);

        if ($brokerRes->failed()) {
            return back()->withErrors(['auth' => 'Failed to create broker realm: ' . $brokerRes->body()]);
        }

        // 5. Write to .env and lock setup
        $this->writeEnv([
            'KEYCLOAK_ADMIN_CLIENT_SECRET' => $clientSecret,
            'KEYCLOAK_BROKER_REALM'        => $brokerRealm,
            'SETUP_COMPLETE'               => 'true',
        ]);

        // 6. Clear config cache so new values are picked up
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
