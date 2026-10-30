<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Return a standardized JSON success response (200 OK by default).
     */
    public function successResponse(
        mixed $data = null,
        string $message = 'Success',
        int $statusCode = HttpStatus::OK,
        array $headers = [],
        array $meta = [],
        array $extra = []
    ): JsonResponse {
        $payload = [
            'status' => 'success',
            'message' => $message,
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }

        if (!empty($extra)) {
            $payload = array_merge($payload, $extra);
        }

        return response()->json($payload, $statusCode, $headers);
    }

    /**
     * Return a standardized JSON 201 Created response.
     */
    public function createdResponse(
        mixed $data = null,
        string $message = 'Resource created successfully',
        array $headers = [],
        array $extra = []
    ): JsonResponse {
        $payload = [
            'status' => 'success',
            'message' => $message,
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        if (!empty($extra)) {
            $payload = array_merge($payload, $extra);
        }

        return response()->json($payload, HttpStatus::CREATED, $headers);
    }

    /**
     * Return a standardized JSON 204 No Content response.
     */
    public function noContentResponse(array $headers = []): JsonResponse
    {
        return response()->json(null, HttpStatus::NO_CONTENT, $headers);
    }

    /**
     * Return a standardized JSON error response.
     */
    public function errorResponse(
        string $message,
        int $statusCode = HttpStatus::BAD_REQUEST,
        ?string $errorCode = null,
        mixed $errors = null,
        array $headers = [],
        array $extra = []
    ): JsonResponse {
        $payload = [
            'status' => 'error',
            'message' => $message,
            'code' => $errorCode ?? 'ERROR',
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        if (!empty($extra)) {
            $payload = array_merge($payload, $extra);
        }

        return response()->json($payload, $statusCode, $headers);
    }

    /**
     * Return a standardized JSON 400 Bad Request response.
     */
    public function badRequestResponse(
        string $message = 'Bad Request',
        ?string $errorCode = ResponseCode::BAD_REQUEST,
        mixed $errors = null,
        array $headers = [],
        array $extra = []
    ): JsonResponse {
        return $this->errorResponse($message, HttpStatus::BAD_REQUEST, $errorCode, $errors, $headers, $extra);
    }

    /**
     * Return a standardized JSON 401 Unauthorized response.
     */
    public function unauthorizedResponse(
        string $message = 'Unauthenticated.',
        ?string $errorCode = ResponseCode::UNAUTHORIZED,
        array $headers = [],
        array $extra = []
    ): JsonResponse {
        return $this->errorResponse($message, HttpStatus::UNAUTHORIZED, $errorCode, headers: $headers, extra: $extra);
    }

    /**
     * Return a standardized JSON 403 Forbidden response.
     */
    public function forbiddenResponse(
        string $message = 'Forbidden action.',
        ?string $errorCode = ResponseCode::FORBIDDEN,
        array $headers = [],
        array $extra = []
    ): JsonResponse {
        return $this->errorResponse($message, HttpStatus::FORBIDDEN, $errorCode, headers: $headers, extra: $extra);
    }

    /**
     * Return a standardized JSON 404 Not Found response.
     */
    public function notFoundResponse(
        string $message = 'Resource not found.',
        ?string $errorCode = ResponseCode::NOT_FOUND,
        array $headers = [],
        array $extra = []
    ): JsonResponse {
        return $this->errorResponse($message, HttpStatus::NOT_FOUND, $errorCode, headers: $headers, extra: $extra);
    }

    /**
     * Return a standardized JSON 422 Unprocessable Entity (Validation Failed) response.
     */
    public function validationErrorResponse(
        mixed $errors,
        string $message = 'The given data was invalid.',
        string $errorCode = ResponseCode::VALIDATION_ERROR
    ): JsonResponse {
        return $this->errorResponse($message, HttpStatus::UNPROCESSABLE_ENTITY, $errorCode, $errors);
    }

    /**
     * Return a standardized JSON 429 Too Many Attempts / Rate Limited response.
     */
    public function tooManyAttemptsResponse(
        int $seconds,
        string $code = ResponseCode::LOGIN_RATE_LIMITED,
        ?string $message = null
    ): JsonResponse {
        $seconds = max(1, $seconds);

        return response()->json([
            'status' => 'error',
            'code' => $code,
            'message' => $message ?? 'Too many login attempts. Please try again later.',
            'retry_after_seconds' => $seconds,
        ], HttpStatus::TOO_MANY_REQUESTS, [
            'Retry-After' => (string) $seconds,
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Return a standardized JSON 500 Internal Server Error response.
     */
    public function serverErrorResponse(
        string $message = 'Internal server error.',
        string $errorCode = ResponseCode::SERVER_ERROR
    ): JsonResponse {
        return $this->errorResponse($message, HttpStatus::INTERNAL_SERVER_ERROR, $errorCode);
    }
}

