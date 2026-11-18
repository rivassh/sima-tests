<?php

namespace Tests\Unit;

use App\Http\Middleware\ApiKeyValidationMiddleware;
use App\Http\Middleware\EnhancedAuthMiddleware;
use App\Http\Middleware\EnsureOtpAuthenticated;
use App\Http\Middleware\RateLimitMiddleware;
use App\Http\Middleware\SessionGuardMiddleware;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

class AuthHardeningTest extends \Tests\TestCase
{
    private function makeNextHandler(bool &$called): \Closure
    {
        return function () use (&$called) {
            $called = true;
            return response('OK');
        };
    }

    private function setProtectedProperty(object $object, string $property, $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    #[Test]
    public function ensure_otp_middleware_exempts_authenticated_api_routes()
    {
        $middleware = new EnsureOtpAuthenticated();
        $this->setProtectedProperty($middleware, 'exemptRoutes', ['/api/v1/health', '/api/v1/test']);

        $user = User::factory()->create();
        Auth::login($user);

        $request = Request::create('/api/v1/health', 'GET');
        $request->headers->set('User-Agent', 'test-agent');
        $request->headers->set('X-API-Key-Validated', 'true');

        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function ensure_otp_middleware_blocks_unauthenticated_api_without_api_key()
    {
        $middleware = new EnsureOtpAuthenticated();

        $request = Request::create('/api/v1/protected', 'GET');
        $request->headers->set('User-Agent', 'test-agent');

        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertFalse($called);
        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('unauthenticated', $data['error']);
    }

    #[Test]
    public function session_guard_validates_session_timeout_from_config()
    {
        Config::set('auth.session.session_timeout_minutes', 30);
        Config::set('auth.session.max_concurrent_sessions', 5);

        $user = User::factory()->create();
        Auth::login($user);

        Session::put('user_id', $user->id);
        Session::put('last_activity', now()->subMinutes(31));

        $request = Request::create('/dashboard', 'GET');
        $request->headers->set('User-Agent', 'test-agent');

        $middleware = new SessionGuardMiddleware();
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertFalse($called);
        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('session_invalid', $data['error']);
    }

    #[Test]
    public function session_guard_enforces_max_concurrent_sessions_from_config()
    {
        Config::set('auth.session.session_timeout_minutes', 120);
        Config::set('auth.session.max_concurrent_sessions', 2);

        $user = User::factory()->create();
        Auth::login($user);

        Cache::put("user_sessions:{$user->id}", 3, 3600);

        $request = Request::create('/dashboard', 'GET');
        $request->headers->set('User-Agent', 'test-agent');

        $middleware = new SessionGuardMiddleware();
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertFalse($called);
        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('max_sessions_exceeded', $data['error']);
    }

    #[Test]
    public function session_guard_bypasses_user_with_valid_session()
    {
        Config::set('auth.session.session_timeout_minutes', 120);
        Config::set('auth.session.max_concurrent_sessions', 5);

        $user = User::factory()->create();
        Auth::login($user);

        Session::put('user_id', $user->id);
        Session::put('last_activity', now());

        $request = Request::create('/dashboard', 'GET');
        $request->headers->set('User-Agent', 'test-agent');

        $middleware = new SessionGuardMiddleware();
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function rate_limit_middleware_enforces_per_minute_limit()
    {
        $ip = '192.168.1.100';
        $path = '/api/login';

        RateLimiter::clear("ip:{$ip}");

        $request1 = Request::create('/api/login', 'POST');
        $request1->server->set('REMOTE_ADDR', $ip);

        $middleware = new RateLimitMiddleware();
        $this->setProtectedProperty($middleware, 'perMinute', 1);
        $called = false;
        $response1 = $middleware->handle($request1, $this->makeNextHandler($called));

        $this->assertTrue($called);
        $this->assertEquals(200, $response1->getStatusCode());

        $request2 = Request::create('/api/login', 'POST');
        $request2->server->set('REMOTE_ADDR', $ip);

        $called = false;
        $response2 = $middleware->handle($request2, $this->makeNextHandler($called));

        $this->assertFalse($called);
        $this->assertEquals(429, $response2->getStatusCode());
    }

    #[Test]
    public function rate_limit_middleware_allows_anonymous_rate_limited_requests()
    {
        $ip = '192.168.1.101';
        $path = '/api/public';

        RateLimiter::clear("ip:{$ip}");

        $request = Request::create('/api/public', 'GET');
        $request->server->set('REMOTE_ADDR', $ip);

        $middleware = new RateLimitMiddleware();
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function api_key_validation_middleware_accepts_valid_bearer_token()
    {
        Config::set('auth.api_keys.valid_keys', ['valid-bearer-token-123']);

        $request = Request::create('/api/v1/protected', 'GET');
        $request->headers->set('Authorization', 'Bearer valid-bearer-token-123');

        $middleware = new ApiKeyValidationMiddleware();
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('true', $request->headers->get('X-API-Key-Validated'));
    }

    #[Test]
    public function api_key_validation_middleware_accepts_valid_x_api_key_header()
    {
        Config::set('auth.api_keys.valid_keys', ['valid-x-key-456']);

        $request = Request::create('/api/v1/protected', 'GET');
        $request->headers->set('X-API-Key', 'valid-x-key-456');

        $middleware = new ApiKeyValidationMiddleware();
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('true', $request->headers->get('X-API-Key-Validated'));
    }

    #[Test]
    public function api_key_validation_middleware_rejects_invalid_api_key()
    {
        Config::set('auth.api_keys.valid_keys', ['valid-key-789']);

        $request = Request::create('/api/v1/protected', 'GET');
        $request->headers->set('Authorization', 'Bearer invalid-key');

        $middleware = new ApiKeyValidationMiddleware();
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertFalse($called);
        $this->assertEquals(401, $response->getStatusCode());
    }

    #[Test]
    public function api_key_validation_sets_validated_header_on_success()
    {
        Config::set('auth.api_keys.valid_keys', ['test-validated-key']);

        $request = Request::create('/api/v1/protected', 'GET');
        $request->headers->set('Authorization', 'Bearer test-validated-key');

        $middleware = new ApiKeyValidationMiddleware();
        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertTrue($called);
        $this->assertEquals('true', $request->headers->get('X-API-Key-Validated'));
    }

    #[Test]
    public function mobile_auth_send_otp_returns_no_debug_code()
    {
        $controller = new \App\Modules\MobileAuth\Controllers\MobileAuthController();
        $request = Request::create('/api/mobile-auth/send-otp', 'POST', [
            'phone' => '+989123456789',
        ]);

        $response = $controller->sendOtp($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('code', $data);
        $this->assertArrayNotHasKey('debug_code', $data);
        $this->assertArrayNotHasKey('otp', $data);
    }

    #[Test]
    public function mobile_auth_expired_otp_returns_401()
    {
        $phoneNumber = '+989123456789';
        $code = '123456';

        Cache::put("mobile_otp:{$phoneNumber}", [
            'code' => $code,
            'expires_at' => now()->subMinutes(6)->timestamp,
        ], 300);

        $controller = new \App\Modules\MobileAuth\Controllers\MobileAuthController();
        $request = Request::create('/api/mobile-auth/verify-otp', 'POST', [
            'phone' => $phoneNumber,
            'code' => $code,
        ]);

        $response = $controller->verifyOtp($request);
        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('expired', $data['error']);
    }

    #[Test]
    public function mobile_auth_used_otp_cannot_be_reused()
    {
        $phoneNumber = '+989123456789';
        $code = '123456';

        Cache::put("mobile_otp:{$phoneNumber}", [
            'code' => $code,
            'expires_at' => now()->addMinutes(5)->timestamp,
        ], 300);

        $controller = new \App\Modules\MobileAuth\Controllers\MobileAuthController();
        $request1 = Request::create('/api/mobile-auth/verify-otp', 'POST', [
            'phone' => $phoneNumber,
            'code' => $code,
        ]);

        $response1 = $controller->verifyOtp($request1);
        $this->assertEquals(200, $response1->getStatusCode());

        // OTP consumed — second attempt must fail
        $request2 = Request::create('/api/mobile-auth/verify-otp', 'POST', [
            'phone' => $phoneNumber,
            'code' => $code,
        ]);

        $response2 = $controller->verifyOtp($request2);
        $this->assertEquals(401, $response2->getStatusCode());
        $data2 = json_decode($response2->getContent(), true);
        $this->assertEquals('expired', $data2['error']);
    }
#[Test]
    public function login_attempt_rate_limiting_triggers_lockout()
    {
        $email = 'test@example.com';
        $user = User::factory()->create(['email' => $email]);

        // Pre-populate rate limiter to simulate 5 failed attempts
        $key = "login_attempts:{$user->id}";
        RateLimiter::clear($key);
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($key, 15 * 60);
        }

        $middleware = new EnhancedAuthMiddleware();

        $request = Request::create('/login', 'POST', [
            'email' => $email,
            'password' => 'wrong-password',
        ]);
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertFalse($called);
        $this->assertEquals(429, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('account_locked', $data['error']);
    }

    #[Test]
    public function inactive_account_returns_403()
    {
        $middleware = new EnhancedAuthMiddleware();

        $user = User::factory()->create(['status' => 'inactive']);

        $request = Request::create('/dashboard', 'GET');
        $request->headers->set('User-Agent', 'test-agent');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $called = false;
        $response = $middleware->handle($request, $this->makeNextHandler($called));

        $this->assertFalse($called);
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals('account_inactive', json_decode($response->getContent(), true)['error']);
    }
}