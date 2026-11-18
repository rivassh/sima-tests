<?php

namespace Tests\Unit\Screening\V3;

use Tests\TestCase;
use App\Services\Talent\Screening\V3\Contracts\CrossValidationInterface;

/**
 * Red Phase: Tests for Cross-Validation + Tier-4 Filtering
 */
class CrossValidationTest extends TestCase
{
    private CrossValidationInterface $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = app(CrossValidationInterface::class);
    }

    /**
     * @test
     * Claim with only tier-4 sources should be discarded
     */
    public function testTier4OnlyDiscarded(): void
    {
        $claims = [
            [
                'attribute' => 'title',
                'value' => 'Developer',
                'source_ref' => 'https://spam-site.example/fake',
                'source_tier' => 4,
                'authority_weight' => 0.1,
            ],
        ];
        
        $result = $this->validator->validate($claims);
        $this->assertEmpty($result);
    }

    /**
     * @test
     * Claim with >=1 tier-1 source should be verified
     */
    public function testTier1SourceVerified(): void
    {
        $claims = [
            [
                'attribute' => 'full_name',
                'value' => 'John Doe',
                'source_ref' => 'https://gov.registry/candidate/123',
                'source_tier' => 1,
                'authority_weight' => 1.0,
            ],
        ];
        
        $result = $this->validator->validate($claims);
        $this->assertCount(1, $result);
        $this->assertEquals('full_name', $result[0]['attribute']);
        $this->assertEquals(1.0, $result[0]['authority_weight']);
    }

    /**
     * @test
     * Claim with >=2 independent tier-2 sources should be verified
     */
    public function testTwoIndependentTier2SourcesVerified(): void
    {
        $claims = [
            [
                'attribute' => 'title',
                'value' => 'Senior Developer',
                'source_ref' => 'https://linkedin.com/in/johndoe',
                'source_tier' => 2,
                'authority_weight' => 0.8,
            ],
            [
                'attribute' => 'title',
                'value' => 'Senior Developer',
                'source_ref' => 'https://github.com/johndoe',
                'source_tier' => 2,
                'authority_weight' => 0.8,
            ],
        ];
        
        $result = $this->validator->validate($claims);
        $this->assertCount(1, $result);
        $this->assertEquals('title', $result[0]['attribute']);
    }

    /**
     * @test
     * Single tier-2 source should NOT be verified
     */
    public function testSingleTier2NotVerified(): void
    {
        $claims = [
            [
                'attribute' => 'title',
                'value' => 'Senior Developer',
                'source_ref' => 'https://linkedin.com/in/johndoe',
                'source_tier' => 2,
                'authority_weight' => 0.8,
            ],
        ];
        
        $result = $this->validator->validate($claims);
        $this->assertEmpty($result);
    }

    /**
     * @test
     * Same domain tier-2 sources should NOT count as independent
     */
    public function testSameDomainTier2NotIndependent(): void
    {
        $claims = [
            [
                'attribute' => 'title',
                'value' => 'Senior Developer',
                'source_ref' => 'https://linkedin.com/in/johndoe',
                'source_tier' => 2,
                'authority_weight' => 0.8,
            ],
            [
                'attribute' => 'title',
                'value' => 'Senior Developer',
                'source_ref' => 'https://linkedin.com/in/janedoe',
                'source_tier' => 2,
                'authority_weight' => 0.8,
            ],
        ];
        
        $result = $this->validator->validate($claims);
        $this->assertEmpty($result);
    }

    /**
     * @test
     * Tier-3 sources alone should not verify
     */
    public function testTier3AloneNotVerified(): void
    {
        $claims = [
            [
                'attribute' => 'title',
                'value' => 'Developer',
                'source_ref' => 'https://blog.example.com/post',
                'source_tier' => 3,
                'authority_weight' => 0.4,
            ],
        ];
        
        $result = $this->validator->validate($claims);
        $this->assertEmpty($result);
    }

    /**
     * @test
     * Mixed tier-1 and tier-4 should verify (tier-1 wins)
     */
    public function testMixedTier1AndTier4Verified(): void
    {
        $claims = [
            [
                'attribute' => 'title',
                'value' => 'Senior Developer',
                'source_ref' => 'https://gov.registry/candidate/123',
                'source_tier' => 1,
                'authority_weight' => 1.0,
            ],
            [
                'attribute' => 'title',
                'value' => 'Junior',
                'source_ref' => 'https://spam-site.example/fake',
                'source_tier' => 4,
                'authority_weight' => 0.1,
            ],
        ];
        
        $result = $this->validator->validate($claims);
        $this->assertCount(1, $result);
        $this->assertEquals('Senior Developer', $result[0]['value']);
    }
}
