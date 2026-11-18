<?php

namespace Tests\Feature\BDD;

use Tests\TestCase;
use App\Models\Candidate;
use PHPUnit\Framework\Attributes\Test;
use App\Models\CandidateAssessment;
use App\Models\ScreeningResult;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

/**
 * BDD Feature: Talent Acquisition Pipeline
 *
 * As a Recruiter
 * I want to screen candidates asynchronously and get executive shortlists
 * So that I can efficiently evaluate and hire top talent
 */
class TalentAcquisitionBddTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The talent routes sit behind ApiAuthMiddleware.
        $this->actingAs(User::factory()->create());

        // The screening job runs inline (sync queue) and calls the LLM twice
        // (enrich_and_extract_profile, evaluate_job_fit) plus once more for
        // the shortlist. Fake them with OpenAI-shaped tool-call envelopes.
        Http::fake([
            '*' => function ($request) {
                $system = $request->data()['messages'][0]['content'] ?? '';

                if (str_contains($system, 'enrich_and_extract_profile function')) {
                    return Http::response($this->toolCall('enrich_and_extract_profile', [
                        'enriched_profile' => [
                            'core_competencies' => ['PHP', 'Laravel'],
                            'normalized_skills' => ['PHP', 'Laravel', 'MySQL'],
                            'seniority_level' => 'Senior',
                            'experience_years' => 5,
                            'education_level' => 'BSc Computer Science',
                            'public_signals' => ['github_commits_12m' => 40],
                        ],
                    ]), 200);
                }

                if (str_contains($system, 'evaluate_job_fit function')) {
                    return Http::response($this->toolCall('evaluate_job_fit', [
                        'technical_score' => 0.85,
                        'cultural_score' => 0.90,
                        'overall_score' => 0.875,
                        'reasoning' => [
                            'technical' => ['skill_coverage' => 0.9],
                            'cultural' => ['leadership_experience' => 0.88],
                            'summary' => 'Strong fit for the role.',
                        ],
                        'shortlisted' => true,
                    ]), 200);
                }

                $decoded = json_decode($this->jsonTail($request->data()['messages'][1]['content'] ?? ''), true) ?: [];
                $ids = $decoded['candidate_ids'] ?? array_column($decoded['candidates'] ?? [], 'id');

                return Http::response($this->toolCall('generate_executive_shortlist', [
                    'shortlist' => array_map(fn ($id, $i) => [
                        'candidate_id' => $id,
                        'rank' => $i + 1,
                        'overall_score' => 0.9 - ($i * 0.1),
                        'shortlist_rationale' => 'Ranked by overall assessment score.',
                        'decision_flags' => [],
                    ], is_array($ids) ? $ids : [], array_keys(is_array($ids) ? $ids : [])),
                ]), 200);
            },
        ]);
    }

    /** @return array<string,mixed> */
    private function toolCall(string $toolName, array $arguments): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_'.$toolName,
                        'type' => 'function',
                        'function' => [
                            'name' => $toolName,
                            'arguments' => json_encode($arguments),
                        ],
                    ]],
                ],
            ]],
            'model' => 'youse',
        ];
    }

    private function jsonTail(string $content): string
    {
        $start = strpos($content, '{');

        return $start === false ? '{}' : substr($content, $start);
    }

    #[Test]
    public function a_recruiter_can_screen_a_candidate_from_jobvision_and_get_job_id()
    {
        // Given a recruiter has a candidate from JobVision
        $candidatePayload = [
            'candidate_id' => 'jv_98234',
            'personal_info' => [
                'full_name' => 'John Doe',
                'email' => 'john.doe@example.com',
            ],
            'skills' => ['PHP', 'Laravel', 'MySQL', 'Docker'],
            'work_history' => [[
                'title' => 'Senior Developer',
                'company' => 'Tech Corp',
                'duration_years' => 5,
            ]],
            'education_history' => [],
            'public_profiles' => ['https://linkedin.com/in/johndoe'],
            'resume_text' => 'Experienced PHP developer...',
        ];

        $jobRequirements = [
            'title' => 'Senior PHP Developer',
            'required_skills' => ['PHP', 'Laravel', 'MySQL'],
            'min_experience_years' => 3,
        ];

        // When the recruiter submits the candidate for screening
        $response = $this->postJson('/api/v1/talent/screen', [
            'source' => 'jobvision',
            'candidate' => $candidatePayload,
            'job_requirements' => $jobRequirements,
        ]);

        // Then the system should accept the request and return a job ID
        $response->assertStatus(202);
        $response->assertJsonStructure([
            'success',
            'job_id',
            'status',
            'message',
        ]);
        // The response tells the client to poll for the async result.
        $this->assertSame(ScreeningResult::STATUS_PENDING, $response->json('status'));
        $this->assertStringContainsString('Screening job dispatched', $response->json('message'));

        // And a screening result record should be created in the database
        // (the job runs inline under the sync queue, so it is already complete)
        $this->assertDatabaseHas('screening_results', [
            'external_id' => 'jv_98234',
            'source' => 'jobvision',
            'status' => ScreeningResult::STATUS_COMPLETED,
        ]);
    }

    #[Test]
    public function a_recruiter_can_poll_screening_status_and_get_results_when_completed()
    {
        // Given a screening job was dispatched
        $screeningResult = ScreeningResult::create([
            'id' => ScreeningResult::generateId(),
            'source' => 'jobvision',
            'external_id' => 'jv_98234',
            'full_name' => 'John Doe',
            'status' => ScreeningResult::STATUS_COMPLETED,
            'result_data' => [
                'candidate_id' => 'cand-uuid-123',
                'assessment_data' => [
                    'technical_score' => 0.85,
                    'cultural_score' => 0.90,
                    'overall_score' => 0.875,
                ],
            ],
        ]);

        // When the recruiter polls for the screening status
        $response = $this->getJson("/api/v1/talent/screening/{$screeningResult->id}");

        // Then the system should return the completed results
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'job_id',
            'status',
            'candidate',
            'assessment',
        ]);
        $this->assertEquals(ScreeningResult::STATUS_COMPLETED, $response->json('status'));
        $this->assertArrayHasKey('candidate', $response->json());
        $this->assertArrayHasKey('assessment', $response->json());
    }

    #[Test]
    public function a_recruiter_gets_404_when_polling_nonexistent_screening_job()
    {
        // When the recruiter polls for a non-existent job
        $response = $this->getJson('/api/v1/talent/screening/non-existent-id');

        // Then the system should return 404
        $response->assertStatus(404);
        $response->assertJsonStructure(['error', 'message']);
    }

    #[Test]
    public function a_recruiter_can_request_executive_shortlist_from_assessed_candidates()
    {
        // Given multiple assessed candidates exist in the database
        $candidate1 = Candidate::create([
            'id' => Str::uuid()->toString(),
            'external_id' => 'cand_001',
            'full_name' => 'Alice Developer',
            'source' => 'jobvision',
            'skills' => ['PHP', 'Laravel', 'MySQL'],
            'status' => 'reviewed',
        ]);

        $candidate2 = Candidate::create([
            'id' => Str::uuid()->toString(),
            'external_id' => 'cand_002',
            'full_name' => 'Bob Engineer',
            'source' => 'jobvision',
            'skills' => ['Java', 'Spring'],
            'status' => 'reviewed',
        ]);

        CandidateAssessment::create([
            'id' => Str::uuid()->toString(),
            'candidate_id' => $candidate1->id,
            'job_title' => 'Senior Developer',
            'technical_score' => 0.90,
            'cultural_score' => 0.85,
            'overall_score' => 0.875,
            'shortlisted' => true,
        ]);

        CandidateAssessment::create([
            'id' => Str::uuid()->toString(),
            'candidate_id' => $candidate2->id,
            'job_title' => 'Senior Developer',
            'technical_score' => 0.75,
            'cultural_score' => 0.80,
            'overall_score' => 0.775,
            'shortlisted' => false,
        ]);

        // When the recruiter requests a shortlist
        $response = $this->postJson('/api/v1/talent/shortlist-top', [
            'candidate_ids' => [$candidate1->id, $candidate2->id],
            'job_requirements' => [
                'title' => 'Senior Developer',
                'required_skills' => ['PHP', 'Laravel'],
            ],
            'limit' => 2,
        ]);

        // Then the system should return a ranked shortlist
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'shortlist' => [
                '*' => ['candidate_id', 'rank', 'overall_score'],
            ],
        ]);
        $this->assertCount(2, $response->json('shortlist'));
    }

    #[Test]
    public function talent_endpoints_require_authentication()
    {
        // Given no authenticated user
        $this->app['auth']->guard()->logout();

        // When accessing talent endpoints
        $response = $this->postJson('/api/v1/talent/screen', []);

        // Then the system should return 401 Unauthorized
        $response->assertStatus(401);
        $response->assertJsonStructure(['success', 'error', 'message']);
    }

    #[Test]
    public function screening_returns_422_for_invalid_payload()
    {
        // Given an invalid payload (missing required fields)
        // When the recruiter submits the request
        $response = $this->postJson('/api/v1/talent/screen', [
            'source' => 'jobvision',
            // missing candidate and job_requirements
        ]);

        // Then the system should return 422 validation error
        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'messages']);
    }
}