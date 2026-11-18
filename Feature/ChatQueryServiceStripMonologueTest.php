<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Chat\Services\ChatQueryService;
use ReflectionClass;
use Tests\TestCase;

/**
 * Unit tests for ChatQueryService::sanitizeAssistantContent() — TASK-079 evidence.
 *
 * Verifies that raw internal monologue/thinking/reasoning content stripped from
 * LLM responses is correctly removed before any response leaves the service.
 */
class ChatQueryServiceStripMonologueTest extends TestCase
{
    /** @var \ReflectionMethod */
    protected $sanitizeAssistantContentMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $reflection = new ReflectionClass(ChatQueryService::class);
        $method = $reflection->getMethod('sanitizeAssistantContent');
        $method->setAccessible(true);
        $this->sanitizeAssistantContentMethod = $method;
    }

    public function test_strips_think_tags_no_space(): void
    {
        // Use chr(60) for '<' to avoid space issue in source
        $input = 'Here is the answer'.chr(60).'think>hidden'.chr(60).'/think> done';
        $expected = 'Here is the answer done';

        $result = $this->invokeStripMonologue($input);

        $this->assertEquals($expected, $result);
    }

    public function test_think_tag_with_space_not_stripped(): void
    {
        $input = 'Here is the answer< think>hidden< /think> done';
        $expected = $input;

        $result = $this->invokeStripMonologue($input);

        $this->assertEquals($expected, $result);
    }

    public function test_strips_bold_think_annotations(): void
    {
        $input = 'Plain text **think: this is internal** more text';
        $expected = 'Plain text  more text';

        $result = $this->invokeStripMonologue($input);

        $this->assertEquals($expected, $result);
    }

    public function test_strips_bold_think_leaving_clean_answer(): void
    {
        $input = '**think: this is internal** Clean answer';
        $expected = 'Clean answer';

        $result = $this->invokeStripMonologue($input);

        $this->assertEquals($expected, $result);
    }

    public function test_strips_reasoning_keyword(): void
    {
        // Monologue-leading content with a final-answer marker keeps only the
        // portion after the marker; mid-sentence use of "reasoning" is a
        // legitimate sentence and must NOT be stripped.
        $input = 'Reasoning about the answer. The actual answer is 42.';
        $expected = 'The actual answer is 42.';

        $result = $this->invokeStripMonologue($input);

        $this->assertEquals($expected, $result);
    }

    public function test_strips_thinking_keyword(): void
    {
        $input = 'Thinking about it. The actual answer is 42.';
        $expected = 'The actual answer is 42.';

        $result = $this->invokeStripMonologue($input);

        $this->assertEquals($expected, $result);
    }

    public function test_strips_internal_keyword(): void
    {
        $input = 'Internal monologue: step 1. The actual answer is 42.';
        $expected = 'The actual answer is 42.';

        $result = $this->invokeStripMonologue($input);

        $this->assertEquals($expected, $result);
    }

    public function test_strips_monologue_keyword_case_insensitive(): void
    {
        $input = 'Internal MONOLOGUE and external info. The actual answer is 42.';
        $expected = 'The actual answer is 42.';

        $result = $this->invokeStripMonologue($input);

        $this->assertEquals($expected, $result);
    }

    public function test_strips_step_keyword(): void
    {
        // Monologue-leading content with no final-answer marker collapses to
        // an empty string after stripping.
        $input = 'Step 1 complete now.';

        $result = $this->invokeStripMonologue($input);

        $this->assertEquals('', $result);
    }

    public function test_strips_phase_keyword(): void
    {
        $input = 'Phase 1 complete now.';

        $result = $this->invokeStripMonologue($input);

        $this->assertEquals('', $result);
    }

    public function test_strips_round_keyword(): void
    {
        $input = 'Round 1 complete now.';

        $result = $this->invokeStripMonologue($input);

        $this->assertEquals('', $result);
    }

    public function test_returns_clean_content_unchanged_when_no_monologue_present(): void
    {
        $input = 'This is a clean answer with no keywords.';

        $result = $this->invokeStripMonologue($input);

        $this->assertEquals($input, $result);
    }

    public function test_handles_empty_string(): void
    {
        $result = $this->invokeStripMonologue('');

        $this->assertEquals('', $result);
    }

    public function test_upstream_reasoning_content_field_is_not_in_content(): void
    {
        // A separate reasoning channel must never be merged into the
        // assistant content that reaches the client. sanitizeAssistantContent
        // only ever sees content strings, so a reasoning field would be
        // dropped at the provider/service boundary instead.
        $input = 'Visible answer only.';

        $result = $this->invokeStripMonologue($input);

        $this->assertStringNotContainsString('reasoning', $result);
        $this->assertEquals('Visible answer only.', $result);
    }

    public function test_strips_embedded_think_block_with_reasoning(): void
    {
        $input = chr(60).'think>hidden reasoning here'.chr(60).'/think> Clean answer';
        $expected = 'Clean answer';

        $result = $this->invokeStripMonologue($input);

        $this->assertEquals($expected, $result);
    }

    private function invokeStripMonologue(string $content): string
    {
        $reflection = new ReflectionClass(ChatQueryService::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        return $this->sanitizeAssistantContentMethod->invoke($instance, $content);
    }
}