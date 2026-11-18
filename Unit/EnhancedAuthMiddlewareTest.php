<?php

namespace Tests\Unit;

use App\Http\Middleware\EnhancedAuthMiddleware;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;

class EnhancedAuthMiddlewareTest extends \Tests\TestCase
{
protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login_attempts:*');
    }

    private function makeNextHandler(bool &$called): \Closure
    {
        return function () use (&$called) {
            $called = true;
            return response('OK');
        };
    }

    private function createAuthenticatedRequest(string $path = '/dashboard'): Request
    {
        $user = User::factory()->create(['status' => 'active']);
        Auth::login($user);
        
        $request = Request::create($path, 'GET');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });
        
        return $request;
    }

    #[Test]
    public function unauthenticated_request_returns_401()
    {
        $middleware = new EnhancedAuthMiddleware();
        $request = Request::create('/dashboard', 'GET');
        $request->setUserResolver(function () {
            return null;
        });
        
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertFalse($called);
        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('unauthenticated', json_decode($response->getContent(), true)['error']);
    }

    #[Test]
    public function active_account_allows_access()
    {
        $middleware = new EnhancedAuthMiddleware();
        $request = $this->createAuthenticatedRequest('/dashboard');
        
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function inactive_account_returns_403()
    {
        $middleware = new EnhancedAuthMiddleware();
        
        $user = User::factory()->create(['status' => 'inactive']);
        Auth::login($user);
        
        $request = Request::create('/dashboard', 'GET');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });
        
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertFalse($called);
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals('account_inactive', json_decode($response->getContent(), true)['error']);
    }

    #[Test]
    public function locked_account_returns_429()
    {
        $middleware = new EnhancedAuthMiddleware();
        $request = $this->createAuthenticatedRequest('/dashboard');
        
        $key = "login_attempts:{$request->user()->id}";
        RateLimiter::hit($key, 1, 900);
        RateLimiter::hit($key, 1, 900);
        RateLimiter::hit($key, 1, 900);
        RateLimiter::hit($key, 1, 900);
        RateLimiter::hit($key, 1, 900);
        
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertFalse($called);
        $this->assertEquals(429, $response->getStatusCode());
        $this->assertEquals('account_locked', json_decode($response->getContent(), true)['error']);
    }

    #[Test]
    public function successful_access_updates_last_login()
    {
        $middleware = new EnhancedAuthMiddleware();
        $request = $this->createAuthenticatedRequest('/dashboard');
        
        $called = false;
        $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertTrue($called);
        $user = $request->user()->fresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertEquals($request->ip(), $user->last_login_ip);
    }
}