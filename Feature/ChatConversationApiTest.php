<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Chat\Models\ChatConversation;
use App\Modules\Chat\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Chat Conversation API Tests (TASK-014)
 *
 * Tests for listing user conversations and retrieving conversation messages.
 */
class ChatConversationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_their_conversations(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $conversation = ChatConversation::create([
            'user_id' => $user->id,
            'title' => 'Test Chat',
        ]);
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hello',
        ]);

        $response = $this->getJson('/api/v1/chat/conversations');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                '*' => [
                    'id',
                    'slug_id',
                    'title',
                    'message_count',
                    'created_at',
                ],
            ],
        ]);

        $this->assertCount(1, $response->json('data'));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $response->json('data.0.slug_id')
        );
    }

    public function test_user_can_retrieve_conversation_messages(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $conversation = ChatConversation::create([
            'user_id' => $user->id,
            'title' => 'Test Chat',
        ]);
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hello',
        ]);
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Hi there!',
        ]);

        $response = $this->getJson("/api/v1/chat/conversations/{$conversation->slug_id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                '*' => ['role', 'content', 'conversation_id'],
            ],
        ]);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_unknown_conversation_returns_404(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->getJson('/api/v1/chat/conversations/999999');

        $response->assertStatus(404);
    }

    public function test_user_cannot_read_another_users_conversation_by_slug_id(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $conversation = ChatConversation::create([
            'user_id' => $owner->id,
            'title' => 'Private conversation',
        ]);

        $this->actingAs($otherUser)
            ->getJson("/api/v1/chat/conversations/{$conversation->slug_id}")
            ->assertNotFound();
    }

    public function test_user_with_integer_id_can_list_their_conversations(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $conversation = app(\App\Modules\Chat\Repositories\DatabaseConversationRepository::class)
            ->resolveConversation(null, (string) $user->getAuthIdentifier());

        $this->assertSame((string) $user->getAuthIdentifier(), $conversation->user_id);
        $this->getJson('/api/v1/chat/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversation->id);
    }

    public function test_user_cannot_read_conversation_owned_by_another_uuid_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $conversation = ChatConversation::create([
            'user_id' => (string) Str::uuid(),
            'title' => 'Private conversation',
        ]);
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Private message',
        ]);

        $this->getJson("/api/v1/chat/conversations/{$conversation->id}")
            ->assertNotFound();
    }

    public function test_resolving_another_users_conversation_creates_a_private_conversation(): void
    {
        $user = User::factory()->create();
        $conversation = ChatConversation::create([
            'user_id' => (string) Str::uuid(),
            'title' => 'Private conversation',
        ]);

        $resolved = app(\App\Modules\Chat\Repositories\DatabaseConversationRepository::class)
            ->resolveConversation((string) $conversation->id, (string) $user->getAuthIdentifier());

        $this->assertNotSame($conversation->id, $resolved->id);
        $this->assertSame((string) $user->getAuthIdentifier(), $resolved->user_id);
    }
}