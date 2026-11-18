<?php

namespace Tests\Unit\Screening\V3;

use Tests\TestCase;
use App\Services\Talent\Screening\V3\Contracts\ScoringInterface;

/**
 * Red Phase: Tests for Scoring & Status Logic
 */
class ScoringTest extends TestCase
{
    private ScoringInterface $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = app(ScoringInterface::class);
    }

    /**
     * @test
     * Trust score = weighted average of verified facts
     */
    public function testTrustScoreWeightedAverage(): void
    {
        $verifiedFacts = [
            ['attribute' => 'full_name', 'value' => 'John Doe', 'authority_weight' => 1.0, 'supporting_sources' => ['src1']],
            ['attribute' => 'title', 'value' => 'Senior Developer', 'authority_weight' => 0.8, 'supporting_sources' => ['src2', 'src3']],
            ['attribute' => 'company', 'value' => 'Tech Corp', 'authority_weight' => 0.8, 'supporting_sources' => ['src2', 'src4']],
        ];
        
        $conflicts = [];
        
        $result = $this->scorer->calculate($verifiedFacts, $conflicts);
        
        // (1.0 + 0.8 + 0.8) / 3 * 100 = 86.67 -> 87
        $this->assertEquals(87, $result['trust_score']);
        $this->assertEquals('APPROVED', $result['status']);
    }

    /**
     * @test
     * Score < 40 with no conflicts -> REJECTED
     */
    public function testScoreBelow40Rejected(): void
    {
        $verifiedFacts = [
            ['attribute' => 'full_name', 'value' => 'Unknown', 'authority_weight' => 0.1, 'supporting_sources' => ['src1']],
        ];
        
        $conflicts = [];
        
        $result = $this->scorer->calculate($verifiedFacts, $conflicts);
        
        $this->assertLessThan(40, $result['trust_score']);
        $this->assertEquals('REJECTED', $result['status']);
    }

    /**
     * @test
     * Score 40-75 -> NEEDS_MANUAL_AUDIT
     */
    public function testScore40To75NeedsAudit(): void
    {
        $verifiedFacts = [
            ['attribute' => 'full_name', 'value' => 'Test', 'authority_weight' => 0.4, 'supporting_sources' => ['src1']],
            ['attribute' => 'title', 'value' => 'Dev', 'authority_weight' => 0.4, 'supporting_sources' => ['src2']],
        ];
        
        $conflicts = [];
        
        $result = $this->scorer->calculate($verifiedFacts, $conflicts);
        
        $this->assertGreaterThanOrEqual(40, $result['trust_score']);
        $this->assertLessThanOrEqual(75, $result['trust_score']);
        $this->assertEquals('NEEDS_MANUAL_AUDIT', $result['status']);
    }

    /**
     * @test
     * Score > 75 with no key conflicts -> APPROVED
     */
    public function testScoreAbove75Approved(): void
    {
        $verifiedFacts = [
            ['attribute' => 'full_name', 'value' => 'John', 'authority_weight' => 1.0, 'supporting_sources' => ['src1']],
            ['attribute' => 'title', 'value' => 'Senior', 'authority_weight' => 1.0, 'supporting_sources' => ['src2']],
            ['attribute' => 'company', 'value' => 'Corp', 'authority_weight' => 1.0, 'supporting_sources' => ['src3']],
        ];
        
        $conflicts = [];
        
        $result = $this->scorer->calculate($verifiedFacts, $conflicts);
        
        $this->assertGreaterThan(75, $result['trust_score']);
        $this->assertEquals('APPROVED', $result['status']);
    }

    /**
     * @test
     * Score > 75 but identity conflict -> REJECTED
     */
    public function testIdentityConflictRejects(): void
    {
        $verifiedFacts = [
            ['attribute' => 'full_name', 'value' => 'John', 'authority_weight' => 1.0, 'supporting_sources' => ['src1']],
        ];
        
        $conflicts = [
            ['attribute' => 'identity', 'rejected_value' => 'Jane', 'accepted_value' => 'John', 'rationale' => 'Identity conflict'],
        ];
        
        $result = $this->scorer->calculate($verifiedFacts, $conflicts);
        
        $this->assertEquals('REJECTED', $result['status']);
    }

    /**
     * @test
     * Score > 75 but equal-weight conflict in key field -> NEEDS_MANUAL_AUDIT
     */
    public function testKeyFieldEqualWeightConflictNeedsAudit(): void
    {
        $verifiedFacts = [
            ['attribute' => 'full_name', 'value' => 'John', 'authority_weight' => 1.0, 'supporting_sources' => ['src1']],
            ['attribute' => 'title', 'value' => 'Senior', 'authority_weight' => 1.0, 'supporting_sources' => ['src2']],
            ['attribute' => 'company', 'value' => 'Corp', 'authority_weight' => 1.0, 'supporting_sources' => ['src3']],
        ];
        
        $conflicts = [
            ['attribute' => 'company', 'rejected_value' => 'Other Corp', 'accepted_value' => 'Corp', 'rationale' => 'Equal weight conflict'],
        ];
        
        $result = $this->scorer->calculate($verifiedFacts, $conflicts);
        
        $this->assertEquals('NEEDS_MANUAL_AUDIT', $result['status']);
    }

    /**
     * @test
     * Boundary: 39 -> REJECTED
     */
    public function testBoundary39Rejected(): void
    {
        $verifiedFacts = [
            ['attribute' => 'full_name', 'value' => 'Test', 'authority_weight' => 0.39, 'supporting_sources' => ['src1']],
        ];
        
        $conflicts = [];
        
        $result = $this->scorer->calculate($verifiedFacts, $conflicts);
        
        $this->assertEquals(39, $result['trust_score']);
        $this->assertEquals('REJECTED', $result['status']);
    }

    /**
     * @test
     * Boundary: 40 -> NEEDS_MANUAL_AUDIT
     */
    public function testBoundary40NeedsAudit(): void
    {
        $verifiedFacts = [
            ['attribute' => 'full_name', 'value' => 'Test', 'authority_weight' => 0.4, 'supporting_sources' => ['src1']],
        ];
        
        $conflicts = [];
        
        $result = $this->scorer->calculate($verifiedFacts, $conflicts);
        
        $this->assertEquals(40, $result['trust_score']);
        $this->assertEquals('NEEDS_MANUAL_AUDIT', $result['status']);
    }

    /**
     * @test
     * Boundary: 75 -> NEEDS_MANUAL_AUDIT
     */
    public function testBoundary75NeedsAudit(): void
    {
        $verifiedFacts = [
            ['attribute' => 'full_name', 'value' => 'Test', 'authority_weight' => 0.75, 'supporting_sources' => ['src1']],
        ];
        
        $conflicts = [];
        
        $result = $this->scorer->calculate($verifiedFacts, $conflicts);
        
        $this->assertEquals(75, $result['trust_score']);
        $this->assertEquals('NEEDS_MANUAL_AUDIT', $result['status']);
    }

    /**
     * @test
     * Boundary: 76 -> APPROVED
     */
    public function testBoundary76Approved(): void
    {
        $verifiedFacts = [
            ['attribute' => 'full_name', 'value' => 'Test', 'authority_weight' => 0.76, 'supporting_sources' => ['src1']],
        ];
        
        $conflicts = [];
        
        $result = $this->scorer->calculate($verifiedFacts, $conflicts);
        
        $this->assertEquals(76, $result['trust_score']);
        $this->assertEquals('APPROVED', $result['status']);
    }
}
