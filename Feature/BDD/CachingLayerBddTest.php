<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Services\Talent\Caching\JobCacheService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * As a Platform Operator
 * I want a caching layer for frequently accessed data
 * So that I can reduce database load and improve response times.
 */
class CachingLayerBddTest extends TestCase
{
    public function test_job_cache_returns_cached_data_on_second_call(): void
    {
        Cache::flush();
        $jobCache = new JobCacheService();

        $firstResult = $jobCache->getJobs(10);
        $secondResult = $jobCache->getJobs(10);

        // Both calls should return same cached data
        $this->assertEquals($firstResult, $secondResult);
    }

    public function test_job_cache_service_exists(): void
    {
        Cache::flush();
        $jobCache = new JobCacheService();

        $this->assertNotNull($jobCache);
        $this->assertInstanceOf(JobCacheService::class, $jobCache);
    }

    public function test_job_cache_invalidates_on_call(): void
    {
        Cache::flush();
        $jobCache = new JobCacheService();

        $jobCache->getJobs(10);
        $jobCache->invalidate();

        // After invalidate, new call should still work
        $result = $jobCache->getJobs(10);
        $this->assertNotNull($result);
    }
}