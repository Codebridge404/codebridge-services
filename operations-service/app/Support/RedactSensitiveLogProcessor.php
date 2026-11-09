<?php

namespace App\Support;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class RedactSensitiveLogProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: LogRedactor::redactString($record->message),
            context: LogRedactor::sanitizeArray($record->context)
        );
    }
}

