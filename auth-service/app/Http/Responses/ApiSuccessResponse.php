<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

class ApiSuccessResponse implements Responsable
{
    /**
     * Create a new standardized API success response.
     */
    public function __construct(
        protected mixed $data = null,
        protected string $message = 'Success',
        protected int $statusCode = HttpStatus::OK,
        protected array $headers = [],
        protected array $meta = [],
        protected array $extra = []
    ) {}

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse($request): JsonResponse
    {
        $payload = [
            'status' => 'success',
            'message' => $this->message,
        ];

        if ($this->data !== null) {
            $payload['data'] = $this->data;
        }

        if (!empty($this->meta)) {
            $payload['meta'] = $this->meta;
        }

        if (!empty($this->extra)) {
            $payload = array_merge($payload, $this->extra);
        }

        return response()->json($payload, $this->statusCode, $this->headers);
    }
}

