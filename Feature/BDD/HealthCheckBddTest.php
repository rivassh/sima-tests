<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * As a Platform Operator
 * I want a health check endpoint
 * So that I can verify the application is running correctly.
 */
class HealthCheckBddTest extends TestCase
{
    public function test_health_endpoint_returns_healthy(): void
    {
        $response = $this->get('/api/v1/health');

        $this->assertEquals(200, $response->status());
        $this->assertStringContainsString('healthy', $response->json()['status']);
    }

    public function test_health_endpoint_returns_json(): void
    {
        $response = $this->get('/api/v1/health');

        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
    }

    public function test_health_endpoint_includes_version(): void
    {
        $response = $this->get('/api/v1/health');

        $this->assertArrayHasKey('version', $response->json());
    }

    public function test_health_endpoint_registered_in_routes(): void
    {
        $this->assertNotNull(Route::getRoutes()->get('health'));
    }

    public function test_health_endpoint_includes_service_name(): void
    {
        $response = $this->get('/api/v1/health');

        $this->assertArrayHasKey('service', $response->json());
    }
}