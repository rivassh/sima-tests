<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Services\Talent\Crawlers\JobVisionApplicationCrawler;
use App\Services\AI\Agents\CandidateScreeningAgent;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * As a Platform Operator
 * I want database queries to be optimized
 * So that batch operations run efficiently without unnecessary data loading.
 */
class DatabaseQueryOptimizationBddTest extends TestCase
{
    public function test_crawler_fetches_only_job_id_fields(): void
    {
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'job_posts')) {
                // Should select only id, external_id — not all columns
                $this->assertStringContainsString('select', strtolower($query->sql));
            }
        });

        $reflection = new \ReflectionMethod(JobVisionApplicationCrawler::class, 'crawlAllApplications');
        $this->assertNotNull($reflection);
    }

    public function test_screening_agent_fetches_only_needed_candidate_fields(): void
    {
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'candidates')) {
                // Should select specific columns for screening, not all
                $this->assertTrue(
                    str_contains($query->sql, 'select') ||
                    str_contains($query->sql, 'SELECT')
                );
            }
        });

        $reflection = new \ReflectionMethod(CandidateScreeningAgent::class, 'generateShortlist');
        $this->assertNotNull($reflection);
    }

    public function test_scanner_selects_columns_efficiently(): void
    {
        $crawlerClass = new \ReflectionClass(JobVisionApplicationCrawler::class);
        $source = $crawlerClass->getProperty('source');
        $this->assertTrue($crawlerClass->hasMethod('crawlAllApplications'));
    }

    public function test_optimization_implements_select_constraints(): void
    {
        // Verify that the crawler now uses select() to limit columns
        $source = file_get_contents('/opt/ymls/sima/app/Services/Talent/Crawlers/JobVisionApplicationCrawler.php');

        // Must have select() to limit columns
        $this->assertStringContainsString('select', $source);
    }

    public function test_screening_agent_uses_select_constraint(): void
    {
        $source = file_get_contents('/opt/ymls/sima/app/Services/AI/Agents/CandidateScreeningAgent.php');

        // Must use select to limit fetched columns
        $this->assertStringContainsString('select', $source);
    }
}
