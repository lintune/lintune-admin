<?php

return [
    'base_url'            => env('KEYCLOAK_BASE_URL', 'https://auth.lintune.com'),
    'client_id'           => env('KEYCLOAK_CLIENT_ID', 'lintune-frontend'),
    'admin_client_secret' => env('KEYCLOAK_ADMIN_CLIENT_SECRET'),
    'admin_cli_client'    => env('KEYCLOAK_ADMIN_CLI_CLIENT', 'admin-cli'),
    'frontend_url'        => env('FRONTEND_URL', 'https://dash.lintune.xyz'),
    'broker_realm'        => env('KEYCLOAK_BROKER_REALM'),
];
