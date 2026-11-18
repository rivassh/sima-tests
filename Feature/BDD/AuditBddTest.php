<?php

namespace Tests\Feature\BDD;

use Tests\TestCase;
use Illuminate\Support\Str;

/**
 * BDD Feature: Audit Log Management
 *
 * As an Auditor
 * I want to view and analyze system audit logs
 * So that I can track all system activities and ensure compliance
 */
class AuditBddTest extends TestCase
{
#[Test]
    public function testAnAuditor_can_retrieve_audit_logs()
    {
        // Given audit logs exist in the system
        // When the auditor requests the audit log list
        $response = $this->getJson('/api/v1/audit/logs');

        // Then the system should return the audit logs
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'logs' => [
                '*' => ['id', 'action', 'model', 'created_at'],
            ],
        ]);
    }

#[Test]
    public function testAnAuditor_can_filter_audit_logs_by_action()
    {
        // Given audit logs with different actions exist
        // When the auditor filters by a specific action
        $response = $this->getJson('/api/v1/audit/logs?action=created');

        // Then the system should return filtered audit logs
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'logs' => [
                '*' => ['id', 'action', 'model', 'created_at'],
            ],
        ]);
    }

#[Test]
    public function testAnAuditor_can_retrieve_audit_statistics()
    {
        // Given audit data exists in the system
        // When the auditor requests audit statistics
        $response = $this->getJson('/api/v1/audit/stats');

        // Then the system should return audit statistics
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'stats' => [
                'total_logs',
                'actions_breakdown',
                'recent_activity',
            ],
        ]);
    }

#[Test]
    public function audit_endpoints_require_authentication()
    {
        // When a request is made without authentication
        $response = $this->getJson('/api/v1/audit/logs');

        // Then the system should return 401 Unauthorized
        $response->assertStatus(401);
        $response->assertJsonStructure(['success', 'error', 'message']);
    }

#[Test]
    public function audit_logs_support_pagination()
    {
        // Given many audit logs exist
        // When the auditor requests with pagination parameters
        $response = $this->getJson('/api/v1/audit/logs?page=1&per_page=10');

        // Then the system should return paginated results
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'logs' => [],
            'pagination' => [
                'current_page', 'per_page', 'total', 'last_page',
            ],
        ]);
    }
}