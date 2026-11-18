<?php

namespace Tests\Unit;

use App\Models\User;
use App\Modules\MobileAuth\Controllers\MobileAuthController;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MobileAuthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function decodeResponse($response): array
    {
        return json_decode($response->getContent(), true);
    }

    #[Test]
    public function verify_otp_rejects_invalid_code()
    {
        $phoneNumber = '+989123456789';
        $wrongCode = '123456';
        $storedCode = '654321';

        // Store a valid OTP (matching controller's timestamp format)
        Cache::put("mobile_otp:{$phoneNumber}", [
            'code' => $storedCode,
            'expires_at' => now()->addMinutes(5)->timestamp,
        ], 300);

        $controller = new MobileAuthController;
        $request = Request::create('/api/mobile-auth/verify-otp', 'POST', [
            'phone' => $phoneNumber,
            'code' => $wrongCode,
        ]);

        $response = $controller->verifyOtp($request);
        $data = $this->decodeResponse($response);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('invalid_code', $data['error']);
        $this->assertEquals('Invalid OTP code.', $data['message']);
    }

    #[Test]
    public function verify_otp_rejects_expired_code()
    {
        $phoneNumber = '+989123456789';
        $code = '123456';

        // Store an expired OTP
        Cache::put("mobile_otp:{$phoneNumber}", [
            'code' => $code,
            'expires_at' => now()->subMinutes(10)->timestamp,
        ], 300);

        $controller = new MobileAuthController;
        $request = Request::create('/api/mobile-auth/verify-otp', 'POST', [
            'phone' => $phoneNumber,
            'code' => $code,
        ]);

        $response = $controller->verifyOtp($request);
        $data = $this->decodeResponse($response);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('expired', $data['error']);
        $this->assertEquals('OTP expired. Please request a new one.', $data['message']);
    }

    #[Test]
    public function verify_otp_accepts_valid_code_and_creates_user()
    {
        $phoneNumber = '+989123456789';
        $code = '123456';

        Cache::put("mobile_otp:{$phoneNumber}", [
            'code' => $code,
            'expires_at' => now()->addMinutes(5)->timestamp,
        ], 300);

        $controller = new MobileAuthController;
        $request = Request::create('/api/mobile-auth/verify-otp', 'POST', [
            'phone' => $phoneNumber,
            'code' => $code,
        ]);

        $response = $controller->verifyOtp($request);
        $data = $this->decodeResponse($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('success', $data['status']);
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('user', $data);
        $this->assertEquals($phoneNumber, $data['user']['phone_number']);

        // Verify user was created
        $this->assertDatabaseHas('users', ['phone_number' => $phoneNumber]);

        // Verify OTP was consumed
        $this->assertNull(Cache::get("mobile_otp:{$phoneNumber}"));
    }

    #[Test]
    public function send_otp_does_not_return_debug_code_in_response()
    {
        config(['auth.mobile_otp.dev_mode' => true]);

        $phoneNumber = '+989123456789';
        $controller = new MobileAuthController;
        $request = Request::create('/api/mobile-auth/send-otp', 'POST', [
            'phone' => $phoneNumber,
        ]);

        $response = $controller->sendOtp($request);
        $data = $this->decodeResponse($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('success', $data['status']);
        $this->assertArrayNotHasKey('debug_code', $data);
    }

    #[Test]
    public function development_otp_authenticates_and_creates_user_with_fixed_code()
    {
        $this->app['env'] = 'development';
        config(['auth.mobile_otp.dev_mode' => true]);

        $phoneNumber = '+989123456789';
        $controller = new MobileAuthController;
        $sendRequest = Request::create('/api/mobile-auth/send-otp', 'POST', [
            'phone' => $phoneNumber,
        ]);

        $sendResponse = $controller->sendOtp($sendRequest);
        $this->assertEquals(200, $sendResponse->getStatusCode());

        $verifyRequest = Request::create('/api/mobile-auth/verify-otp', 'POST', [
            'phone' => $phoneNumber,
            'code' => '123456',
        ]);

        $verifyResponse = $controller->verifyOtp($verifyRequest);
        $data = $this->decodeResponse($verifyResponse);

        $this->assertEquals(200, $verifyResponse->getStatusCode());
        $this->assertEquals($phoneNumber, $data['user']['phone_number']);
        $this->assertArrayHasKey('token', $data);
        $this->assertDatabaseHas('users', ['phone_number' => $phoneNumber]);
    }

    #[Test]
    public function dev_otp_is_not_accepted_outside_local_environment()
    {
        $this->app['env'] = 'production';
        config(['auth.mobile_otp.dev_mode' => true]);
        Cache::put('mobile_otp:+989123456789', [
            'code' => '123456',
            'expires_at' => now()->addMinutes(5)->timestamp,
            'dev_mode' => true,
        ], 300);

        $controller = new MobileAuthController;
        $request = Request::create('/api/mobile-auth/verify-otp', 'POST', [
            'phone' => '+989123456789',
            'code' => '123456',
        ]);

        $response = $controller->verifyOtp($request);
        $data = $this->decodeResponse($response);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('expired', $data['error']);
    }

    #[Test]
    public function send_otp_rejects_invalid_phone_format()
    {
        $controller = new MobileAuthController;
        $request = Request::create('/api/mobile-auth/send-otp', 'POST', [
            'phone' => '123456',
        ]);

        $response = $controller->sendOtp($request);
        $data = $this->decodeResponse($response);

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_phone', $data['error']);
    }

    #[Test]
    public function verify_otp_returns_json_server_error_when_cache_throws()
    {
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('get')->andThrow(new \RuntimeException('could not translate host name'));

        $this->app->instance('cache', $cache);

        $controller = new MobileAuthController;
        $request = Request::create('/api/mobile-auth/verify-otp', 'POST', [
            'phone' => '+989123456789',
            'code' => '123456',
        ]);

        $response = $controller->verifyOtp($request);
        $data = $this->decodeResponse($response);

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals('server_error', $data['error']);
        $this->assertEquals('Authentication failed. Please try again later.', $data['message']);
        $this->assertArrayNotHasKey('token', $data);
    }
}
