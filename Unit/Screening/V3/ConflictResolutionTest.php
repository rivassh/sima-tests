<?php

namespace Tests\Unit\Screening\V3;

use Tests\TestCase;
use App\Services\Talent\Screening\V3\Contracts\ConflictResolutionInterface;

/**
 * Red Phase: Tests for Conflict Resolution
 */
class ConflictResolutionTest extends TestCase
{
    private ConflictResolutionInterface $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(ConflictResolutionInterface::class);
    }

    /**
     * @test
     * Higher weight source should win
     */
    public function testHigherWeightWins(): void
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
                'value' => 'Junior Developer',
                'source_ref' => 'https://linkedin.com/in/johndoe',
                'source_tier' => 2,
                'authority_weight' => 0.8,
            ],
        ];
        
        $result = $this->resolver->resolve($claims);
        
        $this->assertCount(1, $result['verified']);
        $this->assertEquals('Senior Developer', $result['verified'][0]['value']);
        $this->assertCount(1, $result['conflicts']);
        $this->assertEquals('title', $result['conflicts'][0]['attribute']);
        $this->assertEquals('Junior Developer', $result['conflicts'][0]['rejected_value']);
        $this->assertEquals('Senior Developer', $result['conflicts'][0]['accepted_value']);
    }

    /**
     * @test
     * Equal weight sources should create conflict for manual audit
     */
    public function testEqualWeightCreatesConflict(): void
    {
        $claims = [
            [
                'attribute' => 'experience_years',
                'value' => '5',
                'source_ref' => 'https://linkedin.com/in/johndoe',
                'source_tier' => 2,
                'authority_weight' => 0.8,
            ],
            [
                'attribute' => 'experience_years',
                'value' => '3',
                'source_ref' => 'https://github.com/johndoe',
                'source_tier' => 2,
                'authority_weight' => 0.8,
            ],
        ];
        
        $result = $this->resolver->resolve($claims);
        
        // One value is kept (first), but conflict is recorded
        $this->assertCount(1, $result['verified']);
        $this->assertCount(1, $result['conflicts']);
        $this->assertEquals('experience_years', $result['conflicts'][0]['attribute']);
        $this->assertStringContainsString('equal', strtolower($result['conflicts'][0]['rationale']));
    }

    /**
     * @test
     * Identity conflict should be flagged as critical
     */
    public function testIdentityConflictFlagged(): void
    {
        $claims = [
            [
                'attribute' => 'identity',
                'value' => 'John Doe',
                'source_ref' => 'https://gov.registry/candidate/123',
                'source_tier' => 1,
                'authority_weight' => 1.0,
            ],
            [
                'attribute' => 'identity',
                'value' => 'Jane Smith',
                'source_ref' => 'https://linkedin.com/in/janedoe',
                'source_tier' => 2,
                'authority_weight' => 0.8,
            ],
        ];
        
        $result = $this->resolver->resolve($claims);
        
        $this->assertCount(1, $result['conflicts']);
        $this->assertEquals('identity', $result['conflicts'][0]['attribute']);
        $this->assertStringContainsString('identity', strtolower($result['conflicts'][0]['rationale']));
    }

    /**
     * @test
     * No conflicts when single claim
     */
    public function testNoConflictsSingleClaim(): void
    {
        $claims = [
            [
                'attribute' => 'title',
                'value' => 'Senior Developer',
                'source_ref' => 'https://gov.registry/candidate/123',
                'source_tier' => 1,
                'authority_weight' => 1.0,
            ],
        ];
        
        $result = $this->resolver->resolve($claims);
        
        $this->assertCount(1, $result['verified']);
        $this->assertCount(0, $result['conflicts']);
    }

    /**
     * @test
     * Multiple attributes with mixed conflicts
     */
    public function testMultipleAttributesMixedConflicts(): void
    {
        $claims = [
            [
                'attribute' => 'full_name',
                'value' => 'John Doe',
                'source_ref' => 'https://gov.registry/candidate/123',
                'source_tier' => 1,
                'authority_weight' => 1.0,
            ],
            [
                'attribute' => 'title',
                'value' => 'Senior Developer',
                'source_ref' => 'https://gov.registry/candidate/123',
                'source_tier' => 1,
                'authority_weight' => 1.0,
            ],
            [
                'attribute' => 'title',
                'value' => 'Junior Developer',
                'source_ref' => 'https://linkedin.com/in/johndoe',
                'source_tier' => 2,
                'authority_weight' => 0.8,
            ],
        ];
        
        $result = $this->resolver->resolve($claims);
        
        $this->assertCount(2, $result['verified']); // full_name + title (tier-1 wins)
        $this->assertCount(1, $result['conflicts']); // title conflict
    }
}
