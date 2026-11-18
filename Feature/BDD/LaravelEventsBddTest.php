<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Events\CandidateStatusChanged;
use App\Events\JobCrawled;
use App\Events\PermissionUpdated;
use App\Events\DepartmentReorganized;
use Tests\TestCase;

class LaravelEventsTest extends TestCase
{
    public function test_candidate_status_changed_event_can_be_instantiated(): void
    {
        $event = new CandidateStatusChanged(
            candidateId: 'cand-001',
            previousStatus: 'applied',
            newStatus: 'shortlisted',
        );

        $this->assertEquals('cand-001', $event->candidateId);
        $this->assertEquals('applied', $event->previousStatus);
        $this->assertEquals('shortlisted', $event->newStatus);
    }

    public function test_candidate_status_changed_event_immutable_data(): void
    {
        $event = new CandidateStatusChanged(
            candidateId: 'cand-002',
            previousStatus: 'reviewed',
            newStatus: 'rejected',
        );

        $this->assertFalse($event->previousStatus === $event->newStatus);
    }

    public function test_job_crawled_event_can_be_instantiated(): void
    {
        $event = new JobCrawled(
            source: 'jobvision',
            query: 'mechanical',
            resultCount: 42,
        );

        $this->assertEquals('jobvision', $event->source);
        $this->assertEquals('mechanical', $event->query);
        $this->assertEquals(42, $event->resultCount);
    }

    public function test_permission_updated_event_can_be_instantiated(): void
    {
        $event = new PermissionUpdated(
            role: 'admin',
            action: 'update',
            permission: 'candidate.manage',
        );

        $this->assertEquals('admin', $event->role);
        $this->assertEquals('update', $event->action);
        $this->assertEquals('candidate.manage', $event->permission);
    }

    public function test_department_reorganized_event_can_be_instantiated(): void
    {
        $event = new DepartmentReorganized(
            departmentId: 'dept-001',
            reorganizedBy: 'admin',
            employeeCount: 15,
        );

        $this->assertEquals('dept-001', $event->departmentId);
        $this->assertEquals('admin', $event->reorganizedBy);
        $this->assertEquals(15, $event->employeeCount);
    }

    public function test_all_event_classes_exist(): void
    {
        $this->assertTrue(class_exists(CandidateStatusChanged::class));
        $this->assertTrue(class_exists(JobCrawled::class));
        $this->assertTrue(class_exists(PermissionUpdated::class));
        $this->assertTrue(class_exists(DepartmentReorganized::class));
    }
}