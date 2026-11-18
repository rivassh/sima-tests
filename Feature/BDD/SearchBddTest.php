<?php

namespace Tests\Feature\BDD;

use Tests\TestCase;
use App\Models\Candidate;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * BDD Feature: Candidate Search
 *
 * As a Recruiter
 * I want to search and filter candidates
 * So that I can find the best matches for open positions
 */
class SearchBddTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }
#[Test]
    public function testARecruiter_can_search_candidates_by_skills()
    {
        // Given candidates with various skills exist in the database
        $candidate1 = Candidate::create([
            'id' => Str::uuid()->toString(),
            'external_id' => 'search-cand-001',
            'full_name' => 'PHP Developer',
            'source' => 'manual',
            'skills' => ['PHP', 'Laravel', 'MySQL'],
            'status' => 'applied',
        ]);

        $candidate2 = Candidate::create([
            'id' => Str::uuid()->toString(),
            'external_id' => 'search-cand-002',
            'full_name' => 'Java Developer',
            'source' => 'manual',
            'skills' => ['Java', 'Spring', 'MySQL'],
            'status' => 'applied',
        ]);

        // When the recruiter searches for PHP candidates
        $response = $this->getJson('/api/v1/search/candidates?skills=PHP');

        // Then the system should return matching candidates
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'candidates' => [
                '*' => ['id', 'full_name', 'skills', 'status'],
            ],
        ]);
    }

#[Test]
    public function testARecruiter_can_use_autocomplete_for_candidate_search()
    {
        // Given candidates with various names exist
        $candidate = Candidate::create([
            'id' => Str::uuid()->toString(),
            'external_id' => 'search-cand-003',
            'full_name' => 'John Smith',
            'source' => 'manual',
            'skills' => ['PHP'],
            'status' => 'applied',
        ]);

        // When the recruiter uses autocomplete
        $response = $this->getJson('/api/v1/search/autocomplete?q=John');

        // Then the system should return autocomplete suggestions
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'suggestions' => [
                '*' => ['id', 'full_name', 'skills'],
            ],
        ]);
    }

#[Test]
    public function testARecruiter_can_get_search_facets()
    {
        // Given candidates with various attributes exist
        // When the recruiter requests search facets
        $response = $this->getJson('/api/v1/search/facets');

        // Then the system should return available facets
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'facets' => [
                'skills',
                'sources',
                'status',
            ],
        ]);
    }

#[Test]
    public function testARecruiter_can_get_popular_searches()
    {
        // Given search history data exists
        // When the recruiter requests popular searches
        $response = $this->getJson('/api/v1/search/popular');

        // Then the system should return popular search terms
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'popular_searches' => [
                '*' => ['query', 'count'],
            ],
        ]);
    }

#[Test]
    public function testARecruiter_can_get_dashboard_search_stats()
    {
        // Given search data exists
        // When the recruiter requests dashboard stats
        $response = $this->getJson('/api/v1/search/dashboard-stats');

        // Then the system should return dashboard statistics
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'stats' => [
                'total_candidates',
                'recent_searches',
                'top_skills',
            ],
        ]);
    }

#[Test]
    public function search_endpoints_require_authentication()
    {
        // Given no authenticated user
        $this->app['auth']->guard()->logout();

        // When a request is made without authentication
        $response = $this->getJson('/api/v1/search/candidates');

        // Then the system should return 401 Unauthorized
        $response->assertStatus(401);
        $response->assertJsonStructure(['success', 'error', 'message']);
    }

#[Test]
    public function search_returns_empty_results_for_no_matches()
    {
        // Given no candidates match the search criteria
        // When the recruiter searches for non-existent skills
        $response = $this->getJson('/api/v1/search/candidates?skills=NonExistentSkill');

        // Then the system should return empty results
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'candidates' => [],
        ]);
        $this->assertCount(0, $response->json('candidates'));
    }
}