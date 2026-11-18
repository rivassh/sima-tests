<?php

namespace Tests\Unit;

use App\Modules\Chat\Contracts\RagRetrieverInterface;
use App\Modules\Chat\Models\Prompt;
use App\Modules\Chat\Repositories\PromptRepository;
use App\Modules\Chat\Services\PromptBuilder;
use Mockery;
use Tests\TestCase;

class PromptBuilderTest extends TestCase
{
    public function test_render_template_replaces_scalar_variables(): void
    {
        $builder = new PromptBuilder(Mockery::mock(RagRetrieverInterface::class));

        $result = $builder->renderTemplate(
            'Date: {{current_date}}, lang: {{user_language}}',
            ['current_date' => '2026-11-17', 'user_language' => 'fa']
        );

        $this->assertSame('Date: 2026-11-17, lang: fa', $result);
    }

    public function test_render_template_ignores_non_scalar_and_unsafe_keys(): void
    {
        $builder = new PromptBuilder(Mockery::mock(RagRetrieverInterface::class));

        $result = $builder->renderTemplate(
            '{{name}} {{bad key}} {{arr}}',
            ['name' => 'sima', 'bad key' => 'x', 'arr' => [1, 2]]
        );

        $this->assertStringContainsString('sima', $result);
        $this->assertStringContainsString('{{bad key}}', $result);
        $this->assertStringContainsString('{{arr}}', $result);
    }

    public function test_build_system_prompt_returns_empty_when_no_prompts(): void
    {
        $repo = Mockery::mock(PromptRepository::class);
        $repo->shouldReceive('getActiveBasePrompt')->andReturn(null);
        $repo->shouldReceive('getActiveTopicPrompt')->andReturn(null);

        $builder = new PromptBuilder(
            Mockery::mock(RagRetrieverInterface::class),
            $repo
        );

        $this->assertSame('', $builder->buildSystemPrompt('chat'));
    }

    public function test_build_system_prompt_assembles_base_and_topic(): void
    {
        $base = new Prompt([
            'slug' => 'base.persona',
            'content' => 'You are {{user_language}} assistant.',
        ]);
        $topic = new Prompt([
            'slug' => 'topic.candidate_search',
            'content' => 'Candidate search rules for {{current_date}}.',
        ]);

        $repo = Mockery::mock(PromptRepository::class);
        $repo->shouldReceive('getActiveBasePrompt')->andReturn($base);
        $repo->shouldReceive('getActiveTopicPrompt')->with('candidate_search')->andReturn($topic);

        $builder = new PromptBuilder(
            Mockery::mock(RagRetrieverInterface::class),
            $repo
        );

        $result = $builder->buildSystemPrompt('candidate_search', [
            'user_language' => 'fa',
            'current_date' => '2026-11-17',
        ]);

        $this->assertStringContainsString('You are fa assistant.', $result);
        $this->assertStringContainsString('Candidate search rules for 2026-11-17.', $result);
        $this->assertStringContainsString('---', $result);
    }
}
