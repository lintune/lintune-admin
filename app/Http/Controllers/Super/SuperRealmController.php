<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\DomainRealmMap;
use Illuminate\Http\Request;

class SuperRealmController extends Controller
{
    private function token(): string
    {
        return session('super_access_token');
    }

    private function baseUrl(): string
    {
        return config('keycloak.base_url');
    }

    public function index()
    {
        $response = \Http::withToken($this->token())
            ->get("{$this->baseUrl()}/admin/realms");

        $realms = $response->successful() ? $response->json() : [];

        return view('super.realms', compact('realms'));
    }

    public function create()
    {
        return view('super.create-realm');
    }

    public function store(Request $request)
    {
        $request->validate([
            'realm'          => ['required', 'regex:/^[a-zA-Z0-9_.\-]+$/'],
            'admin_email'    => 'required|email',
            'admin_password' => 'required|min:8',
            'admin_firstname'=> 'required',
            'admin_lastname' => 'required',
        ]);

        $realm   = $request->realm;
        $base    = $this->baseUrl();
        $token   = $this->token();
        $appUrl  = rtrim(config('app.url'), '/');

        // 1. Create realm
        $realmRes = \Http::withToken($token)->post("{$base}/admin/realms", [
            'realm'   => $realm,
            'enabled' => true,
        ]);

        if ($realmRes->failed()) {
            return back()->withErrors(['realm' => 'Failed to create realm: ' . $realmRes->body()]);
        }

        // 2. Create client
        $clientRes = \Http::withToken($token)->post("{$base}/admin/realms/{$realm}/clients", [
            'clientId'                     => config('keycloak.client_id'),
            'enabled'                      => true,
            'publicClient'                 => true,
            'standardFlowEnabled'          => true,
            'directAccessGrantsEnabled'    => false,
            'redirectUris'                 => ["{$appUrl}/auth/callback"],
            'webOrigins'                   => [$appUrl],
            'attributes'                   => [
                'pkce.code.challenge.method' => 'S256',
            ],
        ]);

        if ($clientRes->failed()) {
            return back()->withErrors(['realm' => 'Realm created but client setup failed: ' . $clientRes->body()]);
        }

        // 3. Create first admin user
        $userRes = \Http::withToken($token)->post("{$base}/admin/realms/{$realm}/users", [
            'username'      => $request->admin_email,
            'email'         => $request->admin_email,
            'firstName'     => $request->admin_firstname,
            'lastName'      => $request->admin_lastname,
            'enabled'       => true,
            'emailVerified' => true,
            'credentials'   => [[
                'type'      => 'password',
                'value'     => $request->admin_password,
                'temporary' => false,
            ]],
        ]);

        if ($userRes->failed()) {
            return back()->withErrors(['realm' => 'Realm created but user creation failed: ' . $userRes->body()]);
        }

        // 4. Assign realm-admin role to the user
        $userId = basename($userRes->header('Location'));

        $rolesRes = \Http::withToken($token)
            ->get("{$base}/admin/realms/{$realm}/clients");

        $clients    = $rolesRes->json();
        $mgmtClient = collect($clients)->firstWhere('clientId', 'realm-management');

        if ($mgmtClient) {
            $mgmtId    = $mgmtClient['id'];
            $rolesData = \Http::withToken($token)
                ->get("{$base}/admin/realms/{$realm}/clients/{$mgmtId}/roles")
                ->json();

            $adminRole = collect($rolesData)->firstWhere('name', 'realm-admin');

            if ($adminRole) {
                \Http::withToken($token)->post(
                    "{$base}/admin/realms/{$realm}/users/{$userId}/role-mappings/clients/{$mgmtId}",
                    [$adminRole]
                );
            }
        }

        // 5. Insert domain mapping
        DomainRealmMap::updateOrCreate(
            ['domain' => $realm],
            ['realm'  => $realm]
        );

        return redirect()->route('super.realms')->with('success', "Realm '{$realm}' created successfully.");
    }
}
