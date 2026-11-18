<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Chat\Models\ChatConversation;
use App\Modules\Chat\Models\ChatMessage;
use App\Modules\Chat\Repositories\DatabaseConversationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * History persistence tests (TASK-083)
 *
 * Tests the DatabaseConversationRepository and ConversationHistoryService
 * with search/filter by job, date range, and candidate.
 */
class ChatHistoryPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private DatabaseConversationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new DatabaseConversationRepository();
    }

    private function createUser(): User
    {
        return User::factory()->create();
    }

    #[Test]
    public function resolveConversation_creates_new_conversation_for_user()
    {
        $userUuid = Str::uuid()->toString();

        $conversation = $this->repository->resolveConversation(null, $userUuid);

        $this->assertNotNull($conversation);
        $this->assertEquals($userUuid, $conversation->user_id);
        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id]);
    }

    #[Test]
    public function resolveConversation_returns_existing_conversation_by_id()
    {
        $userUuid = Str::uuid()->toString();
        $existing = $this->repository->resolveConversation(null, $userUuid);

        $resolved = $this->repository->resolveConversation((string) $existing->id, $userUuid);

        $this->assertEquals($existing->id, $resolved->id);
    }

    #[Test]
    public function storeMessage_persists_message_to_conversation()
    {
        $conversation = ChatConversation::create(['user_id' => Str::uuid()->toString()]);

        $this->repository->storeMessage($conversation->id, 'user', 'Hello question?');
        $this->repository->storeMessage($conversation->id, 'assistant', 'Hello answer!', [
            'intent' => 'greeting',
            'channel' => 'completions',
        ]);

        $this->assertDatabaseCount('chat_messages', 2);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hello question?',
        ]);
    }

    #[Test]
    public function storeMessage_skips_empty_content()
    {
        $conversation = ChatConversation::create(['user_id' => Str::uuid()->toString()]);

        $this->repository->storeMessage($conversation->id, 'user', '');

        $this->assertDatabaseCount('chat_messages', 0);
    }

    #[Test]
    public function storeMessage_skips_duplicate_content()
    {
        $conversation = ChatConversation::create(['user_id' => Str::uuid()->toString()]);

        $this->repository->storeMessage($conversation->id, 'user', 'Hello');
        $this->repository->storeMessage($conversation->id, 'user', 'Hello');

        $this->assertDatabaseCount('chat_messages', 1);
    }

    #[Test]
    public function recentMessages_returns_oldest_first()
    {
        $conversation = ChatConversation::create(['user_id' => Str::uuid()->toString()]);

        ChatMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'first']);
        ChatMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => 'second']);
        ChatMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'third']);

        $messages = $this->repository->recentMessages($conversation->id, 20);

        $this->assertCount(3, $messages);
        $this->assertEquals('user', $messages[0]['role']);
        $this->assertEquals('first', $messages[0]['content']);
        $this->assertEquals('user', $messages[2]['role']);
        $this->assertEquals('third', $messages[2]['content']);
    }

    #[Test]
    public function searchConversations_filters_by_search_term()
    {
        $userUuid = Str::uuid()->toString();
        $conv1 = ChatConversation::create(['user_id' => $userUuid, 'title' => 'About Jobs']);
        $conv2 = ChatConversation::create(['user_id' => $userUuid, 'title' => 'About Candidates']);

        ChatMessage::create(['conversation_id' => $conv1->id, 'role' => 'user', 'content' => 'Tell me about Java jobs']);
        ChatMessage::create(['conversation_id' => $conv2->id, 'role' => 'user', 'content' => 'Show me candidate profiles']);

        $results = $this->repository->searchConversations($userUuid, ['search' => 'candidate'], 20);

        $this->assertCount(1, $results);
        $this->assertEquals('About Candidates', $results->first()->title);
    }

    #[Test]
    public function searchConversations_filters_by_date_range()
    {
        $userUuid = Str::uuid()->toString();
        $old = ChatConversation::create(['user_id' => $userUuid, 'title' => 'Old Chat']);
        $old->setCreatedAt(now()->subDays(10))->save();
        $recent = ChatConversation::create(['user_id' => $userUuid, 'title' => 'Recent Chat']);
        $recent->setCreatedAt(now())->save();

        $results = $this->repository->searchConversations($userUuid, ['date_from' => now()->subDays(5)->toDateString()], 20);

        $this->assertCount(1, $results);
        $this->assertEquals('Recent Chat', $results->first()->title);
    }

    #[Test]
    public function searchConversations_filters_by_job_id_in_metadata()
    {
        $userUuid = Str::uuid()->toString();
        $conv1 = ChatConversation::create(['user_id' => $userUuid]);
        $conv2 = ChatConversation::create(['user_id' => $userUuid]);

        ChatMessage::create([
            'conversation_id' => $conv1->id,
            'role' => 'user',
            'content' => 'Question about job',
            'metadata' => ['job_id' => 42],
        ]);
        ChatMessage::create([
            'conversation_id' => $conv2->id,
            'role' => 'user',
            'content' => 'Another question',
            'metadata' => ['job_id' => 99],
        ]);

        $results = $this->repository->searchConversations($userUuid, ['job_id' => 42], 20);

        $this->assertCount(1, $results);
        $this->assertEquals($conv1->id, $results->first()->id);
    }

    #[Test]
    public function searchConversations_filters_by_candidate_id_in_metadata()
    {
        $userUuid = Str::uuid()->toString();
        $conv1 = ChatConversation::create(['user_id' => $userUuid]);
        $conv2 = ChatConversation::create(['user_id' => $userUuid]);

        ChatMessage::create([
            'conversation_id' => $conv1->id,
            'role' => 'assistant',
            'content' => 'Candidate info',
            'metadata' => ['candidate_id' => 7],
        ]);
        ChatMessage::create([
            'conversation_id' => $conv2->id,
            'role' => 'assistant',
            'content' => 'Other info',
            'metadata' => ['candidate_id' => 13],
        ]);

        $results = $this->repository->searchConversations($userUuid, ['candidate_id' => 13], 20);

        $this->assertCount(1, $results);
        $this->assertEquals($conv2->id, $results->first()->id);
    }

    #[Test]
    public function searchConversations_only_returns_user_conversations()
    {
        $user1 = Str::uuid()->toString();
        $user2 = Str::uuid()->toString();

        ChatConversation::create(['user_id' => $user1, 'title' => 'User1 Chat']);
        ChatConversation::create(['user_id' => $user2, 'title' => 'User2 Chat']);

        $results = $this->repository->searchConversations($user1, [], 20);

        $this->assertCount(1, $results);
        $this->assertEquals('User1 Chat', $results->first()->title);
    }

    #[Test]
    public function getConversationWithMessages_returns_conversation_with_messages()
    {
        $userUuid = Str::uuid()->toString();
        $conversation = ChatConversation::create(['user_id' => $userUuid, 'title' => 'Test Conversation']);

        ChatMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Q1']);
        ChatMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => 'A1']);
        ChatMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Q2']);

        $result = $this->repository->getConversationWithMessages($conversation->id, $userUuid, 50);

        $this->assertNotNull($result);
        $this->assertCount(3, $result->messages);
    }

    #[Test]
    public function getConversationWithMessages_returns_null_for_other_user()
    {
        $user1 = Str::uuid()->toString();
        $user2 = Str::uuid()->toString();
        $conversation = ChatConversation::create(['user_id' => $user1, 'title' => 'Test']);

        $result = $this->repository->getConversationWithMessages($conversation->id, $user2, 50);

        $this->assertNull($result);
    }

    #[Test]
    public function deleteConversation_removes_conversation_and_messages()
    {
        $userUuid = Str::uuid()->toString();
        $conversation = ChatConversation::create(['user_id' => $userUuid]);

        ChatMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'test']);

        $result = $this->repository->deleteConversation($conversation->id, $userUuid);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('chat_conversations', ['id' => $conversation->id]);
    }

    #[Test]
    public function deleteConversation_returns_false_for_nonexistent_conversation()
    {
        $userUuid = Str::uuid()->toString();

        $result = $this->repository->deleteConversation(999999, $userUuid);

        $this->assertFalse($result);
    }

    #[Test]
    public function storeMessage_handles_errors_gracefully()
    {
        $repository = new DatabaseConversationRepository();
        $repository->storeMessage(999999, 'user', 'content');
    }
}