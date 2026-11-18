<?php

declare(strict_types=1);

namespace Tests\Feature\ChatTools;

use App\Modules\Chat\Contracts\ChatProviderInterface;
use App\Modules\Chat\Contracts\CollectionRegistryInterface;
use App\Modules\Chat\Contracts\RagRetrieverInterface;
use App\Modules\Chat\Contracts\ToolInterface;
use App\Modules\Chat\Rag\VectorRetrieval;
use App\Modules\Chat\Tools\HybridSearchTool;
use App\Modules\Chat\Tools\JobAnalysisTool;
use App\Modules\Chat\Tools\ToolRegistry;
use App\Modules\Chat\Tools\VectorSearchTool;
use Mockery;
use Tests\TestCase;

class VectorSearchToolsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function vectorTool(VectorRetrieval $vector, CollectionRegistryInterface $registry): VectorSearchTool
    {
        return new VectorSearchTool($vector, $registry);
    }

    private function vectorRow(string $sourceId, string $title, string $content, float $distance): array
    {
        return [
            'id' => 'chunk-'.$sourceId,
            'collection' => 'jobvision-chat',
            'source_id' => $sourceId,
            'chunk_index' => 0,
            'content' => $content,
            'metadata' => ['source_type' => 'job_post', 'source_id' => $sourceId, 'title' => $title],
            'distance' => $distance,
            'doc_type' => 'job_post',
        ];
    }

    public function test_vector_search_returns_formatted_summary_and_filters_by_threshold(): void
    {
        $vector = Mockery::mock(VectorRetrieval::class);
        $vector->shouldReceive('search')
            ->once()
            ->with('jobvision-chat', 'مهندس مکانیک', 10)
            ->andReturn([
                $this->vectorRow('1', 'Mechanical Engineer', 'توضیحات شغل مکانیک', 0.20),
                $this->vectorRow('2', 'Far below threshold', 'دور', 1.50),
            ]);
        $vector->shouldReceive('available')->andReturn(true);

        $registry = Mockery::mock(CollectionRegistryInterface::class);
        $registry->shouldReceive('has')->with('jobvision-chat')->andReturn(true);

        $result = ($this->vectorTool($vector, $registry))([
            'collection' => 'jobvision-chat',
            'query' => 'مهندس مکانیک',
        ]);

        $this->assertSame('مهندس مکانیک', $result['query']);
        $this->assertSame('jobvision-chat', $result['collection']);
        $this->assertSame(1, $result['total_found']);
        $this->assertCount(1, $result['top_results']);
        $this->assertSame('1', $result['top_results'][0]['id']);
        $this->assertSame('Mechanical Engineer', $result['top_results'][0]['title']);
        $this->assertSame(0.8, $result['top_results'][0]['score']);
        $this->assertSame(1, $result['search_stats']['filtered_by_threshold']);
        $this->assertTrue($result['search_stats']['vector_available']);
    }

    public function test_vector_search_reports_unknown_collection(): void
    {
        $vector = Mockery::mock(VectorRetrieval::class);
        $vector->shouldNotReceive('search');

        $registry = Mockery::mock(CollectionRegistryInterface::class);
        $registry->shouldReceive('has')->with('nope')->andReturn(false);
        $registry->shouldReceive('names')->andReturn(['jobvision-chat', 'candidates']);

        $result = ($this->vectorTool($vector, $registry))([
            'collection' => 'nope',
            'query' => 'x',
        ]);

        $this->assertSame('UNKNOWN_COLLECTION', $result['error_code']);
        $this->assertContains('jobvision-chat', $result['available_collections']);
    }

    public function test_vector_search_requires_collection_and_query(): void
    {
        $vector = Mockery::mock(VectorRetrieval::class);
        $registry = Mockery::mock(CollectionRegistryInterface::class);

        $result = ($this->vectorTool($vector, $registry))(['collection' => 'jobvision-chat']);

        $this->assertSame('INVALID_ARGS', $result['error_code']);
    }

    public function test_vector_search_returns_hint_when_no_chunks_indexed(): void
    {
        $vector = Mockery::mock(VectorRetrieval::class);
        $vector->shouldReceive('search')->once()->andReturn([]);
        $vector->shouldReceive('available')->andReturn(true);

        $registry = Mockery::mock(CollectionRegistryInterface::class);
        $registry->shouldReceive('has')->with('jobvision-chat')->andReturn(true);

        $result = ($this->vectorTool($vector, $registry))([
            'collection' => 'jobvision-chat',
            'query' => 'anything',
        ]);

        $this->assertSame(0, $result['total_found']);
        $this->assertArrayHasKey('hint', $result);
        $this->assertStringContainsString('not been built', $result['hint']);
    }

    public function test_vector_search_has_no_hint_when_results_exist(): void
    {
        $vector = Mockery::mock(VectorRetrieval::class);
        $vector->shouldReceive('search')->once()->andReturn([
            $this->vectorRow('1', 'A role', 'content', 0.10),
        ]);
        $vector->shouldReceive('available')->andReturn(true);

        $registry = Mockery::mock(CollectionRegistryInterface::class);
        $registry->shouldReceive('has')->andReturn(true);

        $result = ($this->vectorTool($vector, $registry))([
            'collection' => 'jobvision-chat',
            'query' => 'x',
        ]);

        $this->assertArrayNotHasKey('hint', $result);
    }

    public function test_vector_search_applies_metadata_filters(): void
    {
        $vector = Mockery::mock(VectorRetrieval::class);
        $vector->shouldReceive('search')->once()->andReturn([
            $this->vectorRow('1', 'Job Post', 'content', 0.10),
            $this->vectorRow('2', 'Other', 'content', 0.10),
        ]);
        $vector->shouldReceive('available')->andReturn(false);

        $registry = Mockery::mock(CollectionRegistryInterface::class);
        $registry->shouldReceive('has')->andReturn(true);

        // Filter on a top-level field (doc_type) so only job_post rows survive.
        $result = ($this->vectorTool($vector, $registry))([
            'collection' => 'jobvision-chat',
            'query' => 'x',
            'filters' => ['doc_type' => 'job_post'],
        ]);

        $this->assertSame(2, $result['total_found']);
    }

    public function test_hybrid_search_appends_keywords_to_query(): void
    {
        $hybrid = Mockery::mock(RagRetrieverInterface::class);
        $hybrid->shouldReceive('retrieve')
            ->once()
            ->with('jobvision-chat', 'مهندس مکانیک SolidWorks', 10)
            ->andReturn([
                (object) [
                    'content' => 'job body',
                    'metadata' => (object) ['doc_id' => '42', 'title' => 'Mechanical Engineer'],
                    'distance' => 0.0,
                ],
            ]);

        $registry = Mockery::mock(CollectionRegistryInterface::class);
        $registry->shouldReceive('has')->with('jobvision-chat')->andReturn(true);

        $tool = new HybridSearchTool($hybrid, $registry);

        $result = $tool([
            'collection' => 'jobvision-chat',
            'query' => 'مهندس مکانیک',
            'keywords' => ['SolidWorks'],
        ]);

        $this->assertSame('jobvision-chat', $result['collection']);
        $this->assertSame(1, $result['total_found']);
        $this->assertSame('42', $result['top_results'][0]['id']);
        $this->assertSame('Mechanical Engineer', $result['top_results'][0]['title']);
        $this->assertSame('rrf', $result['search_stats']['fusion']);
    }

    public function test_hybrid_search_reports_unknown_collection(): void
    {
        $hybrid = Mockery::mock(RagRetrieverInterface::class);
        $hybrid->shouldNotReceive('retrieve');

        $registry = Mockery::mock(CollectionRegistryInterface::class);
        $registry->shouldReceive('has')->with('ghost')->andReturn(false);
        $registry->shouldReceive('names')->andReturn(['candidates']);

        $result = (new HybridSearchTool($hybrid, $registry))([
            'collection' => 'ghost',
            'query' => 'x',
        ]);

        $this->assertSame('UNKNOWN_COLLECTION', $result['error_code']);
    }

    public function test_job_analysis_parses_json_and_normalizes_fields(): void
    {
        $content = json_encode([
            'job_requirements' => [
                [
                    'skill' => 'React',
                    'evidence_terms' => ['React', 'ReactJS', 'ری اکت'],
                    'weight' => 1.5, // clamps to 1.0
                    'priority' => 'must_have', // invalid -> important
                    'category' => 'frontend', // invalid -> technical
                    'min_experience' => 3,
                ],
                [
                    'skill' => '',
                    'evidence_terms' => [],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $llm = Mockery::mock(ChatProviderInterface::class);
        $llm->shouldReceive('complete')->once()->andReturn([
            'choices' => [['message' => ['content' => $content]]],
        ]);

        $result = (new JobAnalysisTool($llm))(['job_description' => 'We need a React developer']);

        $this->assertArrayHasKey('job_requirements', $result);
        $this->assertCount(1, $result['job_requirements']);
        $req = $result['job_requirements'][0];
        $this->assertSame('React', $req['skill']);
        $this->assertSame(1.0, $req['weight']);
        $this->assertSame('important', $req['priority']);
        $this->assertSame('technical', $req['category']);
        $this->assertSame(3.0, $req['min_experience']);
    }

    public function test_job_analysis_strips_code_fences(): void
    {
        $content = "```json\n{\"job_requirements\":[{\"skill\":\"Python\"}]}\n```";

        $llm = Mockery::mock(ChatProviderInterface::class);
        $llm->shouldReceive('complete')->once()->andReturn([
            'choices' => [['message' => ['content' => $content]]],
        ]);

        $result = (new JobAnalysisTool($llm))(['job_description' => 'python role']);

        $this->assertSame('Python', $result['job_requirements'][0]['skill']);
        $this->assertSame(['Python'], $result['job_requirements'][0]['evidence_terms']);
    }

    public function test_job_analysis_requires_description(): void
    {
        $llm = Mockery::mock(ChatProviderInterface::class);
        $llm->shouldNotReceive('complete');

        $result = (new JobAnalysisTool($llm))(['job_description' => '']);

        $this->assertSame('INVALID_ARGS', $result['error_code']);
    }

    public function test_job_analysis_reports_llm_failure(): void
    {
        $llm = Mockery::mock(ChatProviderInterface::class);
        $llm->shouldReceive('complete')->once()->andThrow(new \RuntimeException('upstream down'));

        $result = (new JobAnalysisTool($llm))(['job_description' => 'some job']);

        $this->assertSame('ANALYSIS_FAILED', $result['error_code']);
    }

    public function test_job_analysis_reports_parse_failure(): void
    {
        $llm = Mockery::mock(ChatProviderInterface::class);
        $llm->shouldReceive('complete')->once()->andReturn([
            'choices' => [['message' => ['content' => 'not json at all']]],
        ]);

        $result = (new JobAnalysisTool($llm))(['job_description' => 'some job']);

        $this->assertSame('PARSE_FAILED', $result['error_code']);
    }

    public function test_registry_definitions_filters_by_domain_and_supports_protocol(): void
    {
        config([
            'chat.tools.default_write_classification' => 'write',
        ]);

        $registry = new ToolRegistry;

        $registry->registerTool($this->fakeTool('talent_tool', 'talent', ['version' => '1.0', 'category' => 'talent', 'streaming' => true]));
        $registry->registerTool($this->fakeTool('search_tool', 'search', null));
        $registry->registerTool($this->fakeTool('general_tool', 'general', ['version' => '1.0', 'category' => 'general', 'streaming' => true]));

        $talentDefs = $registry->definitions(null, 'talent');
        $names = array_map(fn ($d) => $d['function']['name'], $talentDefs);
        sort($names);
        // Domain-scoped export keeps the requested domain plus "general" tools.
        $this->assertSame(['general_tool', 'talent_tool'], $names);

        $allWithProtocol = $registry->definitions(null, null, true);
        $byName = [];
        foreach ($allWithProtocol as $def) {
            $byName[$def['function']['name']] = $def['function'];
        }
        $this->assertArrayHasKey('protocol', $byName['talent_tool']);
        // A tool that returns null protocol must not advertise the key.
        $this->assertArrayNotHasKey('protocol', $byName['search_tool']);
    }

    /**
     * A minimal real ToolInterface implementation (not a mock) so the
     * registry's method_exists() checks see domain()/protocol().
     */
    private function fakeTool(string $name, string $domain, ?array $protocol): ToolInterface
    {
        return new class($name, $domain, $protocol) implements ToolInterface
        {
            public function __construct(
                private string $toolName,
                private string $toolDomain,
                private ?array $toolProtocol,
            ) {}

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return $this->toolName;
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => new \stdClass];
            }

            public function isWrite(): bool
            {
                return false;
            }

            public function domain(): string
            {
                return $this->toolDomain;
            }

            public function protocol(): ?array
            {
                return $this->toolProtocol;
            }

            public function execute(array $arguments): array
            {
                return [];
            }
        };
    }
}
