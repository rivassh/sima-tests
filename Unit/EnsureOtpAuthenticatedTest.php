<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureOtpAuthenticated;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;

class EnsureOtpAuthenticatedTest extends \Tests\TestCase
{
    private function makeNextHandler(bool &$called): \Closure
    {
        return function () use (&$called) {
            $called = true;
            return response('OK');
        };
    }

    #[Test]
    public function api_routes_return_401_for_unauthenticated_users()
    {
        $middleware = new EnsureOtpAuthenticated();
        $request = Request::create('/api/dashboard-stats', 'GET');
        $request->setUserResolver(function () {
            return null;
        });

        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertFalse($called);
        $this->assertEquals(401, $response->getStatusCode());
        $this->assertFalse(json_decode($response->getContent(), true)['success'] ?? true);
    }

    #[Test]
    public function web_routes_redirect_to_chat_for_unauthenticated_users()
    {
        $middleware = new EnsureOtpAuthenticated();
        $request = Request::create('/hr/dashboard', 'GET');
        $request->setUserResolver(function () {
            return null;
        });

        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertFalse($called);
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertStringContainsString('/chat', $response->headers->get('Location'));
    }

    #[Test]
    public function authenticated_users_are_allowed_through()
    {
        $middleware = new EnsureOtpAuthenticated();
        $request = Request::create('/dashboard', 'GET');
        $request->setUserResolver(function () {
            return new \App\Models\User();
        });

        $called = false;
        $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertTrue($called);
    }

    #[Test]
    public function exempt_routes_are_accessible_without_auth()
    {
        $middleware = new EnsureOtpAuthenticated();

        foreach (['/chat', '/login', '/otp', '/logout', '/health', '/api/health', '/api/mobile-auth/send-otp', '/api/mobile-auth/verify-otp'] as $path) {
            $request = Request::create($path, 'GET');
            $request->setUserResolver(function () {
                return null;
            });

            $called = false;
            $middleware->handle($request, $this->makeNextHandler($called));

            $this->assertTrue($called, "Route $path should be exempt from OTP requirement");
        }
    }

    #[Test]
    public function otp_bypass_via_arbitrary_code_is_blocked()
    {
        $this->assertTrue(class_exists(EnsureOtpAuthenticated::class));

        $middleware = new EnsureOtpAuthenticated();
        $request = Request::create('/api/v1/chat/completions', 'POST');
        $request->setUserResolver(function () {
            return null;
        });

        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertFalse($called);
        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('OTP authentication required.', json_decode($response->getContent(), true)['message']);
    }
}