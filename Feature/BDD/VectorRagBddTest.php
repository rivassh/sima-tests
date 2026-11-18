<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Modules\Chat\Contracts\CollectionRegistryInterface;
use App\Modules\Chat\Rag\DatabaseRetrieval;
use App\Modules\Chat\Rag\DocumentChunker;
use App\Modules\Chat\Rag\HybridRetrieval;
use App\Modules\Chat\Rag\VectorRetrieval;
use App\Modules\Chat\Services\CollectionIndexer;
use App\Modules\Chat\Services\Embedding\HashingEmbeddingProvider;
use App\Models\JobPost;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BDD Feature: Vector RAG and Multi-Topic Domain Collections
 *
 * As a Platform Operator
 * I want the chat knowledge base to be a set of configurable collections
 * backed by vector search,
 * So that new domain topics (job posts, candidates, org docs, HR policies,
 * engineering standards) can be added without changing the code.
 */
class VectorRagBddTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        JobPost::create([
            'external_id' => '9101',
            'source' => 'jobvision',
            'title' => 'Senior Mechanical Engineer - High Pressure Piping',
            'company' => 'Kroobi Company',
            'location' => 'Tehran',
            'description' => 'Design and construct high pressure piping systems for refineries.',
            'skills' => ['SolidWorks', 'CATIA', 'Metallurgy', 'High Pressure Systems'],
            'experience_years' => 6,
            'employment_type' => 'Full Time',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function every_domain_topic_is_registered_and_listed(): void
    {
        $registry = app(CollectionRegistryInterface::class);

        foreach (['jobvision-chat', 'candidates', 'org-docs', 'hr-policies', 'engineering-standards'] as $name) {
            $this->assertTrue($registry->has($name), "Collection [{$name}] must be registered.");
            $this->assertNotNull($registry->get($name)['label'] ?? null);
        }

        $response = $this->getJson('/api/v1/chat/collections');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'data' => [['id', 'label', 'description', 'source_type', 'doc_type']]]);

        $ids = array_column($response->json('data'), 'id');

        $this->assertContains('jobvision-chat', $ids);
        $this->assertContains('engineering-standards', $ids);
    }

    #[Test]
    public function the_embedding_encoder_is_deterministic_and_normalised(): void
    {
        $provider = new HashingEmbeddingProvider(dimensions: 384);

        $a = $provider->embed('مهندس مکانیک با تجربه SolidWorks');
        $b = $provider->embed('مهندس مکانیک با تجربه SolidWorks');
        $c = $provider->embed('کارشناس فروش بازاریابی دیجیتال');

        $this->assertCount(384, $a);
        $this->assertEquals($a, $b, 'The encoder must be deterministic.');

        $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $a)));
        $this->assertEqualsWithDelta(1.0, $norm, 0.0001, 'The vector must be L2-normalised.');

        $similarity = $this->cosine($a, $b);
        $unrelated = $this->cosine($a, $c);

        $this->assertGreaterThan($unrelated, $similarity);
    }

    #[Test]
    public function documents_are_split_into_overlapping_chunks(): void
    {
        $chunker = new DocumentChunker(chunkSize: 200, overlap: 40);
        $text = str_repeat('خط اول نمونه متن فنی برای آزمون. ', 30);

        $chunks = $chunker->chunk($text);

        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(200, mb_strlen($chunk, 'UTF-8'));
            $this->assertNotSame('', trim($chunk));
        }

        $this->assertSame([], $chunker->chunk('   '));
    }

    #[Test]
    public function the_indexer_builds_a_chunk_corpus_for_a_table_collection(): void
    {
        $indexer = app(CollectionIndexer::class);

        $count = $indexer->index('jobvision-chat');

        $this->assertGreaterThan(0, $count);

        $chunks = app(\App\Modules\Chat\Repositories\DocumentChunkRepository::class)
            ->allForCollection('jobvision-chat');

        $this->assertCount($count, $chunks);
        $this->assertStringContainsString('SolidWorks', $chunks[0]['content']);
    }

    #[Test]
    public function a_file_backed_collection_is_indexed_from_its_source_directory(): void
    {
        $indexer = app(CollectionIndexer::class);

        $count = $indexer->index('org-docs');

        $this->assertGreaterThan(0, $count, 'The org-docs corpus must contain indexed chunks.');

        $chunks = app(\App\Modules\Chat\Repositories\DocumentChunkRepository::class)
            ->allForCollection('org-docs');

        $this->assertStringContainsString('استخدام', $chunks[0]['content']);
    }

    #[Test]
    public function retrieval_falls_back_to_keyword_search_when_the_vector_column_is_absent(): void
    {
        // The SQLite test database has no `vector` column, so the vector leg
        // must degrade instead of erroring.
        $this->assertFalse(app(VectorRetrieval::class)->available());

        $retriever = app(\App\Modules\Chat\Contracts\RagRetrieverInterface::class);
        $this->assertInstanceOf(HybridRetrieval::class, $retriever);

        $results = $retriever->retrieve('jobvision-chat', 'مهندس مکانیک SolidWorks', 5);

        $this->assertNotEmpty($results);
        $this->assertStringContainsString('Senior Mechanical Engineer', $results[0]->content);
    }

    #[Test]
    public function an_unknown_collection_yields_no_results_instead_of_a_wrong_fallback(): void
    {
        $results = app(DatabaseRetrieval::class)->retrieve('does-not-exist', 'anything', 5);

        $this->assertSame([], $results);
    }

    /**
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * ($b[$i] ?? 0.0);
        }

        return $dot;
    }
}
