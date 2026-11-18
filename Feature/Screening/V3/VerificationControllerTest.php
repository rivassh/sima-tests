<?php

namespace Tests\Feature\Screening\V3;

use Tests\TestCase;
use App\Models\User;

/**
 * Integration tests for VerificationController API endpoints.
 */
class VerificationControllerTest extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a test user and act as them
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($this->user, 'sanctum');
    }

    /**
     * @test
     * POST /api/v1/v3/screening/verify should return strict JSON
     */
    public function testVerifyEndpointReturnsStrictJson(): void
    {
        $payload = [
            'raw_dossier' => [
                'claims' => [
                    [
                        'attribute' => 'full_name',
                        'value' => 'John Doe',
                        'source' => 'https://gov.registry/candidate/123',
                    ],
                    [
                        'attribute' => 'title',
                        'value' => 'Senior Developer',
                        'source' => 'https://linkedin.com/in/johndoe',
                    ],
                    [
                        'attribute' => 'title',
                        'value' => 'Senior Developer',
                        'source' => 'https://github.com/johndoe',
                    ],
                ],
            ],
            'target_entity' => 'candidate_123',
        ];

        $response = $this->postJson('/api/v1/v3/screening/verify', $payload);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'entity_id',
            'trust_score',
            'status',
            'audit_summary' => [
                'total_claims_found',
                'verified_claims_count',
                'conflicts_count',
                'dominant_source',
            ],
            'verified_facts' => [
                '*' => [
                    'attribute',
                    'value',
                    'authority_weight',
                    'supporting_sources',
                ],
            ],
            'conflicts_detected' => [
                '*' => [
                    'attribute',
                    'rejected_value',
                    'accepted_value',
                    'rationale',
                ],
            ],
            'curated_knowledge_snippet',
        ]);

        $json = $response->json();
        $this->assertEquals('candidate_123', $json['entity_id']);
        $this->assertIsInt($json['trust_score']);
        $this->assertGreaterThanOrEqual(0, $json['trust_score']);
        $this->assertLessThanOrEqual(100, $json['trust_score']);
        $this->assertContains($json['status'], ['APPROVED', 'REJECTED', 'NEEDS_MANUAL_AUDIT']);
        
        // Verify no extra keys (schema invariance)
        $expectedKeys = [
            'entity_id', 'trust_score', 'status', 'audit_summary',
            'verified_facts', 'conflicts_detected', 'curated_knowledge_snippet'
        ];
        $this->assertEquals(array_keys($json), $expectedKeys);
    }

    /**
     * @test
     * POST /api/v1/v3/screening/verify should reject invalid payload
     */
    public function testVerifyEndpointRejectsInvalidPayload(): void
    {
        $response = $this->postJson('/api/v1/v3/screening/verify', [
            'raw_dossier' => [],
            'target_entity' => 'test',
        ]);

        $response->assertStatus(422);
    }

    /**
     * @test
     * POST /api/v1/v3/screening/verify should reject missing target_entity
     */
    public function testVerifyEndpointRejectsMissingTargetEntity(): void
    {
        $response = $this->postJson('/api/v1/v3/screening/verify', [
            'raw_dossier' => [
                'claims' => [
                    ['attribute' => 'full_name', 'value' => 'John', 'source' => 'https://example.com'],
                ],
            ],
        ]);

        $response->assertStatus(422);
    }

    /**
     * @test
     * POST /api/v1/v3/screening/screen should work with SIMA payload
     */
    public function testScreenSimaEndpoint(): void
    {
        $payload = [
            'source' => 'jobvision',
            'candidate_payload' => [
                'candidate_id' => 'jv_123',
                'external_id' => 'jv_123',
                'full_name' => 'Test Candidate',
                'skills' => ['PHP'],
                'experience_years' => 3,
            ],
            'job_requirements' => [
                'title' => 'PHP Developer',
                'required_skills' => ['PHP'],
            ],
        ];

        $response = $this->postJson('/api/v1/v3/screening/screen', $payload);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'candidate_id',
                'assessment_id',
                'enriched_data' => [
                    'full_name',
                    'trust_score',
                    'verification_status',
                    'snippet',
                ],
                'assessment_data' => [
                    'technical_score',
                    'cultural_score',
                    'overall_score',
                    'reasoning',
                    'shortlisted',
                ],
            ],
        ]);

        $json = $response->json();
        $this->assertTrue($json['success']);
        $this->assertEquals('Test Candidate', $json['data']['enriched_data']['full_name']);
    }

    /**
     * @test
     * GET /api/v1/v3/screening/schema should return JSON schema
     */
    public function testSchemaEndpoint(): void
    {
        $response = $this->getJson('/api/v1/v3/screening/schema');

        $response->assertStatus(200);
        $json = $response->json();
        
        $this->assertEquals('DeterministicMultiSourceVerificationOutput', $json['title']);
        $this->assertArrayHasKey('required', $json);
        $this->assertArrayHasKey('properties', $json);
        $this->assertContains('entity_id', $json['required']);
        $this->assertContains('trust_score', $json['required']);
        $this->assertContains('status', $json['required']);
    }

    /**
     * @test
     * Verify endpoint output should be pure JSON (no markdown)
     */
    public function testVerifyOutputNoMarkdown(): void
    {
        $payload = [
            'raw_dossier' => [
                'claims' => [
                    ['attribute' => 'full_name', 'value' => 'John Doe', 'source' => 'https://gov.registry/candidate/123'],
                ],
            ],
            'target_entity' => 'test_entity',
        ];

        $response = $this->postJson('/api/v1/v3/screening/verify', $payload);
        
        $content = $response->getContent();
        
        // Should be valid JSON
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        
        // Should not contain markdown fences
        $this->assertStringNotContainsString('```', $content);
        $this->assertStringNotContainsString('markdown', strtolower($content));
    }

    /**
     * @test
     * Verify endpoint should handle tier-4 noise sources
     */
    public function testVerifyHandlesTier4Noise(): void
    {
        $payload = [
            'raw_dossier' => [
                'claims' => [
                    ['attribute' => 'full_name', 'value' => 'John Doe', 'source' => 'https://gov.registry/candidate/123'],
                    ['attribute' => 'fake_title', 'value' => 'Fake CEO', 'source' => 'https://spam-site.example/fake'],
                ],
            ],
            'target_entity' => 'noise_test',
        ];

        $response = $this->postJson('/api/v1/v3/screening/verify', $payload);

        $response->assertStatus(200);
        $json = $response->json();
        
        // Should only have verified facts from tier-1
        $this->assertCount(1, $json['verified_facts']);
        $this->assertEquals('full_name', $json['verified_facts'][0]['attribute']);
    }
}
