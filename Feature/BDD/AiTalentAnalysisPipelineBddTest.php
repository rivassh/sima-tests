<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Models\Candidate;
use App\Models\CandidateAssessment;
use App\Models\JobPost;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BDD Feature: 0.req — AI Talent Analysis Pipeline
 *
 * As Naveed Sharif (evaluator)
 * I want to submit JobVision candidate data through the screening pipeline
 * and receive structured AI job-fit assessments,
 * So that the system proves it can extract skills/work-history/education,
 * enrich from public profiles, and evaluate fit against job requirements.
 *
 * NOTE: This test seeds candidates that mirror real JobVision data.
 * Real-data validation requires a fresh JobVision JWT (see T1 blocker).
 */
class AiTalentAnalysisPipelineBddTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The talent routes sit behind ApiAuthMiddleware.
        $this->actingAs(User::factory()->create());

        // Seed a job post matching the AI talent manager use case
        $this->jobPost = JobPost::create([
            'external_id' => '0req-job-001',
            'source' => 'jobvision',
            'title' => 'Senior AI Talent Manager',
            'company' => 'TechCorp',
            'location' => 'Tehran',
            'description' => 'Lead AI-powered talent analysis platform. Design ML pipelines for candidate profiling.',
            'skills' => ['Machine Learning', 'NLP', 'Python', 'PostgreSQL', 'System Design'],
            'experience_years' => 5,
            'employment_type' => 'Full Time',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function screen_candidate_from_jobvision_data_produces_structured_assessment(): void
    {
        $payload = [
            'source' => 'jobvision',
            'candidate' => [
                'candidate_id' => '0req-cand-001',
                'full_name' => 'Naveed Sharif',
                'skills' => ['Machine Learning', 'NLP', 'Python', 'PostgreSQL', 'System Design'],
                'work_history' => [[
                    'title' => 'ML Engineer',
                    'company' => 'DataCorp',
                    'duration_years' => 4,
                    'description' => 'Built NLP pipelines for candidate matching.',
                ], [
                    'title' => 'AI Team Lead',
                    'company' => 'TechCorp',
                    'duration_years' => 3,
                    'description' => 'Led team of 5 engineers on talent analysis platform.',
                ]],
                'education_history' => [[
                    'degree' => 'MSc Computer Science',
                    'institution' => 'Sharif University',
                ]],
                'public_profiles' => [['url' => 'https://linkedin.com/in/naveed-sharif']],
                'resume_text' => '7 years building ML systems for talent analysis...',
            ],
            'job_requirements' => [
                'title' => 'Senior AI Talent Manager',
                'required_skills' => ['Machine Learning', 'NLP', 'Python', 'PostgreSQL'],
                'min_experience_years' => 5,
            ],
        ];

        $response = $this->postJson('/api/v1/talent/screen', $payload);

        $response->assertStatus(202);
        $jobId = $response->json('job_id');
        $this->assertNotNull($jobId);

        // Poll for the screening result (the queue runs sync in tests).
        $status = $this->getJson("/api/v1/talent/screening/{$jobId}")->assertStatus(200);

        $this->assertSame('completed', $status->json('status'));
        $this->assertArrayHasKey('candidate', $status->json());
        $this->assertArrayHasKey('assessment', $status->json());

        $assessment = $status->json('assessment');
        $this->assertArrayHasKey('technical_score', $assessment);
        $this->assertArrayHasKey('cultural_score', $assessment);
        $this->assertArrayHasKey('overall_score', $assessment);
        $this->assertArrayHasKey('reasoning', $assessment);

        // Verify persistence
        $this->assertDatabaseHas('candidate_assessments', [
            'candidate_id' => $status->json('candidate.id'),
        ]);
    }

    #[Test]
    public function executive_shortlist_ranks_top_candidates_by_overall_score(): void
    {
        // Seed 3 assessed candidates
        $candidates = [];
        for ($i = 1; $i <= 3; $i++) {
            $c = Candidate::create([
                'id' => Str::uuid()->toString(),
                'external_id' => "0req-cand-00{$i}",
                'source' => 'jobvision',
                'full_name' => "Candidate {$i}",
                'skills' => ['ML', 'Python'],
                'raw_profile' => ['experience' => [], 'education' => [], 'public_profiles' => []],
            ]);
            CandidateAssessment::create([
                'id' => Str::uuid()->toString(),
                'candidate_id' => $c->id,
                'job_title' => 'AI Manager',
                'technical_score' => 0.9 - $i * 0.1,
                'cultural_score' => 0.85 - $i * 0.05,
                'overall_score' => 0.875 - $i * 0.075,
                'reasoning' => ['summary' => "Candidate {$i} reasoning"],
                'shortlisted' => $i === 1,
            ]);
            $candidates[] = $c->id;
        }

        $response = $this->postJson('/api/v1/talent/shortlist-top', [
            'candidate_ids' => $candidates,
            'job_requirements' => [
                'title' => 'Senior AI Talent Manager',
                'required_skills' => ['ML', 'Python'],
                'min_experience_years' => 5,
            ],
            'limit' => 3,
        ]);

        $response->assertStatus(200);
        $shortlist = $response->json('shortlist');
        $this->assertCount(3, $shortlist);
        $this->assertSame($candidates[0], $shortlist[0]['candidate_id']); // highest score first
    }
}