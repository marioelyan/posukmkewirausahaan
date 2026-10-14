<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Multi-business enforcement flag
    |--------------------------------------------------------------------------
    |
    | When false (default) the application behaves exactly like the legacy
    | single-tenant install: no business scoping is applied to queries and
    | tenant routes are not enforced. Keep this false in production until the
    | backfill is applied, memberships exist, and the tenant routes are mounted
    | (Build 3). Tests enable it explicitly to prove isolation.
    |
    */
    'enabled' => (bool) env('MULTI_BUSINESS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Default business name
    |--------------------------------------------------------------------------
    |
    | Fallback name used by `business:backfill --create-default` when the store
    | name setting is empty.
    |
    */
    'default_name' => env('MULTI_BUSINESS_DEFAULT_NAME', 'Toko Utama'),
];
