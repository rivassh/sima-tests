<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Models\Candidate;
use App\Models\CandidateAssessment;
use App\Models\User;
use App\Services\AI\Adapters\MimoAdapterClient;
use App\Services\AI\Agents\GenericScreeningEngine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BDD Feature: Generic Broad Agentic Screening Engine
 *
 * As a system architect
 * I want a corpus-agnostic screening engine that extracts requirements matrices
 * from arbitrary unstructured text (job descriptions, product specs, legal rules,
 * medical requirements) and scores candidates against them,
 * So that the system works across Mechanical Engineering, Oil & Gas, Medical,
 * Legal, and Generic domains without hardcoding any domain-specific criteria.
 */
class GenericScreeningEngineBddTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        // Fake the LLM tool-call endpoint so the engine can be tested
        // without calling the real modular-mimo-adapter service.
        Http::fake([
            '*' => function ($request) {
                $system = $request->data()['messages'][0]['content'] ?? '';
                $user = $request->data()['messages'][1]['content'] ?? '';

                // Requirements matrix extraction call
                if (str_contains($system, 'extract_requirements_matrix')) {
                    return Http::response([
                        'choices' => [[
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_matrix_1',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'extract_requirements_matrix',
                                        'arguments' => json_encode([
                                            'requirements' => [
                                                ['skill' => 'SolidWorks', 'category' => 'technical', 'weight' => 1.0, 'priority' => 'required', 'required' => true],
                                                ['skill' => 'HPHT', 'category' => 'technical', 'weight' => 0.9, 'priority' => 'required', 'required' => true],
                                                ['skill' => 'CNC machining', 'category' => 'technical', 'weight' => 0.8, 'priority' => 'important', 'required' => false],
                                                ['skill' => 'Fluids', 'category' => 'technical', 'weight' => 0.7, 'priority' => 'nice_to_have', 'required' => false],
                                            ],
                                        ]),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                // Candidate scoring call
                if (str_contains($system, 'score_candidate_against_matrix')) {
                    return Http::response([
                        'choices' => [[
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_score_1',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'score_candidate_against_matrix',
                                        'arguments' => json_encode([
                                            'verdicts' => [
                                                ['requirement' => 'SolidWorks', 'met' => true, 'evidence' => 'Has SolidWorks experience'],
                                                ['requirement' => 'HPHT', 'met' => true, 'evidence' => 'HPHT systems experience'],
                                                ['requirement' => 'CNC machining', 'met' => false, 'evidence' => 'No CNC experience'],
                                                ['requirement' => 'Fluids', 'met' => true, 'evidence' => 'Fluids background'],
                                            ],
                                            'technical_score' => 0.8,
                                            'overall_score' => 0.75,
                                        ]),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                return Http::response([
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => '{}'],
                    ]],
                ], 200);
            },
        ]);
    }

    #[Test]
    public function extract_requirements_matrix_from_unstructured_text(): void
    {
        // Given unstructured text describing requirements (e.g. from 0-2.req style)
        $unstructuredText = 'Position requires SolidWorks CAD modeling, HPHT systems experience, CNC machining skills, and familiarity with fluid dynamics. Must have at least 4 years of experience. Required skills include SolidWorks and HPHT. Important: CNC machining. Nice to have: Fluids.';

        // When the engine extracts the requirements matrix from this text
        $engine = app(GenericScreeningEngine::class);
        $matrix = $engine->extractMatrixFromText($unstructuredText);

        // Then the matrix should contain requirement objects with skill, category, weight, priority, required
        $this->assertIsArray($matrix);
        $this->assertCount(4, $matrix);
        $this->assertArrayHasKey('skill', $matrix[0]);
        $this->assertArrayHasKey('category', $matrix[0]);
        $this->assertArrayHasKey('weight', $matrix[0]);
        $this->assertArrayHasKey('priority', $matrix[0]);
        $this->assertArrayHasKey('required', $matrix[0]);

        // And specific requirements should be identifiable
        $skills = array_column($matrix, 'skill');
        $this->assertContains('SolidWorks', $skills);
        $this->assertContains('HPHT', $skills);
    }

    #[Test]
    public function score_candidate_against_matrix_with_per_requirement_verdicts(): void
    {
        // Given a candidate profile
        $candidateProfile = [
            'id' => Str::uuid()->toString(),
            'full_name' => 'Ahmed Al-Mehairi',
            'skills' => ['SolidWorks', 'HPHT', 'Fluids', 'Python'],
            'experience_years' => 5,
            'raw_text' => 'Mechanical engineer with SolidWorks and HPHT experience...',
        ];

        // Given a requirements matrix
        $matrix = [
            ['skill' => 'SolidWorks', 'category' => 'technical', 'weight' => 1.0, 'priority' => 'required', 'required' => true],
            ['skill' => 'HPHT', 'category' => 'technical', 'weight' => 0.9, 'priority' => 'required', 'required' => true],
            ['skill' => 'CNC machining', 'category' => 'technical', 'weight' => 0.8, 'priority' => 'important', 'required' => false],
            ['skill' => 'Fluids', 'category' => 'technical', 'weight' => 0.7, 'priority' => 'nice_to_have', 'required' => false],
        ];

        // When scoring the candidate against the matrix
        $engine = app(GenericScreeningEngine::class);
        $result = $engine->scoreCandidates($matrix, [$candidateProfile]);

        // Then each requirement should have a verdict (met/not met with evidence)
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('verdicts', $result[0]);
        $this->assertCount(4, $result[0]['verdicts']);

        // SolidWorks and HPHT should be met
        $solidWorksVerdict = array_filter($result[0]['verdicts'], fn ($v) => $v['requirement'] === 'SolidWorks');
        $this->assertTrue(array_values($solidWorksVerdict)[0]['met']);
        $this->assertArrayHasKey('evidence', array_values($solidWorksVerdict)[0]);

        // CNC machining should not be met
        $cncVerdict = array_filter($result[0]['verdicts'], fn ($v) => $v['requirement'] === 'CNC machining');
        $this->assertFalse(array_values($cncVerdict)[0]['met']);
    }

    #[Test]
    public function return_ranked_top_n_shortlist(): void
    {
        // Given multiple candidates with different skill sets
        $candidates = collect([
            Candidate::create([
                'id' => Str::uuid()->toString(),
                'external_id' => 'cand_eng_001',
                'full_name' => 'Mechanical Engineer A',
                'source' => 'jobvision',
                'skills' => ['SolidWorks', 'HPHT', 'CNC machining', 'Fluids'],
                'raw_profile' => ['experience_years' => 6],
            ]),
            Candidate::create([
                'id' => Str::uuid()->toString(),
                'external_id' => 'cand_eng_002',
                'full_name' => 'Mechanical Engineer B',
                'source' => 'jobvision',
                'skills' => ['SolidWorks', 'HPHT'],
                'raw_profile' => ['experience_years' => 4],
            ]),
            Candidate::create([
                'id' => Str::uuid()->toString(),
                'external_id' => 'cand_eng_003',
                'full_name' => 'Mechanical Engineer C',
                'source' => 'jobvision',
                'skills' => ['Python', 'Java'],
                'raw_profile' => ['experience_years' => 2],
            ]),
        ]);

        // When requesting a top-N shortlist
        $engine = app(GenericScreeningEngine::class);
        $shortlist = $engine->generateShortlist([
            'requirements_matrix' => [
                ['skill' => 'SolidWorks', 'category' => 'technical', 'weight' => 1.0, 'priority' => 'required', 'required' => true],
                ['skill' => 'HPHT', 'category' => 'technical', 'weight' => 0.9, 'priority' => 'required', 'required' => true],
                ['skill' => 'CNC machining', 'category' => 'technical', 'weight' => 0.8, 'priority' => 'important', 'required' => false],
            ],
            'candidate_ids' => $candidates->pluck('id')->toArray(),
            'limit' => 2,
        ]);

        // Then the shortlist should be ranked top-N
        $this->assertIsArray($shortlist);
        $this->assertLessThanOrEqual(2, count($shortlist));
        $this->assertArrayHasKey('rank', $shortlist[0]);
        $this->assertArrayHasKey('overall_score', $shortlist[0]);
        $this->assertArrayHasKey('rationale', $shortlist[0]);
        $this->assertLessThan($shortlist[0]['overall_score'], $shortlist[1]['overall_score']);
    }

    #[Test]
    public function engine_is_domain_agnostic_works_with_medical_requirements(): void
    {
        // Given medical domain requirements
        $matrix = [
            ['skill' => 'Radiology', 'category' => 'medical', 'weight' => 1.0, 'priority' => 'required', 'required' => true],
            ['skill' => 'MRI operation', 'category' => 'medical', 'weight' => 0.9, 'priority' => 'required', 'required' => true],
            ['skill' => 'CT scanning', 'category' => 'medical', 'weight' => 0.8, 'priority' => 'important', 'required' => false],
        ];

        $candidateProfile = [
            'id' => Str::uuid()->toString(),
            'full_name' => 'Dr. Sara Hassan',
            'skills' => ['Radiology', 'MRI operation'],
            'experience_years' => 6,
        ];

        // When scoring against medical matrix
        $engine = app(GenericScreeningEngine::class);
        $result = $engine->scoreCandidates($matrix, [$candidateProfile]);

        // Then medical requirements should be handled correctly
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertCount(3, $result[0]['verdicts']);
        $radiologyVerdict = array_filter($result[0]['verdicts'], fn ($v) => $v['requirement'] === 'Radiology');
        $this->assertTrue(array_values($radiologyVerdict)[0]['met']);
    }

    #[Test]
    public function engine_is_domain_agnostic_works_with_legal_requirements(): void
    {
        // Given legal domain requirements
        $matrix = [
            ['skill' => 'Contract law', 'category' => 'legal', 'weight' => 1.0, 'priority' => 'required', 'required' => true],
            ['skill' => 'Litigation', 'category' => 'legal', 'weight' => 0.9, 'priority' => 'required', 'required' => true],
            ['skill' => 'Arbitration', 'category' => 'legal', 'weight' => 0.7, 'priority' => 'important', 'required' => false],
        ];

        $candidateProfile = [
            'id' => Str::uuid()->toString(),
            'full_name' => 'Attorney Ali Rezaei',
            'skills' => ['Contract law', 'Arbitration'],
            'experience_years' => 8,
        ];

        $engine = app(GenericScreeningEngine::class);
        $result = $engine->scoreCandidates($matrix, [$candidateProfile]);

        // Legal requirements should be scored without domain bias
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $contractLawVerdict = array_filter($result[0]['verdicts'], fn ($v) => $v['requirement'] === 'Contract law');
        $this->assertTrue(array_values($contractLawVerdict)[0]['met']);
        $litigationVerdict = array_filter($result[0]['verdicts'], fn ($v) => $v['requirement'] === 'Litigation');
        $this->assertFalse(array_values($litigationVerdict)[0]['met']);
    }

    #[Test]
    public function existing_screening_api_contract_is_preserved(): void
    {
        // Given the existing CandidateScreeningAgent API
        $agent = app(\App\Services\AI\Agents\CandidateScreeningAgent::class);

        // The screen() method should still accept the same payload shape
        $this->assertIsCallable([$agent, 'screen']);
        $this->assertIsCallable([$agent, 'generateShortlist']);

        // And generateShortlist should still accept candidate_ids, job_requirements, limit
        $reflection = new \ReflectionMethod($agent, 'generateShortlist');
        $params = $reflection->getParameters();
        $this->assertEquals(1, count($params));
        $this->assertEquals('array', $params[0]->getType()->getName() ?? '');
    }
}
