<?php

return [
    'url'          => env('VAULTWARDEN_URL', 'https://vault.' . env('BASE_DOMAIN', '')),
    'internal_url' => env('VAULTWARDEN_INTERNAL_URL', 'http://vaultwarden:80'),
];
