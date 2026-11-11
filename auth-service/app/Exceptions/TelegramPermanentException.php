<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class TelegramPermanentException extends RuntimeException
{
    public function __construct(
        string $message,
        public int $statusCode = 0,
        ?Throwable $previous = null
    ) {
        $sanitized = preg_replace('/\/bot[^\/\s]+/', '/bot[REDACTED]', $message) ?? 'Telegram permanent delivery failure';
        parent::__construct($sanitized, $statusCode, $previous);
    }
}

