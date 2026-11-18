<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Models\JobPost;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BDD Feature: Auto-Grounded RAG Context
 *
 * As a User
 * I want a plain chat question about a job to be answered from the knowledge
 * base,
 * So that the assistant cites real JobVision job posts instead of generic
 * advice.
 */
class ChatRagContextBddTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        JobPost::create([
            'external_id' => '9001',
            'source' => 'jobvision',
            'title' => 'Senior Mechanical Engineer - High Pressure Piping',
            'company' => 'Kroobi Company',
            'location' => 'Tehran',
            'description' => 'Design and construct high pressure piping systems for refineries.',
            'skills' => ['SolidWorks', 'CATIA', 'Metallurgy', 'High Pressure Systems'],
            'experience_years' => 6,
            'employment_type' => 'Full Time',
            'status' => 'active',
        ]);
    }

    /** @return array<string,mixed> */
    private function llmReply(): array
    {
        return [
            'id' => 'chatcmpl-rag',
            'created' => 1700000000,
            'model' => 'youse',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'بر اساس اطلاعات آگهی‌های موجود ...'],
                'finish_reason' => 'stop',
            ]],
        ];
    }

    #[Test]
    public function a_question_matching_a_job_post_injects_the_knowledge_base_context(): void
    {
        $captured = [];

        Http::fake([
            '*' => function ($request) use (&$captured) {
                $captured[] = $request->data()['messages'] ?? [];
                return Http::response($this->llmReply(), 200);
            },
        ]);

        $this->postJson('/api/v1/chat/completions', [
            'model' => 'youse',
            'messages' => [['role' => 'user', 'content' => 'آگهی مهندس مکانیک با تجربه SolidWorks دارید؟']],
        ])->assertStatus(200);

        $messages = $captured[0] ?? [];
        $prompt = '';
        foreach ($messages as $message) {
            $prompt .= ($message['content'] ?? '')."\n";
        }

        $this->assertStringContainsString('Context from Knowledge Base', $prompt);
        $this->assertStringContainsString('Senior Mechanical Engineer', $prompt);
        $this->assertStringContainsString('SolidWorks', $prompt);
    }

    #[Test]
    public function a_question_without_any_matching_job_post_is_not_grounded(): void
    {
        $captured = [];

        Http::fake([
            '*' => function ($request) use (&$captured) {
                $captured[] = $request->data()['messages'] ?? [];
                return Http::response($this->llmReply(), 200);
            },
        ]);

        $this->postJson('/api/v1/chat/completions', [
            'model' => 'youse',
            'messages' => [['role' => 'user', 'content' => 'پنیر پیتزا چطور درست می‌شود؟']],
        ])->assertStatus(200);

        $messages = $captured[0] ?? [];
        $prompt = '';
        foreach ($messages as $message) {
            $prompt .= ($message['content'] ?? '')."\n";
        }

        $this->assertStringNotContainsString('Context from Knowledge Base', $prompt);
    }

    #[Test]
    public function the_legacy_query_endpoint_grounds_the_answer_too(): void
    {
        $captured = [];

        Http::fake([
            '*' => function ($request) use (&$captured) {
                $captured[] = $request->data()['messages'] ?? [];
                return Http::response($this->llmReply(), 200);
            },
        ]);

        $this->postJson('/api/v1/chat/query', [
            'model' => 'youse',
            'messages' => [['role' => 'user', 'content' => 'آگهی مهندس مکانیک با تجربه SolidWorks دارید؟']],
            'collection_id' => 'jobvision-chat',
            'top_k' => 5,
        ])->assertStatus(200);

        $messages = $captured[0] ?? [];
        $prompt = '';
        foreach ($messages as $message) {
            $prompt .= ($message['content'] ?? '')."\n";
        }

        $this->assertStringContainsString('Context from Knowledge Base', $prompt);
        $this->assertStringContainsString('Senior Mechanical Engineer', $prompt);
    }

    #[Test]
    public function oversized_history_messages_are_truncated_before_the_llm_call(): void
    {
        $captured = [];

        Http::fake([
            '*' => function ($request) use (&$captured) {
                $captured[] = $request->data()['messages'] ?? [];
                return Http::response($this->llmReply(), 200);
            },
        ]);

        $oversized = str_repeat('ب', 5000);

        $this->actingAs(new \App\Models\User([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]))->postJson('/api/v1/chat/completions', [
            'model' => 'youse',
            'messages' => [
                ['role' => 'user', 'content' => 'سؤال اول'],
                ['role' => 'assistant', 'content' => $oversized],
                ['role' => 'user', 'content' => 'سؤال دوم'],
            ],
        ])->assertStatus(200);

        $messages = $captured[0] ?? [];
        $this->assertNotEmpty($messages);
        $this->assertLessThanOrEqual(4000, mb_strlen((string) ($messages[1]['content'] ?? '')));
        $this->assertLessThanOrEqual(4000, mb_strlen((string) ($messages[2]['content'] ?? '')));
    }
}
