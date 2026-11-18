<?php

namespace Tests\Unit;

use App\Http\Middleware\ApiKeyValidationMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;

class ApiKeyValidationMiddlewareTest extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('auth.api_key_validation.enabled', true);
        Config::set('auth.api_keys.valid_keys', ['test-key-1', 'test-key-2']);
        RateLimiter::clear('api_key:' . md5('test-key-1'));
        RateLimiter::clear('api_key:' . md5('test-key-2'));
        RateLimiter::clear('api_key:anon');
        RateLimiter::clear('api_key:' . md5('invalid-key'));
    }

    private function makeNextHandler(bool &$called): \Closure
    {
        return function () use (&$called) {
            $called = true;
            return response('OK');
        };
    }

    #[Test]
    public function exempt_routes_are_accessible_without_api_key()
    {
        $middleware = new ApiKeyValidationMiddleware();
        
        foreach (['/health', '/api/health', '/api/mobile-auth/send-otp', '/api/public/status'] as $path) {
            $request = Request::create($path, 'GET');
            $called = false;
            $response = $middleware->handle($request, $this->makeNextHandler($called));
            
            $this->assertTrue($called, "Route $path should be exempt from API key requirement");
        }
    }

    #[Test]
    public function valid_api_key_in_authorization_header_allows_access()
    {
        $middleware = new ApiKeyValidationMiddleware();
        $request = Request::create('/api/v1/test', 'GET');
        $request->headers->set('Authorization', 'Bearer test-key-1');
        
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function valid_api_key_in_header_allows_access()
    {
        $middleware = new ApiKeyValidationMiddleware();
        $request = Request::create('/api/v1/test', 'GET');
        $request->headers->set('X-API-Key', 'test-key-2');
        
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function valid_api_key_in_query_parameter_allows_access()
    {
        $middleware = new ApiKeyValidationMiddleware();
        $request = Request::create('/api/v1/test?api_key=test-key-1', 'GET');
        
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function invalid_api_key_returns_401()
    {
        $middleware = new ApiKeyValidationMiddleware();
        $request = Request::create('/api/v1/test', 'GET');
        $request->headers->set('Authorization', 'Bearer invalid-key');
        
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertFalse($called);
        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('invalid_api_key', json_decode($response->getContent(), true)['error']);
    }

    #[Test]
    public function missing_api_key_returns_401()
    {
        $middleware = new ApiKeyValidationMiddleware();
        $request = Request::create('/api/v1/test', 'GET');
        
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertFalse($called);
        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('invalid_api_key', json_decode($response->getContent(), true)['error']);
    }

    #[Test]
    public function rate_limited_api_key_returns_429()
    {
        $middleware = new ApiKeyValidationMiddleware();
        
        for ($i = 0; $i < 100; $i++) {
            $request = Request::create('/api/v1/test', 'GET');
            $request->headers->set('Authorization', 'Bearer test-key-1');
            $called = false;
            $middleware->handle($request, $this->makeNextHandler($called));
        }

        $request = Request::create('/api/v1/test', 'GET');
        $request->headers->set('Authorization', 'Bearer test-key-1');
        
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertFalse($called);
        $this->assertEquals(429, $response->getStatusCode());
        $this->assertEquals('rate_limited', json_decode($response->getContent(), true)['error']);
    }

    #[Test]
    public function disabled_api_key_validation_allows_all_requests()
    {
        Config::set('auth.api_key_validation.enabled', false);
        
        $middleware = new ApiKeyValidationMiddleware();
        $request = Request::create('/api/v1/test', 'GET');
        
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
    }
}