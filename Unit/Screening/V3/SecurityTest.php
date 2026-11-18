<?php

namespace Tests\Unit\Screening\V3;

use Tests\TestCase;
use App\Services\Talent\Screening\V3\Security\ScreeningSecurityService;
use InvalidArgumentException;

/**
 * Tests for ScreeningSecurityService.
 */
class SecurityTest extends TestCase
{
    private ScreeningSecurityService $security;

    protected function setUp(): void
    {
        parent::setUp();
        $this->security = app(ScreeningSecurityService::class);
    }

    /**
     * @test
     * Valid input should pass validation
     */
    public function testValidInputPasses(): void
    {
        $dossier = [
            'claims' => [
                ['attribute' => 'full_name', 'value' => 'John Doe', 'source' => 'https://gov.registry/candidate/123'],
                ['attribute' => 'title', 'value' => 'Developer', 'source' => 'https://linkedin.com/in/johndoe'],
            ],
        ];
        
        $result = $this->security->validateAndSanitizeInput($dossier, 'candidate_123');
        
        $this->assertCount(2, $result['claims']);
        $this->assertEquals('John Doe', $result['claims'][0]['value']);
    }

    /**
     * @test
     * Oversized dossier should be rejected
     */
    public function testOversizedDossierRejected(): void
    {
        // Create a large dossier
        $claims = [];
        for ($i = 0; $i < 600; $i++) {
            $claims[] = [
                'attribute' => "attr_$i",
                'value' => str_repeat('x', 2000),
                'source' => 'https://example.com/source/' . $i,
            ];
        }
        
        $dossier = ['claims' => $claims];
        
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Too many claims');
        
        $this->security->validateAndSanitizeInput($dossier, 'test');
    }

    /**
     * @test
     * Oversized claim value should be rejected
     */
    public function testOversizedClaimValueRejected(): void
    {
        $dossier = [
            'claims' => [
                ['attribute' => 'full_name', 'value' => str_repeat('x', 15000), 'source' => 'https://example.com'],
            ],
        ];
        
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum length');
        
        $this->security->validateAndSanitizeInput($dossier, 'test');
    }

    /**
     * @test
     * Oversized entity_id should be rejected
     */
    public function testOversizedEntityIdRejected(): void
    {
        $dossier = ['claims' => [['attribute' => 'test', 'value' => 'test', 'source' => 'https://example.com']]];
        $entityId = str_repeat('x', 300);
        
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum length');
        
        $this->security->validateAndSanitizeInput($dossier, $entityId);
    }

    /**
     * @test
     * Invalid source URL should be rejected
     */
    public function testInvalidSourceUrlRejected(): void
    {
        $dossier = [
            'claims' => [
                ['attribute' => 'full_name', 'value' => 'John', 'source' => 'not-a-url'],
            ],
        ];
        
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid URL');
        
        $this->security->validateAndSanitizeInput($dossier, 'test');
    }

    /**
     * @test
     * Control characters should be stripped
     */
    public function testControlCharactersStripped(): void
    {
        $dossier = [
            'claims' => [
                ['attribute' => 'full_name', 'value' => "John\x00Doe\x1F", 'source' => 'https://example.com'],
            ],
        ];
        
        $result = $this->security->validateAndSanitizeInput($dossier, 'test');
        
        $this->assertEquals('JohnDoe', $result['claims'][0]['value']);
    }

    /**
     * @test
     * PII redaction should work for emails
     */
    public function testPiiRedactionEmail(): void
    {
        $text = 'Contact me at john.doe@example.com for details';
        $redacted = $this->security->redactPii($text);
        
        $this->assertStringContainsString('[REDACTED_EMAIL]', $redacted);
        $this->assertStringNotContainsString('john.doe@example.com', $redacted);
    }

    /**
     * @test
     * PII redaction should work for phones
     */
    public function testPiiRedactionPhone(): void
    {
        $text = 'Call +1-555-123-4567 now';
        $redacted = $this->security->redactPii($text);
        
        $this->assertStringContainsString('[REDACTED_PHONE]', $redacted);
    }

    /**
     * @test
     * PII redaction should work recursively on arrays
     */
    public function testPiiRedactionArray(): void
    {
        $data = [
            'email' => 'test@example.com',
            'nested' => [
                'phone' => '+1-555-123-4567',
                'name' => 'John',
            ],
        ];
        
        $redacted = $this->security->redactPiiArray($data);
        
        $this->assertStringContainsString('[REDACTED_EMAIL]', $redacted['email']);
        $this->assertStringContainsString('[REDACTED_PHONE]', $redacted['nested']['phone']);
        $this->assertEquals('John', $redacted['nested']['name']);
    }

    /**
     * @test
     * Hedging word detection should work
     */
    public function testHedgingDetection(): void
    {
        $text = 'This is probably true and likely correct';
        $found = $this->security->containsHedging($text);
        
        $this->assertContains('probably', $found);
        $this->assertContains('likely', $found);
    }

    /**
     * @test
     * Clean text should have no hedging
     */
    public function testCleanTextNoHedging(): void
    {
        $text = 'John Doe is a Senior Developer with 5 years experience';
        $found = $this->security->containsHedging($text);
        
        $this->assertEmpty($found);
    }

    /**
     * @test
     * Safe JSON decode should enforce size limit
     */
    public function testSafeJsonDecodeSizeLimit(): void
    {
        $largeJson = json_encode(['data' => str_repeat('x', 2_000_000)]);
        
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too large');
        
        $this->security->safeJsonDecode($largeJson);
    }

    /**
     * @test
     * Rate limit key should be consistent
     */
    public function testRateLimitKey(): void
    {
        $key1 = $this->security->getRateLimitKey('entity_123');
        $key2 = $this->security->getRateLimitKey('entity_123');
        $key3 = $this->security->getRateLimitKey('entity_456');
        
        $this->assertEquals($key1, $key2);
        $this->assertNotEquals($key1, $key3);
        $this->assertStringStartsWith('screening:v3:', $key1);
    }

    /**
     * @test
     * Missing claims array should be rejected
     */
    public function testMissingClaimsRejected(): void
    {
        $dossier = ['other_key' => 'value'];
        
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain "claims" array');
        
        $this->security->validateAndSanitizeInput($dossier, 'test');
    }
}
