<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class TelegramRetryableException extends RuntimeException
{
    public function __construct(
        string $message,
        public int $retryAfter = 0,
        public int $statusCode = 0,
        ?Throwable $previous = null
    ) {
        $sanitized = preg_replace('/\/bot[^\/\s]+/', '/bot[REDACTED]', $message) ?? 'Telegram delivery failed';
        parent::__construct($sanitized, $statusCode, $previous);
    }
}

