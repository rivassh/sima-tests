<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Modules\Chat\Adapters\MimoAdapterChatProvider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Minimal smoke test: bypass ChatController entirely, instantiate MimoAdapterChatProvider
 * directly and verify that Http::fake() intercepts the provider's outbound HTTP call.
 */
class SmokeTestProviderDirect extends TestCase
{
    #[Test]
    public function fake_intercepts_provider_chat_completion(): void
    {
        $capturedRequest = null;
        Http::fake([
            '*' => function ($request) use (&$capturedRequest) {
                $capturedRequest = $request;
                return Http::response([
                    'id' => 'chatcmpl-smoke',
                    'choices' => [[
                        'index' => 0,
                        'message' => ['role' => 'assistant', 'content' => 'smoke reply'],
                        'finish_reason' => 'stop',
                    ]],
                ], 200);
            },
        ]);

        $provider = new MimoAdapterChatProvider(app());

        $result = $provider->complete([
            [
                'role' => 'user',
                'content' => 'hello test',
            ],
        ]);

        $this->assertNotNull($capturedRequest);
        $this->assertSame('chat/completions', $capturedRequest->url());
        $this->assertNotNull($result);
    }
}
