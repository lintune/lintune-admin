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
            'super_token_expires_at' => now()->addSeconds($tokens['expires_in'])->timestamp,
        ]);

        return redirect()->route('super.realms');
    }

    public function logout()
    {
        session()->forget(['super_access_token', 'super_refresh_token', 'super_username', 'super_token_expires_at']);
        return redirect()->route('super.login');
    }

    public function sessionCheck()
    {
        $refreshToken = session('super_refresh_token');
        if (!$refreshToken) {
            return response()->json(['valid' => false]);
        }

        $base = config('keycloak.base_url');
        $response = \Http::asForm()->post("{$base}/realms/master/protocol/openid-connect/token", [
            'grant_type'    => 'refresh_token',
            'client_id'     => config('keycloak.admin_cli_client'),
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            session()->forget(['super_access_token', 'super_refresh_token', 'super_username', 'super_token_expires_at']);
            return response()->json(['valid' => false]);
        }

        $tokens = $response->json();
        session([
            'super_access_token'     => $tokens['access_token'],
            'super_refresh_token'    => $tokens['refresh_token'],
            'super_token_expires_at' => now()->addSeconds($tokens['expires_in'])->timestamp,
        ]);

        return response()->json(['valid' => true, 'expires_at' => session('super_token_expires_at')]);
    }
}
