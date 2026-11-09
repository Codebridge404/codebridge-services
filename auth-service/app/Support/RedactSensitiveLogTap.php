<?php

namespace App\Support;

use Illuminate\Log\Logger;

final class RedactSensitiveLogTap
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new RedactSensitiveLogProcessor());
    }
}

