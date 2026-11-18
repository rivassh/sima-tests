<?php

namespace Tests\Feature\BDD;

use App\Http\Controllers\SseStreamingController;
use App\Services\AI\Streaming\SSEHandler;
use Tests\TestCase;

class SseHandlerReferenceBddTest extends TestCase
{
    public function test_sse_streaming_controller_uses_correct_sse_handler_path()
    {
        $controllerSource = file_get_contents(base_path('app/Http/Controllers/SseStreamingController.php'));

        // Should use the correct namespace path
        $this->assertStringContainsString('App\\Services\\AI\\Streaming\\SSEHandler', $controllerSource);

        // Should NOT use the incorrect App\AI namespace
        $this->assertStringNotContainsString('App\\AI\\Streaming', $controllerSource);
    }

    public function test_sse_handler_class_exists_at_correct_path()
    {
        // Verify the SSEHandler class exists at the correct namespace
        $this->assertTrue(class_exists(SSEHandler::class));
        $this->assertEquals('App\Services\AI\Streaming\SSEHandler', SSEHandler::class);
    }
}