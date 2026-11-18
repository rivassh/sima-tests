<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\TalentAcquisitionController;
use App\Services\AI\Adapters\MimoAdapterClient;
use App\Services\AI\Contracts\LLMClientInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Talent acquisition pipeline test with database verification.
 */
class TalentAcquisitionPipelineTest extends TestCase
{
    /**
     * Test candidate screening persists data to database.
     */
    public function testScreenCandidatePersistsToDatabase(): void
    {
        // Mock the LLM client to return tool-calling responses
        $mimoAdapter = $this->createMock(MimoAdapterClient::class);

        $mimoAdapter->method('chatWithTools')
            ->willReturnOnConsecutiveCalls(
                // First call: enrich_and_extract_profile
                [
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'enrich_and_extract_profile',
                                    'arguments' => json_encode([
                                        'enriched_profile' => [
                                            'core_competencies' => ['PHP', 'Laravel'],
                                            'normalized_skills' => ['PHP', 'Laravel', 'MySQL', 'Docker'],
                                            'seniority_level' => 'Senior',
                                            'experience_years' => 5,
                                            'education_level' => 'BSc Computer Science',
                                        ],
                                    ]),
                                ],
                            ]],
                        ],
                    ]],
                ],
                // Second call: evaluate_job_fit
                [
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_2',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'evaluate_job_fit',
                                    'arguments' => json_encode([
                                        'technical_score' => 0.85,
                                        'cultural_score' => 0.90,
                                        'overall_score' => 0.875,
                                        'reasoning' => ['Strong technical fit', 'Excellent cultural alignment'],
                                        'shortlisted' => true,
                                    ]),
                                ],
                            ]],
                        ],
                    ]],
                ]
            );

        // Bind the mock on the interface the screening agent actually resolves
        $this->app->instance(LLMClientInterface::class, $mimoAdapter);

        $controller = app(TalentAcquisitionController::class);

        $response = $controller->screen(new Request([
            'source' => 'jobvision',
            'candidate' => [
                'candidate_id' => 'jv_98234',
                'personal_info' => [
                    'full_name' => 'John Doe',
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
            ],
            'job_requirements' => [
                'title' => 'Senior PHP Developer',
                'required_skills' => ['PHP', 'Laravel', 'MySQL'],
                'min_experience_years' => 3,
            ],
        ]));

        // The endpoint is asynchronous: it accepts the request and returns a job id
        $this->assertEquals(202, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('job_id', $data);

        // Poll for the screening result
        $status = $controller->screeningStatus(new Request([]), $data['job_id']);
        $this->assertEquals(200, $status->getStatusCode());
        $statusData = json_decode($status->getContent(), true);
        $this->assertSame('completed', $statusData['status']);
        $this->assertArrayHasKey('candidate', $statusData);
        $this->assertArrayHasKey('assessment', $statusData);

        // Verify database persistence - core requirement for compliance
        $this->assertDatabaseHas('candidates', [
            'external_id' => 'jv_98234',
            'full_name' => 'John Doe',
            'source' => 'jobvision',
        ]);

        $this->assertDatabaseHas('candidate_assessments', [
            'job_title' => 'Senior PHP Developer',
            'technical_score' => 0.85,
            'cultural_score' => 0.90,
            'overall_score' => 0.875,
        ]);
    }
    
    /**
     * Test executive shortlist generation.
     */
    public function testShortlistTopReturnsRankedCandidates(): void
    {
        // Create test data directly in database
        $candidate1 = \App\Models\Candidate::create([
            'id' => Str::uuid()->toString(),
            'external_id' => 'cand_001',
            'full_name' => 'Alice Developer',
            'source' => 'jobvision',
            'skills' => ['PHP', 'Laravel', 'MySQL'],
        ]);

        $candidate2 = \App\Models\Candidate::create([
            'id' => Str::uuid()->toString(),
            'external_id' => 'cand_002',
            'full_name' => 'Bob Engineer',
            'source' => 'jobvision',
            'skills' => ['Java', 'Spring'],
        ]);

        // Create assessments with different scores (higher = more qualified)
        \App\Models\CandidateAssessment::create([
            'id' => Str::uuid()->toString(),
            'candidate_id' => $candidate1->id,
            'job_title' => 'Senior Developer',
            'technical_score' => 0.90,
            'cultural_score' => 0.85,
            'overall_score' => 0.875,
        ]);
        
        \App\Models\CandidateAssessment::create([
            'id' => Str::uuid()->toString(),
            'candidate_id' => $candidate2->id,
            'job_title' => 'Senior Developer',
            'technical_score' => 0.75,
            'cultural_score' => 0.80,
            'overall_score' => 0.775,
        ]);

        // The shortlist is produced by the LLM (generate_executive_shortlist)
        $mimoAdapter = $this->createMock(MimoAdapterClient::class);
        $mimoAdapter->method('chatWithTools')
            ->willReturn([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_shortlist',
                            'type' => 'function',
                            'function' => [
                                'name' => 'generate_executive_shortlist',
                                'arguments' => json_encode([
                                    'shortlist' => [
                                        [
                                            'candidate_id' => $candidate1->id,
                                            'rank' => 1,
                                            'overall_score' => 0.875,
                                            'shortlist_rationale' => 'Highest overall score.',
                                            'decision_flags' => [],
                                        ],
                                        [
                                            'candidate_id' => $candidate2->id,
                                            'rank' => 2,
                                            'overall_score' => 0.775,
                                            'shortlist_rationale' => 'Second overall score.',
                                            'decision_flags' => [],
                                        ],
                                    ],
                                ]),
                            ],
                        ]],
                    ],
                ]],
            ]);

        $this->app->instance(LLMClientInterface::class, $mimoAdapter);

        $controller = app(TalentAcquisitionController::class);
        $request = new Request([
            'candidate_ids' => [$candidate1->id, $candidate2->id],
            'job_requirements' => [
                'title' => 'Senior Developer',
                'required_skills' => ['PHP', 'Laravel'],
            ],
            'limit' => 2,
        ]);
        
        $response = $controller->shortlistTop($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('shortlist', $data);
        $this->assertIsArray($data['shortlist']);
        $this->assertCount(2, $data['shortlist']);
        
        // Should be ordered by overall_score descending (Alice first, then Bob)
        $this->assertEquals($candidate1->id, $data['shortlist'][0]['candidate_id']);
        $this->assertEquals($candidate2->id, $data['shortlist'][1]['candidate_id']);
    }
}