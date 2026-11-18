<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * API Versioning Feature Tests
 *
 * Tests for the ApiVersion middleware — ensures that every API response
 * carries an X-API-Version header reflecting the negotiated version.
 *
 * @extends \Illuminate\Foundation\Testing\TestCase
 */

class ApiVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_version_middleware_detects_version_from_url(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);
        $response->assertSee('X-API-Version: 1');
        $response->assertHeader('X-API-Version', '1');
    }

    public function test_api_version_middleware_defaults_to_v1(): void
    {
        $response = $this->getJson('/health');

        $response->assertStatus(200);
        $response->assertSee('X-API-Version: 1');
        $response->assertHeader('X-API-Version', '1');
    }

    public function test_api_version_middleware_v2_prefix_detection(): void
    {
        $response = $this->getJson('/api/v2/health');

        $response->assertStatus(200);
        $response->assertSee('X-API-Version: 2');
        $response->assertHeader('X-API-Version', '2');
    }

    public function test_versioned_payload_has_api_version_metadata(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('api_version', $data);
        $this->assertSame('1', $data['api_version']);
    }
}