<?php

namespace Tests\Unit;

use App\Http\Middleware\SessionGuardMiddleware;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\Attributes\Test;

class SessionGuardMiddlewareTest extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
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
        $user = User::factory()->create();
        Auth::login($user);
        
        $request = Request::create($path, 'GET');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });
        
        return $request;
    }

    #[Test]
    public function exempt_routes_are_accessible_without_auth()
    {
        $middleware = new SessionGuardMiddleware();
        
        foreach (['/login', '/otp', '/logout', '/health', '/api/health', '/api/mobile-auth/send-otp', '/api/public/status'] as $path) {
            $request = Request::create($path, 'GET');
            $request->setUserResolver(function () {
                return null;
            });
            
            $called = false;
            $response = $middleware->handle($request, $this->makeNextHandler($called));
            
            $this->assertTrue($called, "Route $path should be exempt from session guard");
        }
    }

    #[Test]
    public function unauthenticated_request_returns_401()
    {
        $middleware = new SessionGuardMiddleware();
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
    public function authenticated_user_with_valid_session_allows_access()
    {
        $middleware = new SessionGuardMiddleware();
        $request = $this->createAuthenticatedRequest('/dashboard');
        
        Session::put('last_activity', now());
        Session::put('ip_address', $request->ip());
        Session::put('user_agent', $request->userAgent());
        
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function expired_session_returns_401()
    {
        $middleware = new SessionGuardMiddleware();
        $request = $this->createAuthenticatedRequest('/dashboard');
        
        Session::put('last_activity', now()->subMinutes(121)->toDateTimeString());
        
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertFalse($called);
        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('session_invalid', json_decode($response->getContent(), true)['error']);
    }

    #[Test]
    public function mismatched_ip_address_returns_401_when_binding_enabled()
    {
        config(['auth.session.ip_binding' => true]);
        
        $middleware = new SessionGuardMiddleware();
        $request = $this->createAuthenticatedRequest('/dashboard');
        
        Session::put('last_activity', now());
        Session::put('ip_address', '192.168.1.100');
        Session::put('user_agent', $request->userAgent());
        
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertFalse($called);
        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('session_invalid', json_decode($response->getContent(), true)['error']);
    }

    #[Test]
    public function mismatched_user_agent_returns_401_when_binding_enabled()
    {
        config(['auth.session.ua_binding' => true]);
        
        $middleware = new SessionGuardMiddleware();
        $request = $this->createAuthenticatedRequest('/dashboard');
        
        Session::put('last_activity', now());
        Session::put('ip_address', $request->ip());
        Session::put('user_agent', 'Different User Agent');
        
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertFalse($called);
        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('session_invalid', json_decode($response->getContent(), true)['error']);
    }

    #[Test]
    public function valid_session_updates_last_activity()
    {
        $middleware = new SessionGuardMiddleware();
        $request = $this->createAuthenticatedRequest('/dashboard');
        
        Session::put('last_activity', now()->subMinutes(10));
        Session::put('ip_address', $request->ip());
        Session::put('user_agent', $request->userAgent());
        
        $called = false;
        $middleware->handle($request, $this->makeNextHandler($called));
        
        $this->assertTrue($called);
        $this->assertNotNull(Session::get('last_activity'));
    }
}