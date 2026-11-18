<?php
declare(strict_types=1);
namespace Tests\Feature\BDD;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
class MemCheckTest extends TestCase {
    #[Test]
    public function check_memory_at_key_points(): void {
        fwrite(STDERR, "START_MEM=" . (memory_get_usage(true) / 1024 / 1024) . "MB\n");
        
        Http::fake([
            "*" => function ($req) {
                return Http::response(["choices" => [["message" => ["role" => "assistant", "content" => "ok"]]]], 200);
            },
        ]);
        fwrite(STDERR, "HTTP_FAKE_MEM=" . (memory_get_usage(true) / 1024 / 1024) . "MB\n");
        
        $r = $this->postJson("/api/v1/chat/completions", [
            "model" => "youse",
            "messages" => [["role" => "user", "content" => "hello"]],
        ]);
        fwrite(STDERR, "POST_MEM=" . (memory_get_usage(true) / 1024 / 1024) . "MB\n");
        fwrite(STDERR, "STATUS=" . $r->status() . "\n");
        $this->assertTrue(true);
    }
}
