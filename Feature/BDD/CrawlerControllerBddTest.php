<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Crawlers\Contracts\CrawlerInterface;
use App\Http\Controllers\Crawler\CrawlerController;
use App\Services\Talent\Crawlers\JobVisionCrawler;
use App\Services\Talent\Crawlers\JobinjaCrawler;
use App\Services\Talent\Crawlers\LinkedinCrawler;
use Tests\TestCase;

/**
 * As a Platform Operator
 * I want to manage crawler operations through controllers
 * So that crawls can be triggered, monitored, and errors handled.
 */
class CrawlerControllerBddTest extends TestCase
{
    public function test_all_three_crawler_sources_are_registered(): void
    {
        $controller = new CrawlerController();
        $status = $controller->status();

        foreach (['jobvision', 'jobinja', 'linkedin'] as $source) {
            $this->assertArrayHasKey($source, $status['crawlers']);
        }
    }

    public function test_jobvision_crawler_is_enabled_by_default(): void
    {
        $controller = new CrawlerController();
        $crawlers = $controller->status()['crawlers'];

        $this->assertTrue($crawlers['jobvision']['enabled'], 'JobVision should be enabled by default');
    }

    public function test_jobinja_crawler_is_enabled_by_default(): void
    {
        $controller = new CrawlerController();
        $crawlers = $controller->status()['crawlers'];

        $this->assertTrue($crawlers['jobinja']['enabled'], 'JobInja should be enabled by default');
    }

    public function test_linkedin_crawler_is_disabled_by_default(): void
    {
        $controller = new CrawlerController();
        $crawlers = $controller->status()['crawlers'];

        $this->assertFalse($crawlers['linkedin']['enabled'], 'LinkedIn should be disabled by default');
    }

    public function test_trigger_respects_disabled_linkedin(): void
    {
        $controller = new CrawlerController();
        $result = $controller->trigger(['source' => 'linkedin', 'query' => 'mechanical']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('disabled', $result['error']);
    }

    public function test_trigger_returns_response_for_unknown_source(): void
    {
        $controller = new CrawlerController();
        $result = $controller->trigger(['source' => 'unknown', 'query' => 'test']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown crawler source', $result['error']);
    }
}