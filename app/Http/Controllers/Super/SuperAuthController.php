<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SuperAuthController extends Controller
{
    public function showLogin()
    {
        if (session('super_access_token')) {
            return redirect('/super');
        }

        $verifier  = $this->generateVerifier();
        $challenge = $this->generateChallenge($verifier);

        session(['super_pkce_verifier' => $verifier]);

        $base   = config('keycloak.base_url');
        $appUrl = rtrim(config('app.url'), '/');

        $params = http_build_query([
            'client_id'             => 'lintune-admin',
            'redirect_uri'          => "{$appUrl}/super/auth/callback",
            'response_type'         => 'code',
            'scope'                 => 'openid profile email',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return redirect("{$base}/realms/master/protocol/openid-connect/auth?{$params}");
    }

    public function callback(Request $request)
    {
        if ($request->has('error')) {
            return redirect()->route('super.login')->withErrors(['auth' => 'Authentication failed.']);
        }

        $base     = config('keycloak.base_url');
        $appUrl   = rtrim(config('app.url'), '/');
        $verifier = session('super_pkce_verifier');

        $response = \Http::asForm()->post("{$base}/realms/master/protocol/openid-connect/token", [
            'grant_type'    => 'authorization_code',
            'client_id'     => 'lintune-admin',
            'client_secret' => config('keycloak.admin_client_secret'),
            'redirect_uri'  => "{$appUrl}/super/auth/callback",
            'code'          => $request->code,
            'code_verifier' => $verifier,
        ]);

        if ($response->failed()) {
            return redirect()->route('super.login')->withErrors(['auth' => 'Token exchange failed.']);
        }

        $tokens  = $response->json();
        $payload = $this->parseJwt($tokens['access_token']);

        // Must be a master realm admin
        $roles = $payload['realm_access']['roles'] ?? [];
        if (!in_array('admin', $roles)) {
            return redirect()->route('super.login')->withErrors(['auth' => 'Access denied. Master realm admin role required.']);
        }

        session([
            'super_access_token'     => $tokens['access_token'],
            'super_refresh_token'    => $tokens['refresh_token'],
            'super_id_token'         => $tokens['id_token'],
            'super_username'         => $payload['preferred_username'] ?? $payload['email'] ?? 'admin',
            'super_token_expires_at' => now()->addSeconds($tokens['expires_in'])->timestamp,
        ]);

        return redirect('/super');
    }

    public function logout()
    {
        $idToken = session('super_id_token');
        $base    = config('keycloak.base_url');
        $appUrl  = rtrim(config('app.url'), '/');

        session()->forget(['super_access_token', 'super_refresh_token', 'super_id_token', 'super_username', 'super_token_expires_at', 'super_pkce_verifier']);

        $params = http_build_query([
            'post_logout_redirect_uri' => "{$appUrl}/super/login",
            'id_token_hint'            => $idToken,
        ]);

        return redirect("{$base}/realms/master/protocol/openid-connect/logout?{$params}");
    }

    public function sessionCheck()
    {
        $refreshToken = session('super_refresh_token');
        if (!$refreshToken) {
            return response()->json(['valid' => false]);
        }

        $base     = config('keycloak.base_url');
        $appUrl   = rtrim(config('app.url'), '/');
        $response = \Http::asForm()->post("{$base}/realms/master/protocol/openid-connect/token", [
            'grant_type'    => 'refresh_token',
            'client_id'     => 'lintune-admin',
            'client_secret' => config('keycloak.admin_client_secret'),
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            session()->forget(['super_access_token', 'super_refresh_token', 'super_id_token', 'super_username', 'super_token_expires_at']);
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

    private function generateVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function generateChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function parseJwt(string $token): array
    {
        $parts = explode('.', $token);
        return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?? [];
    }
}
