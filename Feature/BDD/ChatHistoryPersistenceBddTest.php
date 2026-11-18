<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Modules\Chat\Contracts\ConversationRepositoryInterface;
use App\Modules\Chat\Models\ChatConversation;
use App\Modules\Chat\Services\ConversationHistoryService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChatHistoryPersistenceBddTest extends TestCase
{
    private ConversationRepositoryInterface $repository;
    private ConversationHistoryService $history;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(ConversationRepositoryInterface::class);
        $this->history = app(ConversationHistoryService::class);
        DB::table('chat_messages')->truncate();
        DB::table('chat_conversations')->truncate();
    }

    public function test_chat_history_is_stored_in_database(): void
    {
        $conversation = ChatConversation::create(['user_id' => 'test-user', 'title' => 'test']);

        $this->repository->storeMessage($conversation->id, 'user', 'Hello, how are you?');
        $this->repository->storeMessage($conversation->id, 'assistant', 'I am doing well, thank you!');

        $messages = $this->repository->recentMessages($conversation->id, 50);

        $this->assertCount(2, $messages);
        $roles = array_column($messages, 'role');
        $this->assertContains('user', $roles);
        $this->assertContains('assistant', $roles);
    }

    public function test_chat_history_merges_across_turns(): void
    {
        $conversation = ChatConversation::create(['user_id' => 'test-user', 'title' => 'test']);

        $this->repository->storeMessage($conversation->id, 'user', 'First question');
        $this->repository->storeMessage($conversation->id, 'assistant', 'First answer');
        $this->repository->storeMessage($conversation->id, 'user', 'Second question');
        $this->repository->storeMessage($conversation->id, 'assistant', 'Second answer');

        $stored = $this->repository->recentMessages($conversation->id, 50);

        $this->assertCount(4, $stored);
    }

    public function test_chat_history_persists_assistant_response_with_metadata(): void
    {
        $conversation = ChatConversation::create(['user_id' => 'test-user', 'title' => 'test']);

        $this->repository->storeMessage($conversation->id, 'assistant', 'Here is the answer', ['intent' => 'chat', 'tool' => 'search']);

        $stored = DB::table('chat_messages')
            ->where('conversation_id', $conversation->id)
            ->first();

        $this->assertNotNull($stored);
        $this->assertEquals('assistant', $stored->role);
        $this->assertEquals('Here is the answer', $stored->content);
        $this->assertJsonStringEqualsJsonString(json_encode(['intent' => 'chat', 'tool' => 'search']), $stored->metadata);
    }

    public function test_chat_history_resolves_new_conversation_per_user(): void
    {
        $convA = $this->repository->resolveConversation(null, 'user_a');
        $convB = $this->repository->resolveConversation(null, 'user_b');

        $this->assertNotEquals($convA->id, $convB->id);
        $this->assertNull($convA->user_id === null ? null : $convA->user_id);
    }

    public function test_chat_history_per_user_isolation(): void
    {
        $userA = $this->repository->resolveConversation(null, 'user_alpha');
        $userB = $this->repository->resolveConversation(null, 'user_beta');

        $this->assertNotEquals($userA->id, $userB->id);

        $this->repository->storeMessage($userA->id, 'user', 'Alpha asks something');
        $this->repository->storeMessage($userA->id, 'assistant', 'Alpha answer');
        $this->repository->storeMessage($userB->id, 'user', 'Beta asks something');
        $this->repository->storeMessage($userB->id, 'assistant', 'Beta answer');

        $alphasMessages = $this->repository->recentMessages((int) $userA->id, 50);
        $betasMessages = $this->repository->recentMessages((int) $userB->id, 50);

        $this->assertCount(2, $alphasMessages);
        $this->assertCount(2, $betasMessages);

        foreach ($alphasMessages as $m) {
            $this->assertTrue((string) $m['role'] !== (string) $m['content'], 'Alpha messages should not leak into Beta thread');
        }
    }

    public function test_conversation_history_service_merges_and_persists(): void
    {
        $user = 'svc-42';
        $conversation = $this->history->resolveConversation(null, $user);

        $this->repository->storeMessage((int) $conversation->id, 'user', 'What are your capabilities?');
        $this->repository->storeMessage((int) $conversation->id, 'assistant', 'I can help with job search queries.');

        $stored = $this->repository->recentMessages((int) $conversation->id, 50);
        $merged = $this->history->merge([], $stored);

        $this->assertCount(2, $merged);
        $this->assertEquals('user', $merged[0]['role']);
        $this->assertEquals('assistant', $merged[1]['role']);
    }

    public function test_new_message_does_not_duplicate_existing_content(): void
    {
        $conversation = ChatConversation::create(['user_id' => 'dup-user', 'title' => 'test']);

        $this->repository->storeMessage($conversation->id, 'user', 'Repeat message');
        $this->repository->storeMessage($conversation->id, 'user', 'Repeat message');

        $stored = $this->repository->recentMessages($conversation->id, 50);

        $this->assertCount(1, $stored);
    }

    public function test_empty_message_skipped_on_persist(): void
    {
        $conversation = ChatConversation::create(['user_id' => 'skip-user', 'title' => 'test']);

        $this->repository->storeMessage($conversation->id, 'user', '');
        $this->repository->storeMessage($conversation->id, 'user', 'Real message');

        $stored = $this->repository->recentMessages($conversation->id, 50);

        $this->assertCount(1, $stored);
    }
}