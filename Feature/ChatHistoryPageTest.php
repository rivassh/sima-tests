<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Chat\Models\ChatConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatHistoryPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    }

    public function test_authenticated_user_can_open_chat_history_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/chat/history')
            ->assertOk()
            ->assertSee('سوابق گفتگو');
    }

    public function test_dashboard_has_a_link_to_chat_history(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')
            ->assertOk()
            ->assertSee('href="/chat/history"', false);
    }

    public function test_user_cannot_read_another_users_conversation(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $conversation = ChatConversation::create([
            'user_id' => $owner->getAuthIdentifier(),
            'title' => 'Private conversation',
        ]);

        $this->actingAs($otherUser)
            ->getJson("/api/v1/chat/conversations/{$conversation->id}")
            ->assertNotFound();
    }
}
