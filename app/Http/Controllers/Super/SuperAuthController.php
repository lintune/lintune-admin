<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SuperAuthController extends Controller
{
    public function showLogin()
    {
        if (session('super_access_token')) {
            return redirect()->route('super.realms');
        }
        return view('super.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required',
            'password' => 'required',
        ]);

        $base = config('keycloak.base_url');

        $response = \Http::asForm()->post("{$base}/realms/master/protocol/openid-connect/token", [
            'grant_type' => 'password',
            'client_id'  => config('keycloak.admin_cli_client'),
            'username'   => $request->username,
            'password'   => $request->password,
        ]);

        if ($response->failed()) {
            return back()->withErrors(['auth' => 'Invalid credentials.']);
        }

        $tokens = $response->json();

        session([
            'super_access_token'  => $tokens['access_token'],
            'super_refresh_token' => $tokens['refresh_token'],
            'super_username'      => $request->username,
        ]);

        return redirect()->route('super.realms');
    }

    public function logout()
    {
        session()->forget(['super_access_token', 'super_refresh_token', 'super_username']);
        return redirect()->route('super.login');
    }
}
