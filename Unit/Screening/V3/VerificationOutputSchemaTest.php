<?php

namespace Tests\Unit\Screening\V3;

use Tests\TestCase;
use App\Services\Talent\Screening\V3\DTO\VerificationOutputDTO;
use App\Services\Talent\Screening\V3\DTO\AuditSummaryDTO;
use App\Services\Talent\Screening\V3\DTO\VerifiedFactDTO;
use App\Services\Talent\Screening\V3\DTO\ConflictDetectedDTO;
use JsonException;

/**
 * Red Phase: Tests for v3.0 Verification Output Schema Contract
 * These tests MUST FAIL initially because the implementation doesn't exist.
 */
class VerificationOutputSchemaTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesPath = base_path('tests/fixtures/Screening/V3');
    }

    /**
     * @test
     * Valid minimal output should pass schema validation
     */
    public function testValidMinimalOutputPassesSchema(): void
    {
        $json = file_get_contents($this->fixturesPath . '/valid_minimal.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $dto = VerificationOutputDTO::fromArray($data);
        $this->assertInstanceOf(VerificationOutputDTO::class, $dto);
        $this->assertEquals('candidate_123', $dto->entity_id);
        $this->assertEquals(85, $dto->trust_score);
        $this->assertEquals('APPROVED', $dto->status);
        $this->assertCount(2, $dto->verified_facts);
        $this->assertCount(0, $dto->conflicts_detected);
    }

    /**
     * @test
     * Valid output with conflicts should pass schema validation
     */
    public function testValidOutputWithConflictsPassesSchema(): void
    {
        $json = file_get_contents($this->fixturesPath . '/valid_with_conflicts.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $dto = VerificationOutputDTO::fromArray($data);
        $this->assertEquals('NEEDS_MANUAL_AUDIT', $dto->status);
        $this->assertEquals(65, $dto->trust_score);
        $this->assertCount(2, $dto->conflicts_detected);
        $this->assertEquals('title', $dto->conflicts_detected[0]->attribute);
    }

    /**
     * @test
     * Valid REJECTED output should pass schema validation
     */
    public function testValidRejectedOutputPassesSchema(): void
    {
        $json = file_get_contents($this->fixturesPath . '/valid_rejected.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $dto = VerificationOutputDTO::fromArray($data);
        $this->assertEquals('REJECTED', $dto->status);
        $this->assertEquals(35, $dto->trust_score);
        $this->assertCount(1, $dto->conflicts_detected);
        $this->assertEquals('identity', $dto->conflicts_detected[0]->attribute);
    }

    /**
     * @test
     * Boundary: trust_score = 0 should be valid
     */
    public function testBoundaryTrustScoreZero(): void
    {
        $json = file_get_contents($this->fixturesPath . '/valid_boundary_trust_score_0.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $dto = VerificationOutputDTO::fromArray($data);
        $this->assertEquals(0, $dto->trust_score);
        $this->assertEquals('REJECTED', $dto->status);
    }

    /**
     * @test
     * Boundary: trust_score = 40 should be NEEDS_MANUAL_AUDIT
     */
    public function testBoundaryTrustScore40(): void
    {
        $json = file_get_contents($this->fixturesPath . '/valid_boundary_trust_score_40.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $dto = VerificationOutputDTO::fromArray($data);
        $this->assertEquals(40, $dto->trust_score);
        $this->assertEquals('NEEDS_MANUAL_AUDIT', $dto->status);
    }

    /**
     * @test
     * Boundary: trust_score = 75 should be NEEDS_MANUAL_AUDIT
     */
    public function testBoundaryTrustScore75(): void
    {
        $json = file_get_contents($this->fixturesPath . '/valid_boundary_trust_score_75.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $dto = VerificationOutputDTO::fromArray($data);
        $this->assertEquals(75, $dto->trust_score);
        $this->assertEquals('NEEDS_MANUAL_AUDIT', $dto->status);
    }

    /**
     * @test
     * Boundary: trust_score = 76 should be APPROVED
     */
    public function testBoundaryTrustScore76(): void
    {
        $json = file_get_contents($this->fixturesPath . '/valid_boundary_trust_score_76.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $dto = VerificationOutputDTO::fromArray($data);
        $this->assertEquals(76, $dto->trust_score);
        $this->assertEquals('APPROVED', $dto->status);
    }

    /**
     * @test
     * Boundary: trust_score = 100 should be valid
     */
    public function testBoundaryTrustScore100(): void
    {
        $json = file_get_contents($this->fixturesPath . '/valid_boundary_trust_score_100.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $dto = VerificationOutputDTO::fromArray($data);
        $this->assertEquals(100, $dto->trust_score);
        $this->assertEquals('APPROVED', $dto->status);
    }

    /**
     * @test
     * Schema invariance: extra keys should be rejected
     */
    public function testSchemaInvarianceRejectsExtraKeys(): void
    {
        $json = file_get_contents($this->fixturesPath . '/invalid_extra_key.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $this->expectException(\Exception::class);
        VerificationOutputDTO::fromArray($data);
    }

    /**
     * @test
     * Schema invariance: missing required keys should be rejected
     */
    public function testSchemaInvarianceRejectsMissingKeys(): void
    {
        $json = file_get_contents($this->fixturesPath . '/invalid_missing_key.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $this->expectException(\Exception::class);
        VerificationOutputDTO::fromArray($data);
    }

    /**
     * @test
     * Null handling: null values should be allowed but keys must exist
     */
    public function testNullHandlingKeysNotOmitted(): void
    {
        $data = [
            'entity_id' => 'test',
            'trust_score' => 50,
            'status' => 'NEEDS_MANUAL_AUDIT',
            'audit_summary' => [
                'total_claims_found' => 0,
                'verified_claims_count' => 0,
                'conflicts_count' => 0,
                'dominant_source' => 'none',
            ],
            'verified_facts' => [],
            'conflicts_detected' => [],
            'curated_knowledge_snippet' => null,
        ];
        
        $dto = VerificationOutputDTO::fromArray($data);
        $json = json_encode($dto, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true);
        
        $this->assertArrayHasKey('curated_knowledge_snippet', $decoded);
        $this->assertNull($decoded['curated_knowledge_snippet']);
    }

    /**
     * @test
     * Pure JSON: output must not contain markdown formatting
     */
    public function testPureJsonNoMarkdown(): void
    {
        $json = file_get_contents($this->fixturesPath . '/valid_minimal.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $dto = VerificationOutputDTO::fromArray($data);
        $output = json_encode($dto, JSON_THROW_ON_ERROR);
        
        $this->assertStringNotContainsString('```', $output);
        $this->assertStringNotContainsString('markdown', strtolower($output));
    }

    /**
     * @test
     * Banned hedging words should be detected in snippet
     */
    public function testBannedHedgingWordsDetected(): void
    {
        $json = file_get_contents($this->fixturesPath . '/invalid_hedging_words.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $dto = VerificationOutputDTO::fromArray($data);
        $snippet = $dto->curated_knowledge_snippet;
        
        $hedgingWords = [
            'probably', 'likely', 'possibly', 'may', 'might', 'could',
            'appears', 'seems', 'suggests', 'indicates', 'presumably',
            'allegedly', 'reportedly', 'supposedly'
        ];
        
        $foundHedging = [];
        foreach ($hedgingWords as $word) {
            if (stripos($snippet, $word) !== false) {
                $foundHedging[] = $word;
            }
        }
        
        $this->assertNotEmpty($foundHedging, 'Hedging words should be detected in invalid fixture');
        $this->assertContains('probably', $foundHedging);
        $this->assertContains('likely', $foundHedging);
    }

    /**
     * @test
     * Valid fixture should not contain hedging words
     */
    public function testValidFixtureNoHedgingWords(): void
    {
        $json = file_get_contents($this->fixturesPath . '/valid_minimal.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $dto = VerificationOutputDTO::fromArray($data);
        $snippet = $dto->curated_knowledge_snippet;
        
        $hedgingWords = [
            'probably', 'likely', 'possibly', 'may', 'might', 'could',
            'appears', 'seems', 'suggests', 'indicates', 'presumably',
            'allegedly', 'reportedly', 'supposedly'
        ];
        
        foreach ($hedgingWords as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $snippet);
        }
    }

    /**
     * @test
     * Invalid trust_score negative should be rejected
     */
    public function testInvalidTrustScoreNegative(): void
    {
        $json = file_get_contents($this->fixturesPath . '/invalid_trust_score_negative.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $this->expectException(\Exception::class);
        VerificationOutputDTO::fromArray($data);
    }

    /**
     * @test
     * Invalid trust_score over 100 should be rejected
     */
    public function testInvalidTrustScoreOver100(): void
    {
        $json = file_get_contents($this->fixturesPath . '/invalid_trust_score_over_100.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $this->expectException(\Exception::class);
        VerificationOutputDTO::fromArray($data);
    }

    /**
     * @test
     * Invalid status enum should be rejected
     */
    public function testInvalidStatusEnum(): void
    {
        $json = file_get_contents($this->fixturesPath . '/invalid_status_enum.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        $this->expectException(\Exception::class);
        VerificationOutputDTO::fromArray($data);
    }
}
