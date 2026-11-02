<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

class ApiErrorResponse implements Responsable
{
    /**
     * Create a new standardized API error response.
     */
    public function __construct(
        protected string $message,
        protected int $statusCode = HttpStatus::BAD_REQUEST,
        protected ?string $errorCode = null,
        protected mixed $errors = null,
        protected array $headers = [],
        protected ?int $retryAfterSeconds = null,
        protected array $extra = []
    ) {}

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse($request): JsonResponse
    {
        $payload = [
            'status' => 'error',
            'message' => $this->message,
        ];

        if ($this->errorCode !== null) {
            $payload['code'] = $this->errorCode;
        }

        if ($this->retryAfterSeconds !== null) {
            $payload['retry_after_seconds'] = $this->retryAfterSeconds;
        }

        if ($this->errors !== null) {
            $payload['errors'] = $this->errors;
        }

        if (!empty($this->extra)) {
            $payload = array_merge($payload, $this->extra);
        }

        $headers = $this->headers;
        if ($this->retryAfterSeconds !== null) {
            $headers['Retry-After'] = (string) $this->retryAfterSeconds;
            $headers['Cache-Control'] = 'no-store';
        }

        return response()->json($payload, $this->statusCode, $headers);
    }
}

