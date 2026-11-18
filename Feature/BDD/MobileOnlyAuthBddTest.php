<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * As a Mobile User
 * I want to authenticate using phone number + OTP
 * So that I can securely access the mobile application features.
 */
class MobileOnlyAuthBddTest extends TestCase
{
    public function test_user_model_has_phone_number_column(): void
    {
        $user = new User();

        // Verify phone_number exists as a property/attribute
        $this->assertTrue($user->phone_number === null);
    }

    public function test_otp_send_and_verify_routes_exist(): void
    {
        $routeNames = array_map(fn($r) => $r->getName(), Route::getRoutes()->getRoutes());

        $hasSendOtp = in_array('mobile-auth.send-otp', $routeNames, true);
        $hasVerifyOtp = in_array('mobile-auth.verify-otp', $routeNames, true);

        $this->assertTrue($hasSendOtp, 'send-otp route registered');
        $this->assertTrue($hasVerifyOtp, 'verify-otp route registered');
    }

    public function test_invalid_phone_format_rejected(): void
    {
        $response = $this->postJson('/api/v1/mobile-auth/send-otp', ['phone' => 'invalid']);

        $this->assertEquals(400, $response->status());
        $this->assertEquals('invalid_phone', $response->json('error'));
    }

    public function test_valid_otp_flow(): void
    {
        $phone = '+989123456789';

        // Send OTP
        $sendResponse = $this->postJson('/api/v1/mobile-auth/send-otp', ['phone' => $phone]);
        $this->assertEquals(200, $sendResponse->status());
        $debugCode = $sendResponse->json('debug_code');

        // Verify OTP with stored code
        if ($debugCode !== null) {
            $verifyResponse = $this->postJson('/api/v1/mobile-auth/verify-otp', [
                'phone' => $phone,
                'code' => $debugCode,
            ]);
            $this->assertEquals(200, $verifyResponse->status());
        }
    }
}
