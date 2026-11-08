<?php

namespace Tests\Unit;

use App\Http\Middleware\ApiClientMiddleware;
use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ApiClientMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected ApiClientMiddleware $middleware;
    protected ApiClient $activeClient;
    protected ApiClient $inactiveClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new ApiClientMiddleware();

        $this->activeClient = ApiClient::create([
            'name' => 'Active POS Client',
            'client_key' => 'client_pos_active_test',
            'client_type' => 'public',
            'is_active' => true,
        ]);

        $this->inactiveClient = ApiClient::create([
            'name' => 'Deactivated Client',
            'client_key' => 'client_pos_inactive_test',
            'client_type' => 'public',
            'is_active' => false,
        ]);
    }

    public function test_missing_client_key_in_required_mode_returns_unauthorized(): void
    {
        $request = Request::create('/api/v1/some-endpoint', 'POST');

        $response = $this->middleware->handle($request, function ($req) {
            return response('OK', 200);
        }, 'required');

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('CLIENT_KEY_MISSING', $data['code']);
    }

    public function test_invalid_client_key_returns_unauthorized(): void
    {
        $request = Request::create('/api/v1/some-endpoint', 'POST');
        $request->headers->set('X-Client-Key', 'non_existent_key');

        $response = $this->middleware->handle($request, function ($req) {
            return response('OK', 200);
        });

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('INVALID_CLIENT_KEY', $data['code']);
    }

    public function test_inactive_client_key_returns_unauthorized(): void
    {
        $request = Request::create('/api/v1/some-endpoint', 'POST');
        $request->headers->set('X-Client-Key', 'client_pos_inactive_test');

        $response = $this->middleware->handle($request, function ($req) {
            return response('OK', 200);
        });

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('CLIENT_INACTIVE', $data['code']);
    }

    public function test_valid_client_key_injects_attributes_and_passes_through(): void
    {
        $request = Request::create('/api/v1/some-endpoint', 'POST');
        $request->headers->set('X-Client-Key', 'client_pos_active_test');

        $called = false;
        $response = $this->middleware->handle($request, function ($req) use (&$called) {
            $called = true;
            $this->assertNotNull($req->attributes->get('api_client'));
            $this->assertEquals($this->activeClient->id, $req->attributes->get('api_client_id'));
            $this->assertEquals($this->activeClient->uuid, $req->attributes->get('api_client_uuid'));
            return response('OK', 200);
        });

        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_valid_client_uuid_in_header_passes_through(): void
    {
        $request = Request::create('/api/v1/some-endpoint', 'POST');
        $request->headers->set('X-App-Key', $this->activeClient->uuid);

        $called = false;
        $response = $this->middleware->handle($request, function ($req) use (&$called) {
            $called = true;
            $this->assertEquals($this->activeClient->id, $req->attributes->get('api_client_id'));
            return response('OK', 200);
        });

        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_optional_mode_without_key_resolves_fallback(): void
    {
        $request = Request::create('/api/v1/some-endpoint', 'POST');

        $called = false;
        $response = $this->middleware->handle($request, function ($req) use (&$called) {
            $called = true;
            return response('OK', 200);
        }, 'optional');

        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
    }
}
