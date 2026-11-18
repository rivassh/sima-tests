<?php

namespace Tests\Units;

use Tests\TestCase;
use App\Services\AI\Agents\CandidateScreeningAgent;
use App\Services\AI\Adapters\MimoAdapterClient;
use App\Models\Candidate;
use App\Models\CandidateAssessment;

class CandidateScreeningAgentTest extends TestCase
{
    /**
     * Test that screen() method persists candidate and assessment to database.
     */
    public function testScreenMethodPersistsCandidateToDatabase(): void
    {
        // Mock MimoAdapterClient to return tool-calling responses
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
                                        'profile' => [
                                            'external_id' => 'jv_98234',
                                            'full_name' => 'John Doe',
                                            'skills' => ['PHP', 'Laravel', 'MySQL', 'Docker'],
                                            'experience' => [[
                                                'title' => 'Senior Developer',
                                                'company' => 'Tech Corp',
                                                'duration_years' => 5,
                                            ]],
                                            'education' => [],
                                            'public_profiles' => ['https://linkedin.com/in/johndoe'],
                                            'raw_text' => 'Experienced PHP developer...',
                                        ],
                                        'public_links' => [],
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
                                    ]),
                                ],
                            ]],
                        ],
                    ]],
                ]
            );

        // Bind mock adapter in container so agent gets it
        $this->app->instance(MimoAdapterClient::class, $mimoAdapter);

        $agent = app(CandidateScreeningAgent::class);

        $result = $agent->screen([
            'source' => 'jobvision',
            'candidate_payload' => [
                'external_id' => 'jv_98234',
                'full_name' => 'John Doe',
                'skills' => ['PHP', 'Laravel', 'MySQL', 'Docker'],
                'experience' => [[
                    'title' => 'Senior Developer',
                    'company' => 'Tech Corp',
                    'duration_years' => 5,
                ]],
                'education' => [],
                'public_profiles' => ['https://linkedin.com/in/johndoe'],
                'raw_text' => 'Experienced PHP developer...',
            ],
            'job_requirements' => [
                'title' => 'Senior PHP Developer',
                'required_skills' => ['PHP', 'Laravel', 'MySQL'],
                'min_experience_years' => 3,
            ],
        ]);

        $this->assertArrayHasKey('candidate_id', $result);
        $this->assertArrayHasKey('assessment_id', $result);

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
     * Test that generateShortlist() reads from database and returns ranked candidates.
     */
    public function testGenerateShortlistReadsFromDatabase(): void
    {
        // Create test data directly in database
        $candidate1 = Candidate::create([
            'id' => '11111111-1111-1111-1111-111111111111',
            'external_id' => 'cand_001',
            'full_name' => 'Alice Developer',
            'source' => 'jobvision',
            'skills' => ['PHP', 'Laravel', 'MySQL'],
        ]);

        $candidate2 = Candidate::create([
            'id' => '22222222-2222-2222-2222-222222222222',
            'external_id' => 'cand_002',
            'full_name' => 'Bob Engineer',
            'source' => 'jobvision',
            'skills' => ['Java', 'Spring'],
        ]);

        // Create assessments with different scores
        CandidateAssessment::create([
            'id' => '33333333-3333-3333-3333-333333333333',
            'candidate_id' => $candidate1->id,
            'job_title' => 'Senior Developer',
            'technical_score' => 0.90,
            'cultural_score' => 0.85,
            'overall_score' => 0.875,
        ]);

        CandidateAssessment::create([
            'id' => '44444444-4444-4444-4444-444444444444',
            'candidate_id' => $candidate2->id,
            'job_title' => 'Senior Developer',
            'technical_score' => 0.75,
            'cultural_score' => 0.80,
            'overall_score' => 0.775,
        ]);

        $agent = app(CandidateScreeningAgent::class);

        $shortlist = $agent->generateShortlist([
            'candidate_ids' => [$candidate1->id, $candidate2->id],
            'job_requirements' => [
                'title' => 'Senior Developer',
                'required_skills' => ['PHP', 'Laravel'],
            ],
            'limit' => 2,
        ]);

        $this->assertIsArray($shortlist);
        $this->assertCount(2, $shortlist);
    }
}