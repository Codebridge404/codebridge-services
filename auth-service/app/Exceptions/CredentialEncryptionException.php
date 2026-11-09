<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CredentialEncryptionException extends RuntimeException
{
    public function report(): bool
    {
        return true;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'code' => 'CREDENTIAL_ENCRYPTION_ERROR',
            'message' => $this->getMessage(),
        ], 500);
    }
}
