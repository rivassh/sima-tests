<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Chat\Services\ProgressStatus;
use Tests\TestCase;

/**
 * Unit tests for the canonical ProgressStatus vocabulary (TASK-085).
 *
 * Verifies that progress statuses are deterministic, server-defined, and
 * never derive from model reasoning, and that reasoning-like fields are
 * stripped from any payload before it reaches the client.
 */
class ProgressStatusTest extends TestCase
{
    public function test_canonical_steps_have_safe_labels(): void
    {
        $required = [
            'reviewing', 'retrieving_context', 'context_ready',
            'tool_running', 'tool_done', 'synthesizing', 'finalizing',
        ];

        foreach ($required as $step) {
            $this->assertArrayHasKey($step, ProgressStatus::STEPS, "step '{$step}' must be defined");
            $this->assertIsString(ProgressStatus::STEPS[$step]);
            $this->assertNotEquals('', ProgressStatus::STEPS[$step], "step '{$step}' must have a non-empty label");
        }
    }

    public function test_unknown_step_falls_back_to_safe_default(): void
    {
        $this->assertEquals(ProgressStatus::DEFAULT_LABEL, ProgressStatus::label('totally_unknown_step'));
    }

    public function test_tool_label_allowlist_only_contains_known_tools(): void
    {
        $this->assertArrayHasKey('candidates', ProgressStatus::TOOL_LABELS);
        $this->assertArrayHasKey('screen_candidate', ProgressStatus::TOOL_LABELS);
        $this->assertArrayHasKey('retrieve_context', ProgressStatus::TOOL_LABELS);

        // Unknown tools must not surface a raw name.
        $this->assertNull(ProgressStatus::toolLabel('unknown_tool_xyz'));
    }

    public function test_event_payload_shape(): void
    {
        $event = ProgressStatus::event('reviewing');

        $this->assertArrayHasKey('step', $event);
        $this->assertArrayHasKey('message', $event);
        $this->assertArrayNotHasKey('meta', $event);
        $this->assertEquals('reviewing', $event['step']);
    }

    public function test_event_with_meta(): void
    {
        $event = ProgressStatus::event('context_ready', null, ['chunks' => 5]);

        $this->assertArrayHasKey('meta', $event);
        $this->assertEquals(['chunks' => 5], $event['meta']);
    }

    public function test_strip_reasoning_fields_removes_all_forbidden_keys(): void
    {
        $payload = [
            'content' => 'visible',
            'reasoning' => 'secret',
            'reasoning_content' => 'secret',
            'reasoning_details' => ['secret'],
            'thought' => 'secret',
            'thoughts' => ['secret'],
            'internal_thought' => 'secret',
            'analysis' => 'secret',
            'chain_of_thought' => 'secret',
            'cot' => 'secret',
        ];

        $stripped = ProgressStatus::stripReasoningFields($payload);

        $this->assertEquals(['content' => 'visible'], $stripped);
    }

    public function test_strip_reasoning_fields_is_safe_on_clean_payload(): void
    {
        $payload = ['content' => 'visible', 'step' => 'reviewing'];

        $stripped = ProgressStatus::stripReasoningFields($payload);

        $this->assertEquals($payload, $stripped);
    }

    public function test_is_forbidden_field_is_case_insensitive(): void
    {
        $this->assertTrue(ProgressStatus::isForbiddenField('Reasoning'));
        $this->assertTrue(ProgressStatus::isForbiddenField('REASONING_CONTENT'));
        $this->assertTrue(ProgressStatus::isForbiddenField('REASONING_DETAILS'));
        $this->assertTrue(ProgressStatus::isForbiddenField('Thought'));
        $this->assertFalse(ProgressStatus::isForbiddenField('content'));
    }
}