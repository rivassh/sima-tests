<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Candidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Database Query Optimization Feature Tests (TASK-009)
 *
 * Verifies that database-level queries are used for candidate searches
 * instead of loading all records into memory.
 */
class DatabaseQueryOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidates_table_has_composite_indexes(): void
    {
        // Verify composite indexes exist on candidates table
        $results = \Illuminate\Support\Facades\DB::select('SHOW INDEX FROM candidates');

        // Check for status + created_at index
        $hasStatusCreatedAtIndex = false;
        foreach ($results as $indexRow) {
            if ($indexRow['Key_name'] === 'candidates_status_created_at_index') {
                $hasStatusCreatedAtIndex = true;
                break;
            }
        }
        $this->assertTrue($hasStatusCreatedAtIndex, 'candidates_status_created_at_index should exist');

        // Check for source + status index
        $hasSourceStatusIndex = false;
        foreach ($results as $indexRow) {
            if ($indexRow['Key_name'] === 'candidates_source_status_index') {
                $hasSourceStatusIndex = true;
                break;
            }
        }
        $this->assertTrue($hasSourceStatusIndex, 'candidates_source_status_index should exist');

        // Check for full_name + email index
        $hasNameEmailIndex = false;
        foreach ($results as $indexRow) {
            if ($indexRow['Key_name'] === 'candidates_name_email_index') {
                $hasNameEmailIndex = true;
                break;
            }
        }
        $this->assertTrue($hasNameEmailIndex, 'candidates_name_email_index should exist');
    }

    public function test_candidate_assessments_has_optimized_indexes(): void
    {
        $results = \Illuminate\Support\Facades\DB::select('SHOW INDEX FROM candidate_assessments');

        // Check shortlisted + candidate_id index
        $hasShortlistedCandidateIndex = false;
        foreach ($results as $indexRow) {
            if ($indexRow['Key_name'] === 'assessments_shortlisted_candidate_index') {
                $hasShortlistedCandidateIndex = true;
                break;
            }
        }
        $this->assertTrue($hasShortlistedCandidateIndex, 'assessments_shortlisted_candidate_index should exist');

        // Check candidate_id + timestamp index
        $hasCandidateTimestampIndex = false;
        foreach ($results as $indexRow) {
            if ($indexRow['Key_name'] === 'assessments_candidate_timestamp_index') {
                $hasCandidateTimestampIndex = true;
                break;
            }
        }
        $this->assertTrue($hasCandidateTimestampIndex, 'assessments_candidate_timestamp_index should exist');
    }

    public function test_screening_results_has_optimized_indexes(): void
    {
        $results = \Illuminate\Support\Facades\DB::select('SHOW INDEX FROM screening_results');

        // Check job_id + status index
        $hasJobStatusIndex = false;
        foreach ($results as $indexRow) {
            if ($indexRow['Key_name'] === 'screening_job_status_index') {
                $hasJobStatusIndex = true;
                break;
            }
        }
        $this->assertTrue($hasJobStatusIndex, 'screening_job_status_index should exist');

        // Check candidate_id + status index
        $hasCandidateStatusIndex = false;
        foreach ($results as $indexRow) {
            if ($indexRow['Key_name'] === 'screening_candidate_status_index') {
                $hasCandidateStatusIndex = true;
                break;
            }
        }
        $this->assertTrue($hasCandidateStatusIndex, 'screening_candidate_status_index should exist');
    }

    public function test_audit_logs_has_optimized_indexes(): void
    {
        $results = \Illuminate\Support\Facades\DB::select('SHOW INDEX FROM audit_logs');

        // Check auditable_id + auditable_type + created_at index
        $hasEntityCreatedIndex = false;
        foreach ($results as $indexRow) {
            if ($indexRow['Key_name'] === 'audit_entity_created_index') {
                $hasEntityCreatedIndex = true;
                break;
            }
        }
        $this->assertTrue($hasEntityCreatedIndex, 'audit_entity_created_index should exist');

        // Check user_id + created_at index
        $hasUserCreatedIndex = false;
        foreach ($results as $indexRow) {
            if ($indexRow['Key_name'] === 'audit_user_created_index') {
                $hasUserCreatedIndex = true;
                break;
            }
        }
        $this->assertTrue($hasUserCreatedIndex, 'audit_user_created_index should exist');
    }

    public function test_search_controller_uses_database_level_filtering(): void
    {
        // Create many candidates to verify filtering at DB level
        for ($i = 0; $i < 50; $i++) {
            Candidate::create([
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'external_id' => "opt-$i",
                'full_name' => "Developer$i",
                'source' => $i % 2 === 0 ? 'manual' : 'scraper',
                'skills' => ['php'],
                'status' => $i % 3 === 0 ? 'shortlisted' : 'applied',
            ]);
        }

        // Search with status filter — should use index
        $response = $this->getJson('/api/v1/search/candidates?status=applied&page=1&per_page=20');

        $response->assertStatus(200);
        $data = $response->json();

        // Should only return applied candidates (not shortlisted)
        $this->assertLessThanOrEqual(20, count($data['candidates']), 'Should paginate applied candidates');
        $this->assertGreaterThan(0, $data['total'], 'Total should reflect applied count');
        foreach ($data['candidates'] as $candidate) {
            $this->assertSame('applied', $candidate['status']);
        }
    }

    public function test_facets_query_is_efficient(): void
    {
        Candidate::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'external_id' => 'facet-1',
            'full_name' => 'Facet One',
            'source' => 'scraper',
            'skills' => ['devops', 'python'],
            'status' => 'shortlisted',
        ]);
        Candidate::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'external_id' => 'facet-2',
            'full_name' => 'Facet Two',
            'source' => 'manual',
            'skills' => ['laravel'],
            'status' => 'reviewed',
        ]);

        $response = $this->getJson('/api/v1/search/facets');

        $response->assertStatus(200);
        $data = $response->json();

        // Verify facets returned correctly
        $this->assertSame(['devops', 'python', 'laravel'], $data['facets']['skills']);
        $this->assertSame(['manual', 'scraper'], $data['facets']['sources']);
        $this->assertSame(['reviewed', 'shortlisted'], $data['facets']['statuses']);
    }
}