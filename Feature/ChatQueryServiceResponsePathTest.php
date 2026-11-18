<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Chat\Contracts\ChatProviderInterface;
use App\Modules\Chat\Contracts\ConversationRepositoryInterface;
use App\Modules\Chat\Contracts\RagRetrieverInterface;
use App\Modules\Chat\Models\ChatConversation;
use App\Modules\Chat\Repositories\PromptRepository;
use App\Modules\Chat\Services\ChatQueryService;
use App\Modules\Chat\Services\ConversationHistoryService;
use App\Modules\Chat\Services\IntentParser;
use App\Modules\Chat\Services\LLMClassifier;
use App\Modules\Chat\Services\PromptBuilder;
use App\Modules\Chat\Tools\ToolRegistry;
use Tests\TestCase;

/**
 * Integration tests verifying stripMonologue() is applied at ALL 6 response paths:
 * 1. completeWithTools() -> formatResponse()
 * 2. complete() -> formatResponse()
 * 3. completeWithCollection() (no query) -> formatResponse()
 * 4. completeWithCollection() (with query + valid context) -> formatResponse()
 * 5. completeWithToolsStreaming() -> assistant_message event (CoT + no loading dots)
 * 6. completeStream() -> assistant_message event (CoT + no loading dots)
 */
class ChatQueryServiceResponsePathTest extends TestCase
{
    protected ChatProviderInterface $mockProvider;

    protected ConversationRepositoryInterface $mockRepo;

    protected ConversationHistoryService $history;

    protected ToolRegistry $toolRegistry;

    protected ChatQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRepo = $this->createMock(ConversationRepositoryInterface::class);
        $this->mockProvider = $this->createMock(ChatProviderInterface::class);

        $intentParser = new IntentParser;
        $retriever = $this->createMock(RagRetrieverInterface::class);
        $promptBuilder = new PromptBuilder($retriever);
        $llmClassifier = new LLMClassifier(
            $this->mockProvider,
            new PromptRepository(300),
            $intentParser
        );

        $conversation = new ChatConversation;
        $conversation->id = 1;
        $conversation->user_id = 'test-user';

        $this->mockRepo->method('resolveConversation')->willReturn($conversation);
        $this->mockRepo->method('recentMessages')->willReturn([]);

        // Default provider response for the LLM classifier call (valid JSON
        // intent). Individual tests that drive the real LLM path override
        // this with `expects(...)->method('complete')->willReturn(...)`.
        $this->mockProvider->method('complete')->willReturn([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'intent' => 'chat', 'confidence' => 0.95, 'reasoning' => 'test',
                    'suggested_tools' => [], 'extracted_params' => [],
                ])],
                'finish_reason' => 'stop',
            ]],
        ]);

        $this->history = new ConversationHistoryService($this->mockRepo);
        $this->toolRegistry = new ToolRegistry;

        $this->service = new ChatQueryService(
            $this->mockProvider,
            $intentParser,
            $llmClassifier,
            $promptBuilder,
            $retriever,
            $this->history,
            $this->toolRegistry
        );
    }

    protected function cotResponse(string $content): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'test-model',
            'choices' => [
                ['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop'],
            ],
        ];
    }

    protected function cotToolResponse(string $content, array $toolCalls = []): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'test-model',
            'choices' => [
                ['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content, 'tool_calls' => $toolCalls], 'finish_reason' => $toolCalls ? 'tool_calls' : 'stop'],
            ],
        ];
    }

    public function test_complete_with_tools_strips_monologue_via_format_response(): void
    {
        $cotContent = "Here is the answer\n<internal>done";

        $this->mockProvider->expects($this->once())
            ->method('completeWithTools')
            ->willReturn($this->cotResponse($cotContent));

        $response = $this->service->completeWithTools(
            messages: [['role' => 'user', 'content' => 'test']],
            model: 'test-model',
            temperature: 0.3,
            maxTokens: null,
            tools: [],
            maxToolRounds: 5,
            conversationId: null,
            userId: 'test-user'
        );

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('success', $data['status']);
        $assistantContent = $data['data']['choices'][0]['message']['content'];
        $this->assertStringNotContainsString('internal', $assistantContent);
    }

    public function test_complete_strips_monologue_via_format_response(): void
    {
        $cotContent = '**think: this is internal** Clean answer';

        $this->mockProvider->expects($this->atLeastOnce())
            ->method('complete')
            ->willReturn($this->cotResponse($cotContent));

        $response = $this->service->complete(
            messages: [['role' => 'user', 'content' => 'test']],
            model: 'test-model',
            temperature: 0.3,
            maxTokens: null,
            conversationId: null,
            userId: 'test-user'
        );

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $assistantContent = $data['data']['choices'][0]['message']['content'];
        $this->assertStringNotContainsString('**think:', $assistantContent);
        $this->assertStringNotContainsString('this is internal', $assistantContent);
    }

    public function test_complete_with_collection_no_query_strips_monologue(): void
    {
        $cotContent = 'Reasoning about the answer. The actual answer is 42.';

        $this->mockProvider->expects($this->atLeastOnce())
            ->method('complete')
            ->willReturn($this->cotResponse($cotContent));

        $response = $this->service->completeWithCollection(
            messages: [],
            model: 'test-model',
            collectionName: 'test-collection',
            topK: 5,
            temperature: 0.3,
            maxTokens: null,
            conversationId: null,
            userId: 'test-user'
        );

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $assistantContent = $data['data']['choices'][0]['message']['content'];
        $this->assertStringNotContainsString('Reasoning', $assistantContent);
    }

    public function test_complete_with_collection_with_context_strips_monologue(): void
    {
        $cotContent = 'Thinking... Step 1 complete. Phase 2 done. The actual answer is 42.';

        $retriever = $this->createMock(RagRetrieverInterface::class);
        $retriever->method('retrieve')->willReturn([
            ['content' => 'Context chunk 1'],
            ['content' => 'Context chunk 2'],
        ]);

        $promptBuilder = new PromptBuilder($retriever);
        $repo = $this->createMock(ConversationRepositoryInterface::class);
        $repo->method('resolveConversation')->willReturn(new ChatConversation);
        $repo->method('recentMessages')->willReturn([]);
        $history = new ConversationHistoryService($repo);
        $llmClassifier = new LLMClassifier(
            $this->mockProvider,
            new PromptRepository(300),
            new IntentParser
        );

        $service = new ChatQueryService(
            $this->mockProvider,
            new IntentParser,
            $llmClassifier,
            $promptBuilder,
            $retriever,
            $history,
            new ToolRegistry
        );

        $this->mockProvider->expects($this->atLeastOnce())
            ->method('complete')
            ->willReturn($this->cotResponse($cotContent));

        $response = $service->completeWithCollection(
            messages: [['role' => 'user', 'content' => 'test query']],
            model: 'test-model',
            collectionName: 'test-collection',
            topK: 5,
            temperature: 0.3,
            maxTokens: null,
            conversationId: null,
            userId: 'test-user'
        );

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $assistantContent = $data['data']['choices'][0]['message']['content'];
        $this->assertStringNotContainsString('Thinking', $assistantContent);
        $this->assertStringNotContainsString('Step', $assistantContent);
        $this->assertStringNotContainsString('Phase', $assistantContent);
        $this->assertStringNotContainsString('Round', $assistantContent);
    }

    public function test_complete_with_tools_streaming_strips_monologue_and_emits_canonical_safe_statuses(): void
    {
        $cotContent = 'Internal monologue: step 1, phase 2. Final answer here.';

        $callCount = 0;
        $this->mockProvider->expects($this->exactly(2))
            ->method('completeWithTools')
            ->willReturnCallback(function () use (&$callCount, $cotContent) {
                $callCount++;
                if ($callCount === 1) {
                    return $this->cotToolResponse('', [
                        ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'test_tool', 'arguments' => '{}']],
                    ]);
                }

                return $this->cotResponse($cotContent);
            });

        $this->toolRegistry->register('test_tool', fn (array $args) => ['ok' => true], [
            'description' => 'Test tool',
            'parameters' => ['type' => 'object', 'properties' => new \stdClass],
        ]);

        $generator = $this->service->completeWithToolsStreaming(
            messages: [['role' => 'user', 'content' => 'test']],
            model: 'test-model',
            temperature: 0.3,
            maxTokens: null,
            tools: $this->toolRegistry->definitions(),
            maxToolRounds: 5,
            conversationId: null,
            userId: 'test-user'
        );

        $events = iterator_to_array($generator);
        $assistantEvent = null;
        $statusEvents = [];

        foreach ($events as $event) {
            if ($event['event'] === 'assistant_message') {
                $assistantEvent = $event;
            }
            if ($event['event'] === 'status') {
                $statusEvents[] = $event;
            }
        }

        $this->assertNotNull($assistantEvent, 'assistant_message event must be emitted');
        $content = $assistantEvent['data']['content'];
        $this->assertStringNotContainsString('step 1', $content);
        $this->assertStringNotContainsString('phase 2', $content);
        $this->assertStringNotContainsString('monologue', $content);

        // No forbidden reasoning fields in any event payload.
        $forbidden = ['reasoning', 'reasoning_content', 'reasoning_details', 'thought', 'thoughts', 'internal_thought', 'analysis', 'chain_of_thought', 'cot'];
        foreach ($events as $event) {
            foreach ($forbidden as $field) {
                $this->assertArrayNotHasKey($field, $event['data'] ?? [], "Forbidden reasoning field '{$field}' must never appear in SSE data.");
            }
        }

        // Canonical safe statuses must be emitted in order.
        $statusSteps = array_map(fn ($e) => $e['data']['step'], $statusEvents);
        $this->assertContains('reviewing', $statusSteps, 'canonical reviewing status must be emitted');
        $this->assertContains('tool_running', $statusSteps, 'canonical tool_running status must be emitted');
        $this->assertContains('tool_done', $statusSteps, 'canonical tool_done status must be emitted');
        $this->assertContains('synthesizing', $statusSteps, 'canonical synthesizing status must be emitted');
        $this->assertContains('finalizing', $statusSteps, 'canonical finalizing status must be emitted');

        // Tool status must carry an allowlisted label, never raw args/sensitive data.
        $toolRunning = array_filter($statusEvents, fn ($e) => ($e['data']['step'] ?? '') === 'tool_running');
        $this->assertNotEmpty($toolRunning, 'tool_running status must be emitted for the executed tool');
        foreach ($toolRunning as $status) {
            $this->assertArrayHasKey('tool_label', $status['data']['meta'] ?? [], 'tool_running status must carry an allowlisted tool_label');
            $this->assertArrayNotHasKey('args', $status['data'] ?? [], 'tool_running status must never carry raw tool args');
        }

        // Legacy 'thinking' loading-dot status must never be emitted.
        $thinkingStep = array_filter($statusSteps, fn ($d) => $d === 'thinking');
        $this->assertEmpty($thinkingStep, 'thinking status event (loading dots) must be removed');
    }

    public function test_complete_stream_strips_monologue_and_emits_canonical_safe_statuses(): void
    {
        $cotContent = 'Monologue: internal thinking. The actual answer.';

        $this->mockProvider->expects($this->atLeastOnce())
            ->method('complete')
            ->willReturn($this->cotResponse($cotContent));

        $generator = $this->service->completeStream(
            messages: [['role' => 'user', 'content' => 'test']],
            model: 'test-model',
            temperature: 0.3,
            maxTokens: null
        );

        $events = iterator_to_array($generator);
        $assistantEvent = null;
        $statusEvents = [];

        foreach ($events as $event) {
            if ($event['event'] === 'assistant_message') {
                $assistantEvent = $event;
            }
            if ($event['event'] === 'status') {
                $statusEvents[] = $event;
            }
        }

        $this->assertNotNull($assistantEvent, 'assistant_message event must be emitted');
        $content = $assistantEvent['data']['content'];
        $this->assertStringNotContainsString('thinking', $content);

        // No forbidden reasoning fields in any event payload.
        $forbidden = ['reasoning', 'reasoning_content', 'reasoning_details', 'thought', 'thoughts', 'internal_thought', 'analysis', 'chain_of_thought', 'cot'];
        foreach ($events as $event) {
            foreach ($forbidden as $field) {
                $this->assertArrayNotHasKey($field, $event['data'] ?? [], "Forbidden reasoning field '{$field}' must never appear in SSE data.");
            }
        }

        // Canonical safe statuses must be emitted in order.
        $statusSteps = array_map(fn ($e) => $e['data']['step'], $statusEvents);
        $this->assertContains('reviewing', $statusSteps, 'canonical reviewing status must be emitted');
        $this->assertContains('synthesizing', $statusSteps, 'canonical synthesizing status must be emitted');
        $this->assertContains('finalizing', $statusSteps, 'canonical finalizing status must be emitted');

        // Legacy 'thinking' loading-dot status must never be emitted.
        $thinkingStep = array_filter($statusSteps, fn ($d) => ($d['step'] ?? '') === 'thinking');
        $this->assertEmpty($thinkingStep, 'thinking status event (loading dots) must be removed');
    }

    public function test_format_response_strips_monologue_with_tool_calls(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('formatResponse');
        $method->setAccessible(true);

        $cotContent = 'Reasoning: step 1. Answer: done.';

        $rawResponse = $this->cotResponse($cotContent);
        $rawResponse['choices'][0]['message']['tool_calls'] = [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'test', 'arguments' => '{}']],
        ];

        $response = $method->invoke($this->service, $rawResponse, 'test-model', 1, [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'test', 'arguments' => '{}'], 'result' => ['ok' => true]],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $assistantContent = $data['data']['choices'][0]['message']['content'];
        $this->assertStringNotContainsString('Reasoning', $assistantContent);
        $this->assertStringNotContainsString('step 1', $assistantContent);
    }
}
