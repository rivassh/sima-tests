<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Modules\Chat\Models\ChatConversation;
use App\Modules\Chat\Models\ChatMessage;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BDD Feature: Chat Conversation History
 *
 * As a User
 * I want every chat prompt to carry the previous turns of my conversation
 * and my exchange to be remembered,
 * So that the assistant understands follow-up questions instead of treating
 * every message as a fresh conversation.
 */
class ChatConversationHistoryBddTest extends TestCase
{
    /** @return array<string,mixed> */
    private function llmReply(string $content = 'پاسخ آزمایشی'): array
    {
        return [
            'id' => 'chatcmpl-test',
            'created' => 1700000000,
            'model' => 'youse',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
        ];
    }

    protected function fakeLlm(array &$captured = null): void
    {
        Http::fake([
            '*' => function ($request) use (&$captured) {
                if ($captured !== null) {
                    $captured[] = array_column($request->data()['messages'] ?? [], 'content');
                }
                return Http::response($this->llmReply(), 200);
            },
        ]);
    }

    /** @param array<int,array<string,string>> $capturedMessages */
    private function lastLlmMessages(array &$captured): array
    {
        return end($captured);
    }

    #[Test]
    public function chat_completion_persists_the_exchange_and_returns_a_conversation_id(): void
    {
        $this->fakeLlm();

        $response = $this->postJson('/api/v1/chat/completions', [
            'model' => 'youse',
            'messages' => [['role' => 'user', 'content' => 'سلام، چه کسانی برای مهندسی مکانیک دارید؟']],
        ]);

        $response->assertStatus(200);

        $conversationId = $response->json('data.conversation_id');
        $this->assertNotNull($conversationId);

        $this->assertDatabaseHas('chat_conversations', ['id' => $conversationId]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversationId,
            'role' => 'user',
            'content' => 'سلام، چه کسانی برای مهندسی مکانیک دارید؟',
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => 'پاسخ آزمایشی',
        ]);
    }

    #[Test]
    public function follow_up_request_carries_the_previous_turns_to_the_llm(): void
    {
        $captured = [];
        $this->fakeLlm($captured);

        $first = $this->postJson('/api/v1/chat/completions', [
            'model' => 'youse',
            'messages' => [['role' => 'user', 'content' => 'مکانیک دارید؟']],
        ])->assertStatus(200);

        $conversationId = $first->json('data.conversation_id');

        $this->postJson('/api/v1/chat/completions', [
            'model' => 'youse',
            'conversation_id' => (string) $conversationId,
            'messages' => [['role' => 'user', 'content' => 'چند سال سابقه لازم دارند؟']],
        ])->assertStatus(200);

        $contents = $this->lastLlmMessages($captured);

        $this->assertContains('مکانیک دارید؟', $contents);
        $this->assertContains('پاسخ آزمایشی', $contents);
        $this->assertContains('چند سال سابقه لازم دارند؟', $contents);
    }

    #[Test]
    public function a_client_that_sends_the_full_thread_gets_no_duplicates(): void
    {
        $captured = [];
        $this->fakeLlm($captured);

        $first = $this->postJson('/api/v1/chat/completions', [
            'model' => 'youse',
            'messages' => [['role' => 'user', 'content' => 'اولین پیام']],
        ])->assertStatus(200);

        $conversationId = $first->json('data.conversation_id');

        $this->postJson('/api/v1/chat/completions', [
            'model' => 'youse',
            'conversation_id' => (string) $conversationId,
            'messages' => [
                ['role' => 'user', 'content' => 'اولین پیام'],
                ['role' => 'assistant', 'content' => 'پاسخ آزمایشی'],
                ['role' => 'user', 'content' => 'پیام دوم'],
            ],
        ])->assertStatus(200);

        $contents = $this->lastLlmMessages($captured);

        $this->assertSame(1, count(array_filter($contents, fn ($c) => $c === 'اولین پیام')));
        $this->assertSame(1, count(array_filter($contents, fn ($c) => $c === 'پاسخ آزمایشی')));
        $this->assertSame(1, count(array_filter($contents, fn ($c) => $c === 'پیام دوم')));
    }

    #[Test]
    public function the_returned_conversation_id_can_be_sent_back_verbatim(): void
    {
        $captured = [];
        $this->fakeLlm($captured);

        $first = $this->postJson('/api/v1/chat/completions', [
            'model' => 'youse',
            'messages' => [['role' => 'user', 'content' => 'مکانیک دارید؟']],
        ])->assertStatus(200);

        // The API returns the id as a JSON number; a client echoing it back
        // unchanged must not be rejected by validation.
        $conversationId = $first->json('data.conversation_id');
        $this->assertIsInt($conversationId);

        $second = $this->postJson('/api/v1/chat/completions', [
            'model' => 'youse',
            'conversation_id' => $conversationId,
            'messages' => [['role' => 'user', 'content' => 'چند سال سابقه لازم دارند؟']],
        ])->assertStatus(200);

        $this->assertSame($conversationId, $second->json('data.conversation_id'));
    }

    #[Test]
    public function an_unknown_conversation_id_starts_a_new_thread(): void
    {
        $captured = [];
        $this->fakeLlm($captured);

        $existing = ChatConversation::create(['user_id' => null, 'title' => null]);
        ChatMessage::create([
            'conversation_id' => $existing->id,
            'role' => 'user',
            'content' => 'پیام محرمانه',
        ]);

        $response = $this->postJson('/api/v1/chat/completions', [
            'model' => 'youse',
            'conversation_id' => '999999',
            'messages' => [['role' => 'user', 'content' => 'پیام تازه']],
        ])->assertStatus(200);

        $newId = $response->json('data.conversation_id');
        $this->assertNotSame((string) $existing->id, (string) $newId);

        // The other thread's content must not leak into the new one.
        $contents = $this->lastLlmMessages($captured);
        $this->assertNotContains('پیام محرمانه', $contents);
    }

    #[Test]
    public function the_rag_query_endpoint_also_keeps_history(): void
    {
        $this->fakeLlm();

        $first = $this->postJson('/api/v1/chat/query', [
            'model' => 'youse',
            'messages' => [['role' => 'user', 'content' => 'مکانیک دارید؟']],
            'collection_id' => 'jobvision-chat',
            'top_k' => 3,
        ])->assertStatus(200);

        $conversationId = $first->json('data.conversation_id');
        $this->assertNotNull($conversationId);

        $this->postJson('/api/v1/chat/query', [
            'model' => 'youse',
            'conversation_id' => (string) $conversationId,
            'messages' => [['role' => 'user', 'content' => 'و سابقه چند ساله؟']],
            'collection_id' => 'jobvision-chat',
            'top_k' => 3,
        ])->assertStatus(200);

        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversationId,
            'role' => 'user',
            'content' => 'و سابقه چند ساله؟',
        ]);
    }
}
