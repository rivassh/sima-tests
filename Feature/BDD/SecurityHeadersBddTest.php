<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use Tests\TestCase;

class SecurityHeadersBddTest extends TestCase
{
    public function test_api_responses_include_security_headers(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Content-Security-Policy', "default-src 'self'");
    }

    public function test_api_responses_include_cache_control_header(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertHeader('Cache-Control', 'no-cache');
        $response->assertHeader('X-Accel-Buffering', 'no');
    }

    public function test_unauthenticated_api_response_includes_security_headers(): void
    {
        $response = $this->getJson('/api/v1/search/candidates');

        $response->assertStatus(401);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_security_headers_applied_to_responses_with_errors(): void
    {
        $response = $this->getJson('/api/v1/search/candidates?skills=NonExistentSkill');

        $response->assertStatus(200);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }
}