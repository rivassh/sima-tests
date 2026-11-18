<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Talent\Crawlers\JobVisionApplicationCrawler;
use App\Services\Talent\Crawlers\JobVisionCollectionService;
use Mockery;
use Tests\TestCase;

class JobVisionChatCollectionTest extends TestCase
{
    public function test_authenticated_user_can_start_job_and_resume_collection_without_chat_completion(): void
    {
        $collection = Mockery::mock(JobVisionCollectionService::class);
        $collection->shouldReceive('collect')
            ->once()
            ->andReturn([
                'success' => true,
                'page_count' => 3,
                'item_count' => 42,
                'is_complete' => true,
            ]);
        $this->instance(JobVisionCollectionService::class, $collection);

        $applicationCrawler = Mockery::mock(JobVisionApplicationCrawler::class);
        $applicationCrawler->shouldReceive('crawlAllApplications')
            ->once()
            ->andReturn([
                'success' => true,
                'total_stored' => 17,
                'total_failed' => 2,
            ]);
        $this->instance(JobVisionApplicationCrawler::class, $applicationCrawler);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/chat/collect')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('job_posts.count', 42)
            ->assertJsonPath('job_posts.page_count', 3)
            ->assertJsonPath('applications.stored', 17)
            ->assertJsonPath('applications.failed', 2)
            ->assertJsonPath('complete', false);
    }

    public function test_collection_failure_does_not_start_resume_crawl(): void
    {
        $collection = Mockery::mock(JobVisionCollectionService::class);
        $collection->shouldReceive('collect')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'توکن JobVision منقضی شده است.',
                'token_expired' => true,
            ]);
        $this->instance(JobVisionCollectionService::class, $collection);

        $applicationCrawler = Mockery::mock(JobVisionApplicationCrawler::class);
        $applicationCrawler->shouldNotReceive('crawlAllApplications');
        $this->instance(JobVisionApplicationCrawler::class, $applicationCrawler);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/chat/collect')
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath('token_expired', true);
    }

    public function test_collection_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/v1/chat/collect')->assertUnauthorized();
    }
}
