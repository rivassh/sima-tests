<?php

namespace Tests\Unit\Screening\V3;

use Tests\TestCase;
use App\Services\Talent\Screening\V3\Contracts\VerificationEngineInterface;
use App\Services\Talent\Screening\V3\DTO\VerificationOutputDTO;

/**
 * Red Phase: Tests for End-to-End Orchestrator
 */
class VerificationEngineTest extends TestCase
{
    private VerificationEngineInterface $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(VerificationEngineInterface::class);
    }

    /**
     * @test
     * Full pipeline: isolate -> cross-validate -> resolve -> score -> snippet
     */
    public function testFullPipeline(): void
    {
        $rawDossier = [
            'claims' => [
                ['attribute' => 'full_name', 'value' => 'John Doe', 'source' => 'https://gov.registry/candidate/123'],
                ['attribute' => 'title', 'value' => 'Senior PHP Developer', 'source' => 'https://linkedin.com/in/johndoe'],
                ['attribute' => 'title', 'value' => 'Senior PHP Developer', 'source' => 'https://github.com/johndoe'],
                ['attribute' => 'company', 'value' => 'Tech Corp', 'source' => 'https://linkedin.com/in/johndoe'],
                ['attribute' => 'company', 'value' => 'Tech Corp', 'source' => 'https://github.com/johndoe'],
                ['attribute' => 'experience_years', 'value' => '5', 'source' => 'https://gov.registry/candidate/123'],
            ],
        ];
        
        $result = $this->engine->verify($rawDossier, 'candidate_123');
        
        $this->assertInstanceOf(VerificationOutputDTO::class, $result);
        $this->assertEquals('candidate_123', $result->entity_id);
        $this->assertGreaterThan(75, $result->trust_score);
        $this->assertEquals('APPROVED', $result->status);
        $this->assertCount(4, $result->verified_facts); // full_name, title, company, experience_years
        $this->assertCount(0, $result->conflicts_detected);
    }

    /**
     * @test
     * Pipeline with tier-4 noise should discard noise
     */
    public function testPipelineDiscardsTier4Noise(): void
    {
        $rawDossier = [
            'claims' => [
                ['attribute' => 'full_name', 'value' => 'John Doe', 'source' => 'https://gov.registry/candidate/123'],
                ['attribute' => 'title', 'value' => 'Fake Title', 'source' => 'https://spam-site.example/fake'],
            ],
        ];
        
        $result = $this->engine->verify($rawDossier, 'candidate_123');
        
        $this->assertEquals(1, count($result->verified_facts));
        $this->assertEquals('full_name', $result->verified_facts[0]->attribute);
    }

    /**
     * @test
     * Pipeline with verified conflict should detect and report
     */
    public function testPipelineDetectsConflict(): void
    {
        // Both values verified: tier-1 for Senior, 2 independent tier-2 for Junior
        $rawDossier = [
            'claims' => [
                ['attribute' => 'full_name', 'value' => 'John Doe', 'source' => 'https://gov.registry/candidate/123'],
                ['attribute' => 'title', 'value' => 'Senior Developer', 'source' => 'https://gov.registry/candidate/123'],
                ['attribute' => 'title', 'value' => 'Junior Developer', 'source' => 'https://linkedin.com/in/johndoe'],
                ['attribute' => 'title', 'value' => 'Junior Developer', 'source' => 'https://github.com/johndoe'],
            ],
        ];
        
        $result = $this->engine->verify($rawDossier, 'candidate_123');
        
        $this->assertCount(2, $result->verified_facts); // full_name + title (tier-1 wins)
        $this->assertCount(1, $result->conflicts_detected);
        $this->assertEquals('title', $result->conflicts_detected[0]->attribute);
        $this->assertEquals('Senior Developer', $result->conflicts_detected[0]->accepted_value);
        $this->assertEquals('Junior Developer', $result->conflicts_detected[0]->rejected_value);
    }

    /**
     * @test
     * Pipeline with identity conflict should reject
     */
    public function testPipelineIdentityConflictRejects(): void
    {
        // Both identity values from tier-1 (or at least verified)
        $rawDossier = [
            'claims' => [
                ['attribute' => 'full_name', 'value' => 'John Doe', 'source' => 'https://gov.registry/candidate/123'],
                ['attribute' => 'identity', 'value' => 'John Doe', 'source' => 'https://gov.registry/candidate/123'],
                ['attribute' => 'identity', 'value' => 'Jane Smith', 'source' => 'https://gov.registry/candidate/456'],
            ],
        ];
        
        $result = $this->engine->verify($rawDossier, 'candidate_123');
        
        $this->assertEquals('REJECTED', $result->status);
        $this->assertCount(1, $result->conflicts_detected);
        $this->assertEquals('identity', $result->conflicts_detected[0]->attribute);
    }

    /**
     * @test
     * Hard constraint: zero extrapolation - no output outside input data
     */
    public function testZeroExtrapolation(): void
    {
        $rawDossier = [
            'claims' => [
                ['attribute' => 'full_name', 'value' => 'John Doe', 'source' => 'https://gov.registry/candidate/123'],
            ],
        ];
        
        $result = $this->engine->verify($rawDossier, 'candidate_123');
        
        $snippet = $result->curated_knowledge_snippet;
        
        // Should only contain facts from input
        $this->assertStringContainsString('John Doe', $snippet);
        // Should NOT contain fabricated information
        $this->assertStringNotContainsString('probable', strtolower($snippet));
        $this->assertStringNotContainsString('likely', strtolower($snippet));
    }

    /**
     * @test
     * Hard constraint: schema invariance - exact keys only
     */
    public function testSchemaInvariance(): void
    {
        $rawDossier = [
            'claims' => [
                ['attribute' => 'full_name', 'value' => 'John Doe', 'source' => 'https://gov.registry/candidate/123'],
            ],
        ];
        
        $result = $this->engine->verify($rawDossier, 'candidate_123');
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true);
        
        $requiredKeys = [
            'entity_id', 'trust_score', 'status', 'audit_summary',
            'verified_facts', 'conflicts_detected', 'curated_knowledge_snippet'
        ];
        
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $decoded);
        }
        
        $this->assertCount(count($requiredKeys), $decoded);
    }

    /**
     * @test
     * Hard constraint: null handling - keys present with null values
     */
    public function testNullHandling(): void
    {
        $rawDossier = [
            'claims' => [
                ['attribute' => 'full_name', 'value' => 'John Doe', 'source' => 'https://gov.registry/candidate/123'],
            ],
        ];
        
        $result = $this->engine->verify($rawDossier, 'candidate_123');
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true);
        
        // All keys must exist
        $this->assertArrayHasKey('curated_knowledge_snippet', $decoded);
        // Value can be null or string but key must exist
    }

    /**
     * @test
     * Hard constraint: pure JSON - no markdown
     */
    public function testPureJsonNoMarkdown(): void
    {
        $rawDossier = [
            'claims' => [
                ['attribute' => 'full_name', 'value' => 'John Doe', 'source' => 'https://gov.registry/candidate/123'],
            ],
        ];
        
        $result = $this->engine->verify($rawDossier, 'candidate_123');
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        
        $this->assertStringNotContainsString('```', $json);
        $this->assertStringNotContainsString('markdown', strtolower($json));
    }
}
