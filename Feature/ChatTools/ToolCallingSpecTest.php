<?php

declare(strict_types=1);

namespace Tests\Feature\ChatTools;

use App\Modules\Chat\Adapters\FallbackChatProvider;
use App\Modules\Chat\Contracts\ChatProviderInterface;
use App\Modules\Chat\Contracts\ConversationRepositoryInterface;
use App\Modules\Chat\Contracts\RagRetrieverInterface;
use App\Modules\Chat\Contracts\ToolInterface;
use App\Modules\Chat\Models\ChatConversation;
use App\Modules\Chat\Repositories\DatabaseConversationRepository;
use App\Modules\Chat\Repositories\PromptRepository;
use App\Modules\Chat\Services\ChatQueryService;
use App\Modules\Chat\Services\ConversationHistoryService;
use App\Modules\Chat\Services\IntentParser;
use App\Modules\Chat\Services\LLMClassifier;
use App\Modules\Chat\Services\PromptBuilder;
use App\Modules\Chat\Tools\InfrastructureException;
use App\Modules\Chat\Tools\ProviderRequestException;
use App\Modules\Chat\Tools\ToolRegistry;
use App\Modules\Chat\Tools\UnknownToolException;
use Mockery;
use Tests\TestCase;

class ToolCallingSpecTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'chat.tools.enabled' => true,
            'chat.tools.allow_client_tools' => false,
            'chat.tools.max_rounds' => 3,
            'chat.tools.max_validation_attempts' => 2,
            'chat.tools.default_write_classification' => 'write',
            'chat.observability.enabled' => true,
            'chat.observability.log_learning_events' => true,
            'chat.observability.redact_pii' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_flag_disabled_ignores_caller_tools_and_uses_no_tool_path(): void
    {
        config(['chat.tools.enabled' => false]);

        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('complete')->andReturn($this->textResponse('hello'));
        $provider->shouldReceive('completeWithTools')->never();

        $service = $this->service($provider, new ToolRegistry);

        $response = $service->complete(
            messages: [['role' => 'user', 'content' => 'hello']],
            model: 'test-model',
            temperature: 0.3,
            maxTokens: null,
            tools: [['type' => 'function', 'function' => ['name' => 'calculate_vat', 'description' => 'x', 'parameters' => ['type' => 'object', 'properties' => new \stdClass]]]],
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello', $response->getData(true)['data']['choices'][0]['message']['content']);
    }

    public function test_registry_registration_resolution_and_unknown_tool_rejection(): void
    {
        $registry = new ToolRegistry;
        $registry->registerTool(new class implements ToolInterface
        {
            public function name(): string
            {
                return 'calculate_vat';
            }

            public function description(): string
            {
                return 'Calculate VAT';
            }

            public function schema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [
                        'amount' => ['type' => 'number'],
                    ],
                    'required' => ['amount'],
                ];
            }

            public function isWrite(): bool
            {
                return false;
            }

            public function execute(array $arguments): array
            {
                return ['vat' => $arguments['amount'] * 0.1];
            }
        });

        $this->assertTrue($registry->has('calculate_vat'));
        $this->assertSame('calculate_vat', $registry->get('calculate_vat')->name());
        $this->assertSame(['calculate_vat'], $registry->names());
        $this->assertCount(1, $registry->definitions());
        $this->assertSame(['vat' => 1.0], $registry->call('calculate_vat', ['amount' => 10]));

        $this->expectException(UnknownToolException::class);
        $registry->get('missing_tool');
    }

    public function test_disabled_tool_is_not_exported_or_executable(): void
    {
        $registry = new ToolRegistry;
        $registry->register('calculate_vat', static fn (array $args): array => ['vat' => 1], [
            'description' => 'Calculate VAT',
            'parameters' => ['type' => 'object', 'properties' => new \stdClass],
            'is_write' => false,
        ]);

        $registry->disable('calculate_vat');

        $this->assertFalse($registry->isEnabled('calculate_vat'));
        $this->assertSame([], $registry->definitions());

        $this->expectException(UnknownToolException::class);
        $registry->call('calculate_vat', []);
    }

    public function test_callable_tool_can_be_registered_without_touching_orchestrator(): void
    {
        $registry = new ToolRegistry;
        $registry->register('calculate_vat', static fn (array $args): array => ['vat' => $args['amount'] * 0.1], [
            'description' => 'Calculate VAT',
            'parameters' => [
                'type' => 'object',
                'properties' => ['amount' => ['type' => 'number']],
                'required' => ['amount'],
            ],
            'is_write' => false,
        ]);

        $this->assertFalse($registry->classifications()['calculate_vat'] === 'write');
        $this->assertSame(['vat' => 2.5], $registry->call('calculate_vat', ['amount' => 25]));
    }

    public function test_obvious_greeting_uses_no_tool_path_even_when_tools_are_enabled(): void
    {
        $registry = new ToolRegistry;
        $registry->register('calculate_vat', static fn (array $args): array => ['vat' => 1], [
            'description' => 'Calculate VAT',
            'parameters' => [
                'type' => 'object',
                'properties' => ['amount' => ['type' => 'number']],
                'required' => ['amount'],
            ],
            'is_write' => false,
        ]);

        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('complete')->andReturn($this->textResponse('Hello!'));
        $provider->shouldReceive('completeWithTools')->never();

        $service = $this->service($provider, $registry);
        $response = $service->completeWithTools(
            messages: [['role' => 'user', 'content' => 'hello']],
            model: 'test-model',
            temperature: 0.3,
            maxTokens: null,
            tools: $registry->definitions(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Hello!', $response->getData(true)['data']['choices'][0]['message']['content']);
    }

    public function test_tool_choice_required_is_not_bypassed_for_greeting(): void
    {
        $registry = new ToolRegistry;
        $registry->register('calculate_vat', static fn (array $args): array => ['vat' => 1], [
            'description' => 'Calculate VAT',
            'parameters' => [
                'type' => 'object',
                'properties' => ['amount' => ['type' => 'number']],
                'required' => ['amount'],
            ],
            'is_write' => false,
        ]);

        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('complete')->andReturn($this->textResponse('tool required'));
        $provider->shouldReceive('completeWithTools')
            ->once()
            ->with(
                Mockery::type('array'),
                Mockery::type('array'),
                Mockery::type('string'),
                Mockery::type('float'),
                Mockery::any(),
                'required'
            )
            ->andReturn($this->textResponse('tool required'));

        $service = $this->service($provider, $registry);
        $response = $service->completeWithTools(
            messages: [['role' => 'user', 'content' => 'hello']],
            model: 'test-model',
            temperature: 0.3,
            maxTokens: null,
            tools: $registry->definitions(),
            toolChoice: 'required',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('tool required', $response->getData(true)['data']['choices'][0]['message']['content']);
    }

    public function test_tool_execution_error_does_not_leak_exception_message(): void
    {
        $registry = new ToolRegistry;
        $registry->register('boom', static function (array $args): array {
            throw new \RuntimeException('secret sql detail');
        }, [
            'description' => 'Boom',
            'parameters' => ['type' => 'object', 'properties' => new \stdClass],
            'is_write' => false,
        ]);

        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('completeWithTools')
            ->once()
            ->andReturn($this->toolCallResponse('boom', '{}'));

        $service = $this->service($provider, $registry);
        $response = $service->completeWithTools(
            messages: [['role' => 'user', 'content' => 'run boom']],
            model: 'test-model',
            temperature: 0.3,
            maxTokens: null,
            tools: $registry->definitions(),
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('tool_execution_failed', $response->getData(true)['error']);
        $this->assertSame('Tool execution failed.', $response->getData(true)['message']);
        $this->assertStringNotContainsString('secret sql detail', $response->getContent());
    }

    public function test_malformed_json_is_rejected_after_validation_attempts(): void
    {
        config(['chat.tools.max_validation_attempts' => 0]);

        $registry = new ToolRegistry;
        $registry->register('calculate_vat', static fn (array $args): array => ['vat' => $args['amount'] * 0.1], [
            'description' => 'Calculate VAT',
            'parameters' => [
                'type' => 'object',
                'properties' => ['amount' => ['type' => 'number']],
                'required' => ['amount'],
            ],
            'is_write' => false,
        ]);

        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('completeWithTools')
            ->once()
            ->andReturn($this->toolCallResponse('calculate_vat', '{'));

        $service = $this->service($provider, $registry);
        $response = $service->completeWithTools(
            messages: [['role' => 'user', 'content' => 'calculate 10']],
            model: 'test-model',
            temperature: 0.3,
            maxTokens: null,
            tools: $registry->definitions(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('validation_failed', $response->getData(true)['error']);
    }

    public function test_malformed_json_can_be_corrected_within_validation_budget(): void
    {
        config(['chat.tools.max_validation_attempts' => 2]);

        $registry = new ToolRegistry;
        $registry->register('calculate_vat', static fn (array $args): array => ['vat' => $args['amount'] * 0.1], [
            'description' => 'Calculate VAT',
            'parameters' => [
                'type' => 'object',
                'properties' => ['amount' => ['type' => 'number']],
                'required' => ['amount'],
            ],
            'is_write' => false,
        ]);

        $calls = 0;
        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('completeWithTools')
            ->andReturnUsing(function () use (&$calls) {
                $calls++;

                if ($calls === 1) {
                    return $this->toolCallResponse('calculate_vat', '{');
                }

                return $this->textResponse('corrected');
            });

        $service = $this->service($provider, $registry);
        $response = $service->completeWithTools(
            messages: [['role' => 'user', 'content' => 'calculate 10']],
            model: 'test-model',
            temperature: 0.3,
            maxTokens: null,
            tools: $registry->definitions(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('corrected', $response->getData(true)['data']['choices'][0]['message']['content']);
        $this->assertCount(1, $response->getData(true)['data']['choices'][0]['message']['tool_calls']);
    }

    public function test_unauthorized_tool_is_rejected_before_execution(): void
    {
        $registry = new ToolRegistry;
        $registry->registerTool(new class implements ToolInterface
        {
            public function name(): string
            {
                return 'forbidden_tool';
            }

            public function description(): string
            {
                return 'Forbidden';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => new \stdClass];
            }

            public function isWrite(): bool
            {
                return false;
            }

            public function authorize(mixed $context = null): bool
            {
                return false;
            }

            public function execute(array $arguments): array
            {
                throw new \RuntimeException('must not execute');
            }
        });

        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('completeWithTools')
            ->once()
            ->andReturn($this->toolCallResponse('forbidden_tool', '{}'));

        $service = $this->service($provider, $registry);
        $response = $service->completeWithTools(
            messages: [['role' => 'user', 'content' => 'run forbidden tool']],
            model: 'test-model',
            temperature: 0.3,
            maxTokens: null,
            tools: $registry->definitions(),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('tool_unauthorized', $response->getData(true)['error']);
    }

    public function test_fallback_only_for_infrastructure_errors(): void
    {
        $primary = Mockery::mock(ChatProviderInterface::class);
        $fallback = Mockery::mock(ChatProviderInterface::class);

        $primary->shouldReceive('completeWithTools')
            ->once()
            ->andThrow(new InfrastructureException('upstream 503'));
        $fallback->shouldReceive('completeWithTools')
            ->once()
            ->andReturn($this->textResponse('fallback answer'));

        $provider = new FallbackChatProvider($primary, $fallback, true);
        $result = $provider->completeWithTools([['role' => 'user', 'content' => 'x']], []);

        $this->assertSame('fallback answer', $result['choices'][0]['message']['content']);

        $primary2 = Mockery::mock(ChatProviderInterface::class);
        $fallback2 = Mockery::mock(ChatProviderInterface::class);
        $primary2->shouldReceive('completeWithTools')
            ->once()
            ->andThrow(new ProviderRequestException('upstream 400'));
        $fallback2->shouldReceive('completeWithTools')->never();

        $provider2 = new FallbackChatProvider($primary2, $fallback2, true);

        $this->expectException(ProviderRequestException::class);
        $provider2->completeWithTools([['role' => 'user', 'content' => 'x']], []);
    }

    public function test_tool_loop_stops_at_configured_maximum_rounds(): void
    {
        config(['chat.tools.max_rounds' => 2]);

        $registry = new ToolRegistry;
        $registry->register('calculate_vat', static fn (array $args): array => ['vat' => 1], [
            'description' => 'Calculate VAT',
            'parameters' => [
                'type' => 'object',
                'properties' => ['amount' => ['type' => 'number']],
                'required' => ['amount'],
            ],
            'is_write' => false,
        ]);

        // Distinct arguments each round so the repeated-call detector does NOT
        // short-circuit — this exercises the max-rounds path specifically.
        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('completeWithTools')
            ->times(3)
            ->andReturn(
                $this->toolCallResponse('calculate_vat', '{"amount":10}'),
                $this->toolCallResponse('calculate_vat', '{"amount":20}'),
                $this->toolCallResponse('calculate_vat', '{"amount":30}'),
            );
        // After the budget is exhausted a forced synthesis pass runs; the mock
        // yields no content, so the loop-limit error is preserved.
        $provider->shouldReceive('complete')
            ->andReturn($this->textResponse(''));

        $service = $this->service($provider, $registry);
        $response = $service->completeWithTools(
            messages: [['role' => 'user', 'content' => 'calculate 10']],
            model: 'test-model',
            temperature: 0.3,
            maxTokens: null,
            tools: $registry->definitions(),
            maxToolRounds: 2,
        );

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('tool_loop_limit', $response->getData(true)['error']);
    }

    public function test_repeated_identical_tool_calls_force_synthesis(): void
    {
        config(['chat.tools.max_rounds' => 5]);

        $registry = new ToolRegistry;
        $registry->register('calculate_vat', static fn (array $args): array => ['vat' => 1], [
            'description' => 'Calculate VAT',
            'parameters' => [
                'type' => 'object',
                'properties' => ['amount' => ['type' => 'number']],
                'required' => ['amount'],
            ],
            'is_write' => false,
        ]);

        // Initial + one follow-up return the SAME call; the next round repeats
        // it, triggering duplicate detection before another follow-up.
        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('completeWithTools')
            ->times(2)
            ->andReturn(
                $this->toolCallResponse('calculate_vat', '{"amount":10}'),
                $this->toolCallResponse('calculate_vat', '{"amount":10}'),
            );
        // Forced synthesis returns a real answer -> the user still gets a reply.
        $provider->shouldReceive('complete')
            ->andReturn($this->textResponse('final answer'));

        $service = $this->service($provider, $registry);
        $response = $service->completeWithTools(
            messages: [['role' => 'user', 'content' => 'calculate 10']],
            model: 'test-model',
            temperature: 0.3,
            maxTokens: null,
            tools: $registry->definitions(),
            maxToolRounds: 5,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', $response->getData(true)['status']);
        $this->assertSame(
            'final answer',
            $response->getData(true)['data']['choices'][0]['message']['content'],
        );
    }

    public function test_tool_result_is_appended_with_role_and_tool_call_id(): void
    {
        $registry = new ToolRegistry;
        $registry->register('calculate_vat', static fn (array $args): array => ['vat' => 1], [
            'description' => 'Calculate VAT',
            'parameters' => [
                'type' => 'object',
                'properties' => ['amount' => ['type' => 'number']],
                'required' => ['amount'],
            ],
            'is_write' => false,
        ]);

        $captured = null;
        $calls = 0;
        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('completeWithTools')
            ->andReturnUsing(function (array $messages) use (&$captured, &$calls) {
                $calls++;

                if ($calls === 1) {
                    return $this->toolCallResponse('calculate_vat', '{"amount":10}');
                }

                $captured = $messages;

                return $this->textResponse('done');
            });

        $service = $this->service($provider, $registry);
        $service->completeWithTools(
            messages: [['role' => 'user', 'content' => 'calculate 10']],
            model: 'test-model',
            temperature: 0.3,
            maxTokens: null,
            tools: $registry->definitions(),
        );

        $toolMessage = null;
        foreach ($captured as $message) {
            if (($message['role'] ?? null) === 'tool') {
                $toolMessage = $message;
                break;
            }
        }

        $this->assertNotNull($toolMessage);
        $this->assertSame('call_1', $toolMessage['tool_call_id']);
    }

    public function test_conversation_context_survives_and_null_context_is_compatible(): void
    {
        $repository = new DatabaseConversationRepository;

        $conversation = ChatConversation::create(['user_id' => 'user-1', 'title' => null]);
        $repository->saveContext((int) $conversation->id, ['last_tool' => 'calculate_vat']);

        $this->assertSame('calculate_vat', $repository->getContext((int) $conversation->id)['last_tool']);

        $legacy = ChatConversation::create(['user_id' => 'user-2', 'title' => null]);
        $this->assertSame([], $repository->getContext((int) $legacy->id));
    }

    public function test_streaming_tool_results_are_sanitized(): void
    {
        $registry = new ToolRegistry;
        $registry->register('candidate_lookup', static fn (array $args): array => [
            'full_name' => 'Ali Reza',
            'email' => 'ali@example.com',
            'skills' => ['PHP'],
        ], [
            'description' => 'Lookup',
            'parameters' => ['type' => 'object', 'properties' => new \stdClass],
            'is_write' => false,
        ]);

        $calls = 0;
        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('completeWithTools')
            ->andReturnUsing(function () use (&$calls) {
                $calls++;

                if ($calls === 1) {
                    return $this->toolCallResponse('candidate_lookup', '{}');
                }

                return $this->textResponse('done');
            });

        $service = $this->service($provider, $registry);
        $events = iterator_to_array($service->completeWithToolsStreaming(
            messages: [['role' => 'user', 'content' => 'lookup candidate']],
            model: 'test-model',
            temperature: 0.3,
            maxTokens: null,
            tools: $registry->definitions(),
        ));

        $toolEnd = null;
        foreach ($events as $event) {
            if (($event['event'] ?? null) === 'tool_execution_end') {
                $toolEnd = $event['data'];
                break;
            }
        }

        $this->assertNotNull($toolEnd);
        $this->assertSame('[redacted]', $toolEnd['result']['full_name']);
        $this->assertStringNotContainsString('ali@example.com', json_encode($toolEnd));
    }

    protected function service(ChatProviderInterface $provider, ToolRegistry $registry): ChatQueryService
    {
        $repository = Mockery::mock(ConversationRepositoryInterface::class);
        $conversation = new ChatConversation;
        $conversation->id = 1;
        $conversation->user_id = 'user-1';

        $repository->shouldReceive('resolveConversation')->andReturn($conversation);
        $repository->shouldReceive('recentMessages')->andReturn([]);
        $repository->shouldReceive('storeMessage')->andReturnNull();
        $repository->shouldReceive('getContext')->andReturn([]);
        $repository->shouldReceive('saveContext')->andReturnNull();

        $history = new ConversationHistoryService($repository);
        $retriever = Mockery::mock(RagRetrieverInterface::class);
        $promptBuilder = new PromptBuilder($retriever);
        $llmClassifier = new LLMClassifier(
            $provider,
            new PromptRepository(300),
            new IntentParser,
        );

        return new ChatQueryService(
            llmProvider: $provider,
            intentParser: new IntentParser,
            llmClassifier: $llmClassifier,
            promptBuilder: $promptBuilder,
            retriever: $retriever,
            history: $history,
            toolRegistry: $registry,
        );
    }

    protected function textResponse(string $content): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'test-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
        ];
    }

    protected function toolCallResponse(string $name, string $arguments): array
    {
        return [
            'id' => 'chatcmpl-tool',
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'test-model',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => $name, 'arguments' => $arguments],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ];
    }
}
