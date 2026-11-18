<?php

namespace Tests\Unit\Screening\V3;

use Tests\TestCase;
use App\Services\Talent\Screening\V3\Contracts\SourceAuthorityInterface;

/**
 * Red Phase: Tests for Source Authority Hierarchy
 * These tests MUST FAIL initially because the implementation doesn't exist.
 */
class SourceAuthorityTest extends TestCase
{
    private SourceAuthorityInterface $authority;

    protected function setUp(): void
    {
        parent::setUp();
        // This will fail in Red phase because the implementation doesn't exist yet
        $this->authority = app(SourceAuthorityInterface::class);
    }

    /**
     * @test
     * Tier 1: Official hiring portals should have weight 1.0
     */
    public function testTier1OfficialPortalsWeight(): void
    {
        $tier = $this->authority->getTier('https://gov.registry/candidates/123');
        $this->assertEquals(1, $tier);
        $this->assertEquals(1.0, $this->authority->getWeight(1));
    }

    /**
     * @test
     * Tier 1: Government portals should have weight 1.0
     */
    public function testTier1GovernmentPortalsWeight(): void
    {
        $tier = $this->authority->getTier('https://labor.gov.ir/registry/456');
        $this->assertEquals(1, $tier);
        $this->assertEquals(1.0, $this->authority->getWeight(1));
    }

    /**
     * @test
     * Tier 1: Corporate registries should have weight 1.0
     */
    public function testTier1CorporateRegistriesWeight(): void
    {
        $tier = $this->authority->getTier('https://companies.gov/corp/789');
        $this->assertEquals(1, $tier);
        $this->assertEquals(1.0, $this->authority->getWeight(1));
    }

    /**
     * @test
     * Tier 2: LinkedIn profiles should have weight 0.8
     */
    public function testTier2LinkedInWeight(): void
    {
        $tier = $this->authority->getTier('https://linkedin.com/in/johndoe');
        $this->assertEquals(2, $tier);
        $this->assertEquals(0.8, $this->authority->getWeight(2));
    }

    /**
     * @test
     * Tier 2: GitHub profiles should have weight 0.8
     */
    public function testTier2GitHubWeight(): void
    {
        $tier = $this->authority->getTier('https://github.com/johndoe');
        $this->assertEquals(2, $tier);
        $this->assertEquals(0.8, $this->authority->getWeight(2));
    }

    /**
     * @test
     * Tier 2: Technical documentation should have weight 0.8
     */
    public function testTier2TechnicalDocsWeight(): void
    {
        $tier = $this->authority->getTier('https://docs.example.com/api/reference');
        $this->assertEquals(2, $tier);
        $this->assertEquals(0.8, $this->authority->getWeight(2));
    }

    /**
     * @test
     * Tier 3: Social media should have weight 0.4
     */
    public function testTier3SocialMediaWeight(): void
    {
        $tier = $this->authority->getTier('https://instagram.com/johndoe');
        $this->assertEquals(3, $tier);
        $this->assertEquals(0.4, $this->authority->getWeight(3));
    }

    /**
     * @test
     * Tier 3: Forums should have weight 0.4
     */
    public function testTier3ForumsWeight(): void
    {
        $tier = $this->authority->getTier('https://stackoverflow.com/users/123');
        $this->assertEquals(3, $tier);
        $this->assertEquals(0.4, $this->authority->getWeight(3));
    }

    /**
     * @test
     * Tier 3: Blogs should have weight 0.4
     */
    public function testTier3BlogsWeight(): void
    {
        $tier = $this->authority->getTier('https://blog.example.com/post');
        $this->assertEquals(3, $tier);
        $this->assertEquals(0.4, $this->authority->getWeight(3));
    }

    /**
     * @test
     * Tier 4: Error pages should have weight 0.1 (noise)
     */
    public function testTier4ErrorPagesWeight(): void
    {
        $tier = $this->authority->getTier('https://example.com/404');
        $this->assertEquals(4, $tier);
        $this->assertEquals(0.1, $this->authority->getWeight(4));
        $this->assertTrue($this->authority->isNoise('https://example.com/404'));
    }

    /**
     * @test
     * Tier 4: Incomplete caches should have weight 0.1 (noise)
     */
    public function testTier4IncompleteCachesWeight(): void
    {
        $tier = $this->authority->getTier('https://webcache.googleusercontent.com/partial');
        $this->assertEquals(4, $tier);
        $this->assertEquals(0.1, $this->authority->getWeight(4));
        $this->assertTrue($this->authority->isNoise('https://webcache.googleusercontent.com/partial'));
    }

    /**
     * @test
     * Tier 4: Spam/noise sources should have weight 0.1
     */
    public function testTier4SpamWeight(): void
    {
        $tier = $this->authority->getTier('https://spam-site.example/fake-profile');
        $this->assertEquals(4, $tier);
        $this->assertEquals(0.1, $this->authority->getWeight(4));
        $this->assertTrue($this->authority->isNoise('https://spam-site.example/fake-profile'));
    }

    /**
     * @test
     * Unknown domains should default to tier 4 (noise)
     */
    public function testUnknownDomainDefaultsToNoise(): void
    {
        $tier = $this->authority->getTier('https://unknown-random-domain.xyz/profile');
        $this->assertEquals(4, $tier);
        $this->assertTrue($this->authority->isNoise('https://unknown-random-domain.xyz/profile'));
    }

    /**
     * @test
     * Weight lookup for all tiers should return correct values
     */
    public function testAllTierWeights(): void
    {
        $this->assertEquals(1.0, $this->authority->getWeight(1));
        $this->assertEquals(0.8, $this->authority->getWeight(2));
        $this->assertEquals(0.4, $this->authority->getWeight(3));
        $this->assertEquals(0.1, $this->authority->getWeight(4));
    }

    /**
     * @test
     * Invalid tier should throw or return noise weight
     */
    public function testInvalidTierHandling(): void
    {
        $this->assertEquals(0.1, $this->authority->getWeight(0));
        $this->assertEquals(0.1, $this->authority->getWeight(5));
        $this->assertEquals(0.1, $this->authority->getWeight(-1));
    }
}
