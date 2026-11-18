<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ToolCallingBddTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['chat.tools.enabled' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    #[Test]
    public function tool_definitions_are_sent_when_tool_calling_is_enabled(): void
    {
        Http::fake([
            '*' => function ($request) {
                $data = $request->data() ?? [];
                $tools = $data['tools'] ?? [];
                if (isset($tools[0]['function']['name']) && $tools[0]['function']['name'] === 'render_dynamic_ui') {
                    return Http::response([
                        'id' => 'chatcmpl-tool',
                        'created' => 1700000000,
                        'model' => 'minimax',
                        'choices' => [[
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_render',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'render_dynamic_ui',
                                        'arguments' => json_encode(['component' => 'table', 'title' => 'Candidates', 'data' => [['candidate_id' => 'test-123']]]),
                                    ],
                                ]],
                            ],
                            'finish_reason' => 'tool_calls',
                        ]],
                    ], 200);
                }

                return Http::response([
                    'id' => 'chatcmpl-raw',
                    'created' => 1700000000,
                    'model' => 'youse',
                    'choices' => [[
                        'index' => 0,
                        'message' => ['role' => 'assistant', 'content' => 'Here is the table.'],
                        'finish_reason' => 'stop',
                    ]],
                ], 200);
            },
        ]);

        $this->postJson('/api/v1/chat/completions', [
            'model' => 'youse',
            'messages' => [['role' => 'user', 'content' => 'Show me candidates as a table']],
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            $data = $request->data() ?? [];
            $tools = $data['tools'] ?? [];

            foreach ($tools as $tool) {
                if (($tool['function']['name'] ?? null) === 'render_dynamic_ui') {
                    return true;
                }
            }

            return false;
        });
    }

    #[Test]
    public function tool_call_is_executed_and_result_fed_back_to_llm(): void
    {
        $captured = [];

        Http::fake([
            '*' => function ($request) use (&$captured) {
                $captured[] = $request->data() ?? [];
                $data = $captured[count($captured) - 1];
                $hasTools = isset($data['tools']) && is_array($data['tools']);
                $hasToolCallsInMessages = false;
                foreach ($data['messages'] ?? [] as $msg) {
                    if (isset($msg['tool_calls'])) {
                        $hasToolCallsInMessages = true;
                        break;
                    }
                }
                if ($hasTools && ! $hasToolCallsInMessages) {
                    // First request has tools - return tool_calls
                    return Http::response([
                        'id' => 'chatcmpl-tool',
                        'created' => 1700000000,
                        'model' => 'minimax',
                        'choices' => [[
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_1',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'render_dynamic_ui',
                                        'arguments' => json_encode(['component' => 'table', 'title' => 'Candidates', 'data' => []]),
                                    ],
                                ]],
                            ],
                            'finish_reason' => 'tool_calls',
                        ]],
                    ], 200);
                }

                // Second request (after tool result)
                return Http::response([
                    'id' => 'chatcmpl-final',
                    'created' => 1700000000,
                    'model' => 'minimax',
                    'choices' => [[
                        'index' => 0,
                        'message' => ['role' => 'assistant', 'content' => 'Here is the candidate table: | Name | Score |'],
                        'finish_reason' => 'stop',
                    ]],
                ], 200);
            },
        ]);

        $response = $this->postJson('/api/v1/chat/completions', [
            'model' => 'youse',
            'messages' => [['role' => 'user', 'content' => 'Show candidates table']],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.choices.0.message.content', 'Here is the candidate table: | Name | Score |');
        $this->assertCount(2, $captured);
    }

    #[Test]
    public function the_executed_tool_call_and_its_result_are_surfaced_to_the_client(): void
    {
        Http::fake([
            '*' => function ($request) {
                $data = $request->data() ?? [];
                $hasToolCallsInMessages = false;
                foreach ($data['messages'] ?? [] as $msg) {
                    if (isset($msg['tool_calls'])) {
                        $hasToolCallsInMessages = true;
                        break;
                    }
                }
                if (isset($data['tools']) && ! $hasToolCallsInMessages) {
                    return Http::response([
                        'id' => 'chatcmpl-tool',
                        'created' => 1700000000,
                        'model' => 'minimax',
                        'choices' => [[
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_1',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'render_dynamic_ui',
                                        'arguments' => json_encode(['component' => 'table', 'title' => 'Candidates', 'data' => []]),
                                    ],
                                ]],
                            ],
                            'finish_reason' => 'tool_calls',
                        ]],
                    ], 200);
                }

                return Http::response([
                    'id' => 'chatcmpl-final',
                    'created' => 1700000000,
                    'model' => 'minimax',
                    'choices' => [[
                        'index' => 0,
                        'message' => ['role' => 'assistant', 'content' => 'Done.'],
                        'finish_reason' => 'stop',
                    ]],
                ], 200);
            },
        ]);

        $response = $this->postJson('/api/v1/chat/completions', [
            'model' => 'youse',
            'messages' => [['role' => 'user', 'content' => 'Show candidates table']],
        ]);

        $response->assertStatus(200);

        $toolCalls = $response->json('data.choices.0.message.tool_calls');
        $this->assertIsArray($toolCalls);
        $this->assertCount(1, $toolCalls);
        $this->assertSame('render_dynamic_ui', $toolCalls[0]['function']['name']);
        $this->assertSame('table', $toolCalls[0]['result']['component']);
        $this->assertSame('Candidates', $toolCalls[0]['result']['title']);
    }

    #[Test]
    public function an_unknown_tool_name_does_not_silently_fall_back_to_another_tool(): void
    {
        Http::fake([
            '*' => function ($request) {
                $data = $request->data() ?? [];
                $hasToolCallsInMessages = false;
                foreach ($data['messages'] ?? [] as $msg) {
                    if (isset($msg['tool_calls'])) {
                        $hasToolCallsInMessages = true;
                        break;
                    }
                }
                if (isset($data['tools']) && ! $hasToolCallsInMessages) {
                    return Http::response([
                        'id' => 'chatcmpl-badtool',
                        'created' => 1700000000,
                        'model' => 'minimax',
                        'choices' => [[
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_bad',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'not_a_registered_tool',
                                        'arguments' => json_encode(['component' => 'table']),
                                    ],
                                ]],
                            ],
                            'finish_reason' => 'tool_calls',
                        ]],
                    ], 200);
                }

                return Http::response([
                    'id' => 'chatcmpl-final',
                    'created' => 1700000000,
                    'model' => 'minimax',
                    'choices' => [[
                        'index' => 0,
                        'message' => ['role' => 'assistant', 'content' => 'Sorry, that tool is unavailable.'],
                        'finish_reason' => 'stop',
                    ]],
                ], 200);
            },
        ]);

        $response = $this->postJson('/api/v1/chat/completions', [
            'model' => 'youse',
            'messages' => [['role' => 'user', 'content' => 'Show candidates table']],
        ]);

        $response->assertStatus(200);

        $toolCalls = $response->json('data.choices.0.message.tool_calls');
        $this->assertSame('not_a_registered_tool', $toolCalls[0]['function']['name']);
        $this->assertArrayHasKey('error', $toolCalls[0]['result']);
        $this->assertStringContainsString('not_a_registered_tool', $toolCalls[0]['result']['error']);
    }
}
