<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChatTranslationTest extends TestCase
{
    public function test_expired_chat_session_message_uses_persian_translation(): void
    {
        app()->setLocale('en');

        $renderedChat = view('chat')->render();

        $this->assertStringContainsString(
            'نشست ورود شما نامعتبر یا منقضی شده است. لطفاً دوباره وارد شوید.',
            $renderedChat,
        );
    }
}
