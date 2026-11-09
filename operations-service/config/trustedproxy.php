<?php

$raw = (string) env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1,::1');
$trimmed = trim($raw);

return [
    'proxies' => ($trimmed === '*' || $trimmed === '**')
        ? '*'
        : array_values(array_filter(array_map('trim', explode(',', $trimmed)))),
];

