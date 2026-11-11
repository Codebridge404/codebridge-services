<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Automated Threat Defense & IP Auto-Ban Settings
    |--------------------------------------------------------------------------
    |
    | When repeated attack vectors (e.g., SQL injections, path traversals, scanners)
    | originate from the same source IP, the system automatically bans the IP.
    |
    */
    'auto_ban' => [
        'enabled' => env('SECURITY_AUTO_BAN_ENABLED', true),
        'max_offenses' => (int) env('SECURITY_MAX_OFFENSES', 3),
        'offense_decay_minutes' => (int) env('SECURITY_OFFENSE_WINDOW_MINUTES', 15),
        'ban_duration_minutes' => (int) env('SECURITY_BAN_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | IP Whitelist
    |--------------------------------------------------------------------------
    |
    | Trusted IPs that should never be automatically banned (e.g. gateway loopback)
    |
    */
    'whitelist' => array_filter(array_map('trim', explode(',', env('SECURITY_IP_WHITELIST', '127.0.0.1,::1')))),
];

