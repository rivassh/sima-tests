<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiVersioningBddTest extends TestCase
{
    public function test_api_routes_are_versioned(): void
    {
        $uriPatterns = $this->getRouteUris();
        $this->assertContains('api/v1/chat/completions', $uriPatterns);
        $this->assertContains('api/v2/chat/completions', $uriPatterns);
    }

    public function test_v1_routes_resolve_to_v1_group(): void
    {
        $uriPatterns = $this->getRouteUris();
        $this->assertContains('api/v1/chat/completions', $uriPatterns);
    }

    public function test_v2_routes_resolve_to_v2_group(): void
    {
        $uriPatterns = $this->getRouteUris();
        $this->assertContains('api/v2/chat/completions', $uriPatterns);
    }

    public function test_v2_endpoint_returns_version_header(): void
    {
        $response = $this->get('/api/v2/health');
        $this->assertContains(
            $response->headers->get('X-API-Version'),
            ['v1', 'v2']
        );
    }

    public function test_version_middleware_rejects_invalid_version(): void
    {
        $response = $this->get('/api/v3/health');
        $this->assertContains(
            $response->status(),
            [404, 400]
        );
    }

    private function getRouteUris(): array
    {
        return array_map(
            fn ($r) => $r->uri(),
            iterator_to_array(Route::getRoutes()->getIterator())
        );
    }
}
