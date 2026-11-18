<?php

namespace Tests\Unit;

use App\Modules\Chat\Contracts\ChatProviderInterface;
use App\Modules\Chat\Models\Prompt;
use App\Modules\Chat\Repositories\PromptRepository;
use App\Modules\Chat\Services\IntentParser;
use App\Modules\Chat\Services\LLMClassifier;
use Mockery;
use Tests\TestCase;

class LLMClassifierTest extends TestCase
{
    public function test_classifier_returns_llm_intent_when_valid_json(): void
    {
        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('complete')->once()->andReturn([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'intent' => 'candidate_search',
                    'confidence' => 0.9,
                    'reasoning' => 'user wants candidates',
                    'suggested_tools' => ['candidates'],
                    'extracted_params' => ['skills' => [], 'location' => null],
                ])],
                'finish_reason' => 'stop',
            ]],
        ]);

        $repo = Mockery::mock(PromptRepository::class);
        $repo->shouldReceive('getClassifierPrompt')->andReturn(new Prompt(['content' => 'classify']));

        $classifier = new LLMClassifier($provider, $repo, new IntentParser);

        $result = $classifier->classify([['role' => 'user', 'content' => 'پیدا کن مهندس مکانیک']]);

        $this->assertSame('candidate_search', $result['intent']);
        $this->assertSame('candidates', $result['collection']);
        $this->assertSame(0.9, $result['confidence']);
        $this->assertSame(['candidates'], $result['suggested_tools']);
    }

    public function test_classifier_falls_back_to_deterministic_on_bad_json(): void
    {
        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('complete')->once()->andReturn([
            'choices' => [[
                'message' => ['content' => 'not json at all'],
                'finish_reason' => 'stop',
            ]],
        ]);

        $repo = Mockery::mock(PromptRepository::class);
        $repo->shouldReceive('getClassifierPrompt')->andReturn(new Prompt(['content' => 'classify']));

        $classifier = new LLMClassifier($provider, $repo, new IntentParser);

        $result = $classifier->classify([['role' => 'user', 'content' => 'چند شغل داریم؟']]);

        $this->assertSame('job_count', $result['intent']);
        $this->assertSame('jobvision-chat', $result['collection']);
    }

    public function test_classifier_falls_back_on_low_confidence(): void
    {
        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('complete')->once()->andReturn([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'intent' => 'candidate_search',
                    'confidence' => 0.4,
                    'reasoning' => 'unsure',
                    'suggested_tools' => [],
                    'extracted_params' => [],
                ])],
                'finish_reason' => 'stop',
            ]],
        ]);

        $repo = Mockery::mock(PromptRepository::class);
        $repo->shouldReceive('getClassifierPrompt')->andReturn(new Prompt(['content' => 'classify']));

        $classifier = new LLMClassifier($provider, $repo, new IntentParser);

        $result = $classifier->classify([['role' => 'user', 'content' => 'سلام']]);

        // Low confidence (< 0.7) falls back to deterministic IntentParser -> 'chat'
        $this->assertSame('chat', $result['intent']);
    }

    public function test_classifier_uses_default_prompt_when_db_missing(): void
    {
        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('complete')->once()->andReturn([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'intent' => 'chat', 'confidence' => 0.9, 'reasoning' => 'hi',
                    'suggested_tools' => [], 'extracted_params' => [],
                ])],
                'finish_reason' => 'stop',
            ]],
        ]);

        $repo = Mockery::mock(PromptRepository::class);
        $repo->shouldReceive('getClassifierPrompt')->andReturn(null);

        $classifier = new LLMClassifier($provider, $repo, new IntentParser);

        $result = $classifier->classify([['role' => 'user', 'content' => 'سلام']]);

        $this->assertSame('chat', $result['intent']);
    }

    public function test_classifier_disabled_uses_deterministic_only(): void
    {
        $this->app['config']->set('chat.classifier.enabled', false);

        $provider = Mockery::mock(ChatProviderInterface::class);
        $provider->shouldReceive('complete')->never();

        $repo = Mockery::mock(PromptRepository::class);
        $repo->shouldReceive('getClassifierPrompt')->never();

        $classifier = new LLMClassifier($provider, $repo, new IntentParser);

        $result = $classifier->classify([['role' => 'user', 'content' => 'چند شغل داریم؟']]);

        $this->assertSame('job_count', $result['intent']);
    }
}
