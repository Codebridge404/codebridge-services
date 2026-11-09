<?php

namespace App\Services;

use Illuminate\Support\Facades\Hash;

class PinService
{
    public static function matches(string $pin, string $hash): bool
    {
        if (str_starts_with($hash, '$2') || str_starts_with($hash, '$argon2')) {
            return Hash::check($pin, $hash);
        }
        return strlen($hash) === 64 && ctype_xdigit($hash) && hash_equals($hash, hash('sha256', $pin));
    }
}
