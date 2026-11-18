<?php

namespace Tests\Unit\Screening\V3;

use Tests\TestCase;
use App\Services\Talent\Screening\V3\Contracts\FactIsolationInterface;

/**
 * Red Phase: Tests for Fact Isolation into Atomic Claims
 * These tests MUST FAIL initially because the implementation doesn't exist.
 */
class FactIsolationTest extends TestCase
{
    private FactIsolationInterface $isolator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolator = app(FactIsolationInterface::class);
    }

    /**
     * @test
     * Empty input should return empty array
     */
    public function testEmptyInputReturnsEmptyArray(): void
    {
        $claims = $this->isolator->isolate([]);
        $this->assertIsArray($claims);
        $this->assertEmpty($claims);
    }

    /**
     * @test
     * Should isolate title claim from dossier
     */
    public function testIsolateTitleClaim(): void
    {
        $dossier = [
            'claims' => [
                [
                    'attribute' => 'title',
                    'value' => 'Senior PHP Developer',
                    'source' => 'https://linkedin.com/in/johndoe',
                ],
            ],
        ];
        
        $claims = $this->isolator->isolate($dossier);
        $this->assertCount(1, $claims);
        $this->assertEquals('title', $claims[0]['attribute']);
        $this->assertEquals('Senior PHP Developer', $claims[0]['value']);
        $this->assertEquals('https://linkedin.com/in/johndoe', $claims[0]['source_ref']);
    }

    /**
     * @test
     * Should isolate experience claim from dossier
     */
    public function testIsolateExperienceClaim(): void
    {
        $dossier = [
            'claims' => [
                [
                    'attribute' => 'experience_years',
                    'value' => '5',
                    'source' => 'https://gov.registry/candidate/123',
                ],
            ],
        ];
        
        $claims = $this->isolator->isolate($dossier);
        $this->assertCount(1, $claims);
        $this->assertEquals('experience_years', $claims[0]['attribute']);
        $this->assertEquals('5', $claims[0]['value']);
    }

    /**
     * @test
     * Should isolate skills claim from dossier
     */
    public function testIsolateSkillsClaim(): void
    {
        $dossier = [
            'claims' => [
                [
                    'attribute' => 'skills',
                    'value' => 'PHP, Laravel, MySQL',
                    'source' => 'https://github.com/johndoe',
                ],
            ],
        ];
        
        $claims = $this->isolator->isolate($dossier);
        $this->assertCount(1, $claims);
        $this->assertEquals('skills', $claims[0]['attribute']);
    }

    /**
     * @test
     * Should isolate company claim from dossier
     */
    public function testIsolateCompanyClaim(): void
    {
        $dossier = [
            'claims' => [
                [
                    'attribute' => 'company',
                    'value' => 'Tech Corp',
                    'source' => 'https://linkedin.com/in/johndoe',
                ],
            ],
        ];
        
        $claims = $this->isolator->isolate($dossier);
        $this->assertCount(1, $claims);
        $this->assertEquals('company', $claims[0]['attribute']);
        $this->assertEquals('Tech Corp', $claims[0]['value']);
    }

    /**
     * @test
     * Should isolate multiple claims from dossier
     */
    public function testIsolateMultipleClaims(): void
    {
        $dossier = [
            'claims' => [
                ['attribute' => 'title', 'value' => 'Senior Developer', 'source' => 'https://linkedin.com/in/johndoe'],
                ['attribute' => 'company', 'value' => 'Tech Corp', 'source' => 'https://linkedin.com/in/johndoe'],
                ['attribute' => 'skills', 'value' => 'PHP, Laravel', 'source' => 'https://github.com/johndoe'],
            ],
        ];
        
        $claims = $this->isolator->isolate($dossier);
        $this->assertCount(3, $claims);
    }

    /**
     * @test
     * Malformed source should be classified as tier 4
     */
    public function testMalformedSourceClassifiedAsTier4(): void
    {
        $dossier = [
            'claims' => [
                [
                    'attribute' => 'title',
                    'value' => 'Developer',
                    'source' => 'not-a-valid-url',
                ],
            ],
        ];
        
        $claims = $this->isolator->isolate($dossier);
        $this->assertCount(1, $claims);
        $this->assertEquals(4, $claims[0]['source_tier']);
        $this->assertEquals(0.1, $claims[0]['authority_weight']);
    }

    /**
     * @test
     * Missing source should be classified as tier 4
     */
    public function testMissingSourceClassifiedAsTier4(): void
    {
        $dossier = [
            'claims' => [
                [
                    'attribute' => 'title',
                    'value' => 'Developer',
                ],
            ],
        ];
        
        $claims = $this->isolator->isolate($dossier);
        $this->assertCount(1, $claims);
        $this->assertEquals(4, $claims[0]['source_tier']);
    }

    /**
     * @test
     * Each claim should have required fields
     */
    public function testClaimHasRequiredFields(): void
    {
        $dossier = [
            'claims' => [
                [
                    'attribute' => 'title',
                    'value' => 'Senior Developer',
                    'source' => 'https://linkedin.com/in/johndoe',
                ],
            ],
        ];
        
        $claims = $this->isolator->isolate($dossier);
        $claim = $claims[0];
        
        $this->assertArrayHasKey('attribute', $claim);
        $this->assertArrayHasKey('value', $claim);
        $this->assertArrayHasKey('source_ref', $claim);
        $this->assertArrayHasKey('source_tier', $claim);
        $this->assertArrayHasKey('authority_weight', $claim);
    }
}
