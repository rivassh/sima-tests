<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Models\Candidate;
use App\Models\JobPost;
use App\Services\Talent\Crawlers\JobVisionApplicationCrawler;
use App\Services\Talent\Crawlers\JobVisionCollectionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BDD Feature: JobVision Crawler Port
 *
 * As a Platform Operator
 * I want the legacy cchat JobVision crawler ported into ســـیــمـا and fed real data
 * through an end-to-end crawl,
 * So that evaluation 0.req (job opportunities) and 0-2.req (screening
 * mechanical candidates) have real data to work from.
 */
class JobVisionCrawlerBddTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.jobvision.auth_token' => $this->validTestToken()]);
    }

    /** @return array<string,mixed> */
    private function jobPostsPayload(): array
    {
        return [
            'isSuccess' => true,
            'data' => ['listOfJobPostSummaries' => [
                [
                    'id' => 4211,
                    'title' => 'Senior Mechanical Engineer',
                    'company' => 'Kroobi Company',
                    'cityName' => 'Tehran',
                    'jobPostStatus' => ['id' => 3, 'title' => 'بسته'],
                    'description' => 'Design high-pressure piping systems for refineries.',
                    'skillList' => [
                        ['name' => 'SolidWorks'],
                        ['name' => 'CATIA'],
                    ],
                    'experienceYears' => 6,
                    'employmentType' => 'Full Time',
                    'publishedAt' => '2025-12-01T08:30:00+04:30',
                    'sourceKey' => 'jobvision',
                ],
                [
                    'id' => 4212,
                    'title' => 'Junior Backend Engineer',
                    'company' => 'TechCorp Ltd',
                    'cityName' => 'Isfahan',
                    'jobPostStatus' => ['id' => 1, 'title' => 'فعال'],
                    'description' => 'Backend and DevOps responsibilities.',
                    'skillList' => [
                        ['name' => 'Git'],
                        ['name' => 'PostgreSQL'],
                    ],
                    'experienceYears' => 2,
                    'employmentType' => 'Contract',
                    'publishedAt' => '2026-01-15T10:00:00+03:30',
                    'sourceKey' => 'jobvision',
                ],
            ]],
            'totalCount' => 2,
            'tabsCount' => 2,
        ];
    }

    /** @return array<string,mixed> */
    private function applicationsPayload(): array
    {
        return [
            'isSuccess' => true,
            'data' => ['listOfApplicationSummaries' => [
                [
                    'applicationId' => 7777,
                    'personalInfo' => ['fullName' => 'Mehran Ghasemzadeh'],
                    'skills' => ['Mechanical', 'SolidWorks', 'CATIA'],
                    'workHistory' => [[
                        'title' => 'Process Engineer',
                        'company' => 'Refinery A',
                        'durationYears' => 6,
                    ]],
                    'educationHistory' => [[
                        'degree' => 'BSc Mechanical Engineering',
                        'institution' => 'University of Tehran',
                    ]],
                    'publicProfiles' => [['url' => 'https://linkedin.com/in/mehran']],
                    'resumeText' => 'Six years designing refinery piping systems.',
                ],
                [
                    'applicationId' => 7778,
                    'personalInfo' => ['fullName' => 'Sara Hosseini'],
                    'skills' => ['Azure', 'CI/CD'],
                    'workHistory' => [],
                    'educationHistory' => [],
                    'publicProfiles' => [],
                    'resumeText' => '',
                ],
            ]],
        ];
    }

    private function validTestToken(): string
    {
        $encode = static fn (array $value): string => rtrim(strtr(
            base64_encode(json_encode($value, JSON_THROW_ON_ERROR)),
            '+/',
            '-_'
        ), '=');

        return $encode(['alg' => 'none']).'.'.$encode(['exp' => time() + 3600]).'.test-signature';
    }

    #[Test]
    public function collect_normalizes_and_persists_job_posts(): void
    {
        Http::fake([
            'employerapi.jobvision.ir/api/v1.0/JobPost/GetListOfJobPosts' => Http::response($this->jobPostsPayload(), 200),
        ]);

        $result = app(JobVisionCollectionService::class)->collect();

        $this->assertTrue($result['success'], 'collection should succeed');
        $this->assertSame(2, $result['item_count']);
        $this->assertTrue($result['is_complete']);

        $this->assertDatabaseHas('job_posts', [
            'external_id' => '4211',
            'source' => 'jobvision',
            'title' => 'Senior Mechanical Engineer',
            'company' => 'Kroobi Company',
            'location' => 'Tehran',
            'experience_years' => 6,
            'employment_type' => 'Full Time',
        ]);

        $post = JobPost::where('external_id', '4211')->firstOrFail();
        $this->assertContains('SolidWorks', $post->skills);
        $this->assertContains('CATIA', $post->skills);
        $this->assertSame('closed', $post->status);
        $this->assertNotNull($post->published_at);
    }

    #[Test]
    public function collect_is_idempotent_on_repeated_runs(): void
    {
        Http::fake([
            'employerapi.jobvision.ir/api/v1.0/JobPost/GetListOfJobPosts' => Http::response($this->jobPostsPayload(), 200),
        ]);

        app(JobVisionCollectionService::class)->collect();
        app(JobVisionCollectionService::class)->collect();

        $this->assertSame(2, JobPost::where('source', 'jobvision')->count());
    }

    #[Test]
    public function collect_marks_closed_job_posts(): void
    {
        Http::fake([
            'employerapi.jobvision.ir/api/v1.0/JobPost/GetListOfJobPosts' => Http::response($this->jobPostsPayload(), 200),
        ]);

        app(JobVisionCollectionService::class)->collect();

        $this->assertSame('closed', JobPost::where('external_id', '4211')->firstOrFail()->status);
        $this->assertSame('active', JobPost::where('external_id', '4212')->firstOrFail()->status);
    }

    #[Test]
    public function collect_reports_expired_token_on_unauthorized(): void
    {
        Http::fake([
            'employerapi.jobvision.ir/api/v1.0/JobPost/GetListOfJobPosts' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $result = app(JobVisionCollectionService::class)->collect();

        $this->assertFalse($result['success']);
        $this->assertTrue($result['token_expired']);
        $this->assertSame(0, JobPost::where('source', 'jobvision')->count());
    }

    #[Test]
    public function collect_reports_server_error_without_writing_rows(): void
    {
        Http::fake([
            'employerapi.jobvision.ir/api/v1.0/JobPost/GetListOfJobPosts' => Http::response(['message' => 'boom'], 500),
        ]);

        $result = app(JobVisionCollectionService::class)->collect();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('500', $result['error']);
        $this->assertSame(0, JobPost::where('source', 'jobvision')->count());
    }

    #[Test]
    public function application_crawler_persists_candidates_with_job_link(): void
    {
        Http::fake([
            'employerapi.jobvision.ir/api/v1.0/JobPost/GetListOfJobPosts' => Http::response($this->jobPostsPayload(), 200),
            'employerapi.jobvision.ir/api/v1.0/JobPostApplication/GetFilteredApplicationsIds' => Http::response([
                'isSuccess' => true,
                'data' => [7777, 7778],
            ], 200),
            'employerapi.jobvision.ir/api/v1.0/JobPostApplication/GetListOfApplicationsSummary' => Http::response($this->applicationsPayload(), 200),
        ]);

        app(JobVisionCollectionService::class)->collect();

        $result = app(JobVisionApplicationCrawler::class)->crawlApplications(4211);

        $this->assertTrue($result['success'], 'application crawl should succeed');
        $this->assertSame(2, $result['count']);

        $this->assertDatabaseHas('candidates', [
            'external_id' => '7777',
            'source' => 'jobvision',
            'full_name' => 'Mehran Ghasemzadeh',
            'status' => Candidate::STATUS_APPLIED,
            'education_level' => 'BSc Mechanical Engineering',
        ]);

        $candidate = Candidate::where('external_id', '7777')->firstOrFail();
        $this->assertContains('SolidWorks', $candidate->skills);
        $this->assertSame(6, $candidate->experience_years);
        $this->assertSame('Senior Mechanical Engineer', $candidate->raw_profile['job_post_title']);
        $this->assertNotNull($candidate->raw_profile['job_post_id']);
        $this->assertSame(7777, $candidate->raw_profile['application']['applicationId']);
    }

    #[Test]
    public function application_crawler_skips_unknown_job_post(): void
    {
        Http::fake();

        $result = app(JobVisionApplicationCrawler::class)->crawlApplications(999999);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
    }
}
