<?php

namespace App\Http\Responses;

class ResponseCode
{
    // Success Codes
    public const SUCCESS = 'SUCCESS';

    // Client Error Codes
    public const BAD_REQUEST = 'BAD_REQUEST';
    public const UNAUTHORIZED = 'UNAUTHORIZED';
    public const FORBIDDEN = 'FORBIDDEN';
    public const NOT_FOUND = 'NOT_FOUND';
    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    public const CONFLICT = 'CONFLICT';
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';

    // Auth & Security Error Codes
    public const ACCOUNT_LOCKED_OUT = 'ACCOUNT_LOCKED_OUT';
    public const TOO_MANY_ATTEMPTS = 'TOO_MANY_ATTEMPTS';
    public const LOGIN_RATE_LIMITED = 'LOGIN_RATE_LIMITED';
    public const TWO_FACTOR_REQUIRED = 'TWO_FACTOR_REQUIRED';
    public const TWO_FACTOR_ENROLLMENT_REQUIRED = 'TWO_FACTOR_ENROLLMENT_REQUIRED';
    public const INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
    public const INVALID_PIN = 'INVALID_PIN';
    public const AMBIGUOUS_PIN = 'AMBIGUOUS_PIN';
    public const INVALID_CODE = 'INVALID_CODE';
    public const INVALID_PASSWORD = 'INVALID_PASSWORD';
    public const INVALID_VERIFICATION_HASH = 'INVALID_VERIFICATION_HASH';
    public const TOKEN_EXPIRED = 'TOKEN_EXPIRED';
    public const TOKEN_INVALID = 'TOKEN_INVALID';
    public const TOKEN_REQUIRED = 'TOKEN_REQUIRED';
    public const INVALID_REFRESH_TOKEN = 'INVALID_REFRESH_TOKEN';
    public const USER_NOT_FOUND = 'USER_NOT_FOUND';
    public const ACCOUNT_INACTIVE = 'ACCOUNT_INACTIVE';
    public const ACCOUNT_SUSPENDED = 'ACCOUNT_SUSPENDED';
    public const TENANT_REQUIRED = 'TENANT_REQUIRED';
    public const ROLE_NOT_FOUND = 'ROLE_NOT_FOUND';

    // Server Error Codes
    public const SERVER_ERROR = 'SERVER_ERROR';
}

