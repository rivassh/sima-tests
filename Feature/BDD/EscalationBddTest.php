<?php

namespace Tests\Feature\BDD;

use Tests\TestCase;
use App\Models\User;
use App\Models\RBAC\RbacPermission;
use Illuminate\Support\Str;

/**
 * BDD Feature: Permission Escalation Management
 *
 * As an Admin
 * I want to grant and revoke user permissions
 * So that I can control access to sensitive operations
 */
class EscalationBddTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

#[Test]
    public function testAnAdmin_can_grant_permission_to_user()
    {
        // Given an admin is authenticated and a user exists
        $target = User::factory()->create();
        $permissionPayload = [
            'user_id' => $target->id,
            'permission' => 'admin.access',
            'resource_type' => 'system',
            'resource_id' => null,
            'expires_at' => now()->addDays(7)->toIso8601String(),
        ];

        // When the admin submits the permission grant request
        $response = $this->postJson('/api/v1/escalation/grant', $permissionPayload);

        // Then the system should grant the permission
        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'escalation' => [
                'id', 'user_id', 'permission', 'resource_type', 'granted_at', 'expires_at',
            ],
        ]);
        $this->assertEquals('admin.access', $response->json('escalation.permission'));

        // And the permission should be stored in the database
        $this->assertDatabaseHas('rbac_permissions', [
            'user_id' => $permissionPayload['user_id'],
            'permission' => 'admin.access',
        ]);
    }

#[Test]
    public function testAnAdmin_can_revoke_permission_from_user()
    {
        // Given a permission was previously granted
        $target = User::factory()->create();
        $granted = RbacPermission::create([
            'permission' => 'admin.access',
            'user_id' => $target->id,
            'resource_type' => 'system',
            'expires_at' => now()->addDays(7),
        ]);

        // When the admin submits the revoke request
        $response = $this->postJson('/api/v1/escalation/revoke', [
            'permission_id' => $granted->id,
        ]);

        // Then the system should revoke the permission
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
        ]);
    }

#[Test]
    public function testEscalation_endpoints_require_authentication()
    {
        // Given no authenticated user
        $this->app['auth']->guard()->logout();

        // When a request is made without authentication
        $response = $this->postJson('/api/v1/escalation/grant', [
            'user_id' => Str::uuid(),
            'permission' => 'test.permission',
        ]);

        // Then the system should return 401 Unauthorized
        $response->assertStatus(401);
        $response->assertJsonStructure(['success', 'error', 'message']);
    }

#[Test]
    public function grant_returns_422_for_invalid_permission()
    {
        // Given an invalid permission name
        $target = User::factory()->create();

        // When the admin submits the grant request
        $response = $this->postJson('/api/v1/escalation/grant', [
            'user_id' => $target->id,
            'permission' => '', // empty permission
            'expires_at' => now()->addDays(7)->toIso8601String(),
        ]);

        // Then the system should return 422 validation error
        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'messages']);
    }

#[Test]
    public function grant_returns_422_for_invalid_expiration_date()
    {
        // Given an invalid expiration date (past date)
        $target = User::factory()->create();

        // When the admin submits the grant request
        $response = $this->postJson('/api/v1/escalation/grant', [
            'user_id' => $target->id,
            'permission' => 'test.permission',
            'expires_at' => '2020-01-01T00:00:00Z', // past date
        ]);

        // Then the system should return 422 validation error
        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'messages']);
    }
}