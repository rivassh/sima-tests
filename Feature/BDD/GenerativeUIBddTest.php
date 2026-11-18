<?php

namespace Tests\Feature\BDD;

use Tests\TestCase;
use App\Models\User;

/**
 * BDD Feature: Generative UI Dashboard Schema
 *
 * As a User
 * I want to receive a schema-driven dashboard configuration
 * So that I can see a personalized, dynamic dashboard layout
 */
class GenerativeUIBddTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

#[Test]
    public function testAUser_can_request_dashboard_schema()
    {
        // Given a user is authenticated
        $dashboardPayload = [
            'widgets' => ['chart', 'table', 'card'],
            'layout' => 'grid',
            'filters' => [
                'date_range' => 'last_30_days',
                'department' => 'engineering',
            ],
        ];

        // When the user requests the dashboard schema
        $response = $this->postJson('/api/v1/generative-ui/dashboard-schema', $dashboardPayload);

        // Then the system should return the dashboard schema
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'schema' => [
                'version',
                'widgets' => [
                    '*' => ['type', 'id', 'title', 'config'],
                ],
                'layout',
            ],
        ]);
    }

#[Test]
    public function testDashboard_schema_supports_multiple_widget_types()
    {
        // Given a request for multiple widget types
        $response = $this->postJson('/api/v1/generative-ui/dashboard-schema', [
            'widgets' => ['chart', 'table', 'card', 'form'],
            'layout' => 'flex',
        ]);

        // Then the system should return a schema with all requested widget types
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'schema' => [
                'version',
                'widgets' => [
                    '*' => ['type', 'id', 'title', 'config'],
                ],
                'layout',
            ],
        ]);
        $widgetTypes = collect($response->json('schema.widgets'))->pluck('type')->toArray();
        $this->assertContains('chart', $widgetTypes);
        $this->assertContains('table', $widgetTypes);
        $this->assertContains('card', $widgetTypes);
    }

    #[Test]
    public function testDashboard_schema_requires_authentication()
    {
        // Given no authenticated user
        $this->app['auth']->guard()->logout();

        // When a request is made without authentication
        $response = $this->postJson('/api/v1/generative-ui/dashboard-schema', []);

        // Then the system should return 401 Unauthorized
        $response->assertStatus(401);
        $response->assertJsonStructure(['success', 'error', 'message']);
    }

#[Test]
    public function testDashboard_schema_returns_422_for_invalid_widget_type()
    {
        // Given an invalid widget type
        // When the user submits the request
        $response = $this->postJson('/api/v1/generative-ui/dashboard-schema', [
            'widgets' => ['invalid_widget_type'],
            'layout' => 'grid',
        ]);

        // Then the system should return 422 validation error
        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'messages']);
    }
}