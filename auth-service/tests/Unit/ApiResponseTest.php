<?php

namespace Tests\Unit;

use App\Http\Responses\ApiResponse;
use App\Http\Responses\HttpStatus;
use App\Http\Responses\ResponseCode;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    use ApiResponse;

    /**
     * Test successResponse returns HTTP 200 with formatted JSON payload.
     */
    public function test_success_response(): void
    {
        $response = $this->successResponse(['user_id' => 10], 'User loaded successfully');

        $this->assertEquals(HttpStatus::OK, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertEquals('success', $data['status']);
        $this->assertEquals('User loaded successfully', $data['message']);
        $this->assertEquals(10, $data['data']['user_id']);
    }

    /**
     * Test createdResponse returns HTTP 201 with created resource.
     */
    public function test_created_response(): void
    {
        $response = $this->createdResponse(['id' => 5]);

        $this->assertEquals(HttpStatus::CREATED, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertEquals('success', $data['status']);
        $this->assertEquals('Resource created successfully', $data['message']);
        $this->assertEquals(5, $data['data']['id']);
    }

    /**
     * Test noContentResponse returns HTTP 204.
     */
    public function test_no_content_response(): void
    {
        $response = $this->noContentResponse();
        $this->assertEquals(HttpStatus::NO_CONTENT, $response->getStatusCode());
    }

    /**
     * Test errorResponse returns specified status and error code.
     */
    public function test_error_response(): void
    {
        $response = $this->errorResponse('Custom error message', HttpStatus::BAD_REQUEST, 'CUSTOM_CODE', ['field' => 'Required']);

        $this->assertEquals(HttpStatus::BAD_REQUEST, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Custom error message', $data['message']);
        $this->assertEquals('CUSTOM_CODE', $data['code']);
        $this->assertEquals(['field' => 'Required'], $data['errors']);
    }

    /**
     * Test unauthorizedResponse returns HTTP 401.
     */
    public function test_unauthorized_response(): void
    {
        $response = $this->unauthorizedResponse();

        $this->assertEquals(HttpStatus::UNAUTHORIZED, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals(ResponseCode::UNAUTHORIZED, $data['code']);
    }

    /**
     * Test forbiddenResponse returns HTTP 403.
     */
    public function test_forbidden_response(): void
    {
        $response = $this->forbiddenResponse('You do not have access.');

        $this->assertEquals(HttpStatus::FORBIDDEN, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals(ResponseCode::FORBIDDEN, $data['code']);
        $this->assertEquals('You do not have access.', $data['message']);
    }

    /**
     * Test notFoundResponse returns HTTP 404.
     */
    public function test_not_found_response(): void
    {
        $response = $this->notFoundResponse('User not found.');

        $this->assertEquals(HttpStatus::NOT_FOUND, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals(ResponseCode::NOT_FOUND, $data['code']);
    }

    /**
     * Test validationErrorResponse returns HTTP 422 with errors.
     */
    public function test_validation_error_response(): void
    {
        $response = $this->validationErrorResponse(['email' => ['The email field is required.']]);

        $this->assertEquals(HttpStatus::UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals(ResponseCode::VALIDATION_ERROR, $data['code']);
        $this->assertArrayHasKey('email', $data['errors']);
    }

    /**
     * Test tooManyAttemptsResponse returns HTTP 429 with retry headers.
     */
    public function test_too_many_attempts_response(): void
    {
        $response = $this->tooManyAttemptsResponse(
            seconds: 60,
            code: ResponseCode::ACCOUNT_LOCKED_OUT
        );

        $this->assertEquals(HttpStatus::TOO_MANY_REQUESTS, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals(ResponseCode::ACCOUNT_LOCKED_OUT, $data['code']);
        $this->assertEquals('Too many login attempts. Please try again later.', $data['message']);
        $this->assertEquals(60, $data['retry_after_seconds']);

        $this->assertEquals('60', $response->headers->get('Retry-After'));
        $this->assertEquals('no-store, private', $response->headers->get('Cache-Control'));
    }

    /**
     * Test serverErrorResponse returns HTTP 500.
     */
    public function test_server_error_response(): void
    {
        $response = $this->serverErrorResponse();

        $this->assertEquals(HttpStatus::INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals(ResponseCode::SERVER_ERROR, $data['code']);
    }

    /**
     * Test successResponse with custom extra fields merges properly at top level.
     */
    public function test_success_response_with_extra(): void
    {
        $response = $this->successResponse(
            message: 'Login successful.',
            extra: [
                'access_token' => 'jwt_sample_token',
                'token_type' => 'Bearer',
                'expires_in' => 900,
            ]
        );

        $this->assertEquals(HttpStatus::OK, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertEquals('success', $data['status']);
        $this->assertEquals('Login successful.', $data['message']);
        $this->assertEquals('jwt_sample_token', $data['access_token']);
        $this->assertEquals('Bearer', $data['token_type']);
        $this->assertEquals(900, $data['expires_in']);
    }

    /**
     * Test forbiddenResponse with custom code and headers.
     */
    public function test_forbidden_response_with_custom_code_and_headers(): void
    {
        $response = $this->forbiddenResponse(
            message: 'Two-factor authentication is required.',
            errorCode: ResponseCode::TWO_FACTOR_REQUIRED,
            headers: ['Cache-Control' => 'no-store'],
            extra: ['status' => 'requires_mfa']
        );

        $this->assertEquals(HttpStatus::FORBIDDEN, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertEquals('requires_mfa', $data['status']);
        $this->assertEquals(ResponseCode::TWO_FACTOR_REQUIRED, $data['code']);
        $this->assertEquals('no-store, private', $response->headers->get('Cache-Control'));
    }
}

