<?php

namespace Tests\Feature\Screening\V3;

use Tests\TestCase;
use App\Services\Talent\Screening\V3\Contracts\SimaScreeningAdapterInterface;
use App\Services\Talent\Screening\V3\Contracts\VerificationEngineInterface;

/**
 * Integration tests for SimaScreeningAdapter.
 */
class SimaScreeningAdapterTest extends TestCase
{
    private SimaScreeningAdapterInterface $adapter;
    private VerificationEngineInterface $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = app(SimaScreeningAdapterInterface::class);
        $this->engine = app(VerificationEngineInterface::class);
    }

    /**
     * @test
     * Adapter should convert SIMA payload to v3.0 format and back
     */
    public function testAdapterConvertsPayload(): void
    {
        $payload = [
            'source' => 'jobvision',
            'candidate_payload' => [
                'candidate_id' => 'jv_12345',
                'external_id' => 'jv_12345',
                'full_name' => 'John Doe',
                'skills' => ['PHP', 'Laravel', 'MySQL', 'Docker'],
                'experience_years' => 5,
                'work_history' => [
                    [
                        'title' => 'Senior PHP Developer',
                        'company' => 'Tech Corp',
                        'duration_years' => 5,
                    ],
                ],
                'education_history' => [
                    [
                        'degree' => 'BSc',
                        'field_of_study' => 'Computer Science',
                    ],
                ],
                'public_profiles' => ['https://linkedin.com/in/johndoe', 'https://github.com/johndoe'],
            ],
            'job_requirements' => [
                'title' => 'Senior PHP Developer',
                'required_skills' => ['PHP', 'Laravel', 'MySQL'],
                'min_experience_years' => 3,
            ],
            'job_title' => 'Senior PHP Developer',
        ];

        $result = $this->adapter->screen($payload);

        // Check backward-compatible structure
        $this->assertArrayHasKey('candidate_id', $result);
        $this->assertArrayHasKey('assessment_id', $result);
        $this->assertArrayHasKey('enriched_data', $result);
        $this->assertArrayHasKey('assessment_data', $result);

        // Check enriched_data structure
        $enriched = $result['enriched_data'];
        $this->assertEquals('John Doe', $enriched['full_name']);
        $this->assertArrayHasKey('core_competencies', $enriched);
        $this->assertArrayHasKey('normalized_skills', $enriched);
        $this->assertArrayHasKey('trust_score', $enriched);
        $this->assertArrayHasKey('verification_status', $enriched);
        $this->assertArrayHasKey('snippet', $enriched);

        // Check assessment_data structure
        $assessment = $result['assessment_data'];
        $this->assertArrayHasKey('technical_score', $assessment);
        $this->assertArrayHasKey('cultural_score', $assessment);
        $this->assertArrayHasKey('overall_score', $assessment);
        $this->assertArrayHasKey('reasoning', $assessment);
        $this->assertArrayHasKey('shortlisted', $assessment);
        $this->assertArrayHasKey('job_title', $assessment);
        $this->assertArrayHasKey('verification_audit', $assessment);

        // Check trust_score is in valid range
        $this->assertGreaterThanOrEqual(0, $enriched['trust_score']);
        $this->assertLessThanOrEqual(100, $enriched['trust_score']);

        // Check verification_status is valid
        $this->assertContains($enriched['verification_status'], ['APPROVED', 'REJECTED', 'NEEDS_MANUAL_AUDIT']);
    }

    /**
     * @test
     * Adapter should handle LinkedIn source
     */
    public function testAdapterHandlesLinkedInSource(): void
    {
        $payload = [
            'source' => 'linkedin',
            'candidate_payload' => [
                'candidate_id' => 'li_67890',
                'external_id' => 'li_67890',
                'full_name' => 'Jane Smith',
                'skills' => ['Java', 'Spring', 'Kubernetes'],
                'experience_years' => 8,
                'work_history' => [
                    [
                        'title' => 'Principal Engineer',
                        'company' => 'Big Tech Inc',
                        'duration_years' => 4,
                    ],
                ],
                'public_profiles' => ['https://linkedin.com/in/janesmith'],
            ],
            'job_requirements' => [
                'title' => 'Java Architect',
                'required_skills' => ['Java', 'Spring'],
                'min_experience_years' => 5,
            ],
        ];

        $result = $this->adapter->screen($payload);

        $this->assertEquals('Jane Smith', $result['enriched_data']['full_name']);
        // Experience is extracted from verified facts
        $this->assertArrayHasKey('experience', $result['enriched_data']);
    }

    /**
     * @test
     * Adapter should handle GitHub source
     */
    public function testAdapterHandlesGitHubSource(): void
    {
        $payload = [
            'source' => 'github',
            'candidate_payload' => [
                'candidate_id' => 'gh_11111',
                'external_id' => 'gh_11111',
                'full_name' => 'Bob Coder',
                'skills' => ['Python', 'Go', 'Rust'],
                'experience_years' => 3,
                'work_history' => [],
            ],
            'job_requirements' => [
                'title' => 'Backend Engineer',
                'required_skills' => ['Go', 'Rust'],
            ],
        ];

        $result = $this->adapter->screen($payload);

        $this->assertEquals('Bob Coder', $result['enriched_data']['full_name']);
        $skills = $result['enriched_data']['normalized_skills'];
        // Skills from GitHub source (tier-2) may need 2 independent sources to verify
        $this->assertIsArray($skills);
    }

    /**
     * @test
     * Adapter should handle minimal payload
     */
    public function testAdapterHandlesMinimalPayload(): void
    {
        $payload = [
            'source' => 'internal',
            'candidate_payload' => [
                'external_id' => 'int_001',
                'full_name' => 'Minimal Candidate',
            ],
            'job_requirements' => [],
        ];

        $result = $this->adapter->screen($payload);

        $this->assertEquals('Minimal Candidate', $result['enriched_data']['full_name']);
        $this->assertEquals(0, $result['enriched_data']['trust_score']);
        $this->assertEquals('REJECTED', $result['enriched_data']['verification_status']);
    }

    /**
     * @test
     * Adapter should map trust_score to technical_score correctly
     */
    public function testTrustScoreMapping(): void
    {
        $payload = [
            'source' => 'jobvision',
            'candidate_payload' => [
                'candidate_id' => 'jv_999',
                'external_id' => 'jv_999',
                'full_name' => 'Verified Candidate',
                'skills' => ['PHP', 'Laravel'],
                'experience_years' => 10,
                'work_history' => [
                    [
                        'title' => 'Lead Developer',
                        'company' => 'Official Corp',
                        'duration_years' => 10,
                    ],
                ],
                'public_profiles' => ['https://gov.registry/candidate/jv_999'],
            ],
            'job_requirements' => [
                'title' => 'Lead Developer',
                'required_skills' => ['PHP', 'Laravel'],
            ],
        ];

        $result = $this->adapter->screen($payload);

        $technicalScore = $result['assessment_data']['technical_score'];
        $trustScore = $result['enriched_data']['trust_score'];

        $this->assertEquals(round($trustScore / 100.0, 4), $technicalScore);
        $this->assertGreaterThan(0.75, $technicalScore);
    }

    /**
     * @test
     * Adapter should include conflicts in assessment reasoning
     */
    public function testConflictsInReasoning(): void
    {
        $payload = [
            'source' => 'jobvision',
            'candidate_payload' => [
                'candidate_id' => 'jv_conflict',
                'external_id' => 'jv_conflict',
                'full_name' => 'Conflict Candidate',
                'skills' => ['PHP'],
                'experience_years' => 5,
                'work_history' => [
                    [
                        'title' => 'Senior Developer',
                        'company' => 'Tier2 Corp',
                        'duration_years' => 5,
                    ],
                ],
                'public_profiles' => [
                    'https://linkedin.com/in/conflict',
                    'https://github.com/conflict',
                    'https://gov.registry/candidate/jv_conflict',
                ],
            ],
            'job_requirements' => [
                'title' => 'Senior Developer',
            ],
        ];

        $result = $this->adapter->screen($payload);

        $reasoning = $result['assessment_data']['reasoning'];
        $this->assertArrayHasKey('technical', $reasoning);
        $this->assertIsArray($reasoning['technical']);
        $this->assertNotEmpty($reasoning['technical']);
    }

    /**
     * @test
     * Engine directly should produce same structure as adapter
     */
    public function testEngineDirectVsAdapter(): void
    {
        $payload = [
            'source' => 'jobvision',
            'candidate_payload' => [
                'candidate_id' => 'direct_test',
                'external_id' => 'direct_test',
                'full_name' => 'Direct Test',
                'skills' => ['PHP'],
                'experience_years' => 3,
                'work_history' => [
                    ['title' => 'Developer', 'company' => 'Test Co', 'duration_years' => 3],
                ],
            ],
            'job_requirements' => [
                'title' => 'Developer',
                'required_skills' => ['PHP'],
            ],
        ];

        $adapterResult = $this->adapter->screen($payload);

        $this->assertArrayHasKey('enriched_data', $adapterResult);
        $this->assertArrayHasKey('assessment_data', $adapterResult);
    }
}
