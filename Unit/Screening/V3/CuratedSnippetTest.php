<?php

namespace Tests\Unit\Screening\V3;

use Tests\TestCase;
use App\Services\Talent\Screening\V3\Contracts\CuratedSnippetInterface;

/**
 * Red Phase: Tests for Curated Knowledge Snippet
 */
class CuratedSnippetTest extends TestCase
{
    private CuratedSnippetInterface $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = app(CuratedSnippetInterface::class);
    }

    /**
     * @test
     * Generate fact-only snippet from verified facts
     */
    public function testGenerateFactOnlySnippet(): void
    {
        $verifiedFacts = [
            ['attribute' => 'full_name', 'value' => 'John Doe', 'authority_weight' => 1.0, 'supporting_sources' => ['src1']],
            ['attribute' => 'title', 'value' => 'Senior PHP Developer', 'authority_weight' => 0.8, 'supporting_sources' => ['src2', 'src3']],
            ['attribute' => 'experience_years', 'value' => '5', 'authority_weight' => 0.8, 'supporting_sources' => ['src2']],
        ];
        
        $snippet = $this->generator->generate($verifiedFacts);
        
        $this->assertStringContainsString('John Doe', $snippet);
        $this->assertStringContainsString('Senior PHP Developer', $snippet);
        $this->assertStringContainsString('5', $snippet);
    }

    /**
     * @test
     * Snippet should not contain hedging words
     */
    public function testNoHedgingWords(): void
    {
        $verifiedFacts = [
            ['attribute' => 'full_name', 'value' => 'John Doe', 'authority_weight' => 1.0, 'supporting_sources' => ['src1']],
            ['attribute' => 'title', 'value' => 'Senior Developer', 'authority_weight' => 0.8, 'supporting_sources' => ['src2']],
        ];
        
        $snippet = $this->generator->generate($verifiedFacts);
        
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
     * Empty verified facts should return fallback message
     */
    public function testEmptyFactsReturnsFallback(): void
    {
        $snippet = $this->generator->generate([]);
        
        $this->assertStringContainsString('No verifiable information', $snippet);
    }

    /**
     * @test
     * Single fact should produce concise snippet
     */
    public function testSingleFactConcise(): void
    {
        $verifiedFacts = [
            ['attribute' => 'full_name', 'value' => 'Jane Smith', 'authority_weight' => 1.0, 'supporting_sources' => ['src1']],
        ];
        
        $snippet = $this->generator->generate($verifiedFacts);
        
        $this->assertStringContainsString('Jane Smith', $snippet);
        $this->assertLessThan(200, strlen($snippet));
    }

    /**
     * @test
     * Multiple facts should be combined coherently
     */
    public function testMultipleFactsCombined(): void
    {
        $verifiedFacts = [
            ['attribute' => 'full_name', 'value' => 'Alice Johnson', 'authority_weight' => 1.0, 'supporting_sources' => ['src1']],
            ['attribute' => 'title', 'value' => 'Principal Engineer', 'authority_weight' => 1.0, 'supporting_sources' => ['src2']],
            ['attribute' => 'company', 'value' => 'Tech Giant Inc', 'authority_weight' => 1.0, 'supporting_sources' => ['src3']],
            ['attribute' => 'experience_years', 'value' => '15', 'authority_weight' => 1.0, 'supporting_sources' => ['src4']],
        ];
        
        $snippet = $this->generator->generate($verifiedFacts);
        
        $this->assertStringContainsString('Alice Johnson', $snippet);
        $this->assertStringContainsString('Principal Engineer', $snippet);
        $this->assertStringContainsString('Tech Giant Inc', $snippet);
        $this->assertStringContainsString('15', $snippet);
    }
}
