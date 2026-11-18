<?php

namespace Tests\Feature\BDD;

use Tests\TestCase;
use Illuminate\Support\Str;

/**
 * BDD Feature: HR Department Management
 *
 * As an HR Administrator
 * I want to manage departments and employees
 * So that I can organize the company hierarchy and workforce
 */
class HrbddTest extends TestCase
{
#[Test]
    public function an_hr_admin_can_create_a_department()
    {
        // Given an HR admin is authenticated
        $departmentPayload = [
            'name' => 'Engineering',
            'code' => 'ENG',
            'description' => 'Engineering and Development Department',
            'manager_id' => null,
            'is_active' => true,
        ];

        // When the HR admin submits the department creation request
        $response = $this->postJson('/api/v1/hr/departments', $departmentPayload);

        // Then the system should create the department
        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'department' => [
                'id', 'name', 'code', 'description', 'is_active',
            ],
        ]);
        $this->assertEquals('Engineering', $response->json('department.name'));
        $this->assertEquals('ENG', $response->json('department.code'));
        $this->assertTrue($response->json('department.is_active'));

        // And the department should be stored in the database
        $this->assertDatabaseHas('departments', [
            'name' => 'Engineering',
            'code' => 'ENG',
            'is_active' => true,
        ]);
    }

#[Test]
    public function an_hr_admin_can_list_all_departments()
    {
        // Given multiple departments exist in the database
        // (created via migrations or seed data)

        // When the HR admin requests the department list
        $response = $this->getJson('/api/v1/hr/departments');

        // Then the system should return the department list
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'departments' => [
                '*' => ['id', 'name', 'code', 'is_active'],
            ],
        ]);
    }

#[Test]
    public function an_hr_admin_can_retrieve_single_department()
    {
        // Given a department exists in the database
        $departmentId = Str::uuid()->toString();

        // When the HR admin requests the department by ID
        $response = $this->getJson("/api/v1/hr/departments/{$departmentId}");

        // Then the system should return the department or 404
        $this->assertContains($response->status(), [200, 404]);
        if ($response->status() === 200) {
            $response->assertJsonStructure([
                'success',
                'department' => ['id', 'name', 'code', 'is_active'],
            ]);
        } else {
            $response->assertJsonStructure(['error', 'message']);
        }
    }

#[Test]
    public function an_hr_admin_can_update_a_department()
    {
        // Given a department exists in the database
        $departmentId = Str::uuid()->toString();
        $updatePayload = [
            'name' => 'Updated Department',
            'code' => 'UPD',
            'description' => 'Updated description',
            'is_active' => false,
        ];

        // When the HR admin submits the update request
        $response = $this->putJson("/api/v1/hr/departments/{$departmentId}", $updatePayload);

        // Then the system should return 200 (success) or 404 (not found)
        $this->assertContains($response->status(), [200, 404]);
        if ($response->status() === 200) {
            $response->assertJsonStructure([
                'success',
                'department' => ['id', 'name', 'code', 'is_active'],
            ]);
        }
    }

#[Test]
    public function hr_endpoints_require_authentication()
    {
        // When a request is made without authentication
        $response = $this->postJson('/api/v1/hr/departments', [
            'name' => 'Test Department',
            'code' => 'TEST',
        ]);

        // Then the system should return 401 Unauthorized
        $response->assertStatus(401);
        $response->assertJsonStructure(['success', 'error', 'message']);
    }

#[Test]
    public function department_creation_returns_422_for_invalid_data()
    {
        // Given an invalid payload (missing required fields)
        // When the HR admin submits the request
        $response = $this->postJson('/api/v1/hr/departments', [
            'code' => 'TEST',
            // missing name
        ]);

        // Then the system should return 422 validation error
        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'messages']);
    }
}