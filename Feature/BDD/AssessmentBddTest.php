<?php

namespace Tests\Feature\BDD;

use Tests\TestCase;
use App\Models\Candidate;
use App\Models\CandidateAssessment;
use App\Models\ScreeningResult;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Str;

/**
 * BDD Feature: Candidate Assessment Management
 *
 * As a Recruiter
 * I want to assess candidates with technical and cultural evaluations
 * So that I can score and shortlist qualified candidates
 */
class AssessmentBddTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

#[Test]
    public function testARecruiter_can_create_assessment_for_existing_candidate()
    {
        // Given an existing candidate in the database
        $candidate = Candidate::create([
            'id' => Str::uuid()->toString(),
            'external_id' => 'candidate-001',
            'full_name' => 'Test Developer',
            'source' => 'manual',
            'skills' => ['PHP', 'Laravel', 'MySQL'],
            'status' => 'applied',
        ]);

        $assessmentPayload = [
            'candidate_id' => $candidate->id,
            'job_title' => 'Senior Developer',
            'technical_score' => 0.85,
            'cultural_score' => 0.90,
            'reasoning' => [
                'skills_match' => 'Strong technical background',
                'cultural_fit' => 'Good team fit',
            ],
            'shortlisted' => true,
        ];

        // When the recruiter submits the assessment
        $response = $this->postJson('/api/v1/assessments', $assessmentPayload);

        // Then the system should accept the assessment
        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'assessment' => [
                'id', 'candidate_id', 'technical_score', 'cultural_score', 'overall_score', 'shortlisted',
            ],
        ]);
        $this->assertEquals(0.875, $response->json('assessment.overall_score'));
        $this->assertTrue($response->json('assessment.shortlisted'));

        // And an assessment record should be created in the database
        $this->assertDatabaseHas('candidate_assessments', [
            'candidate_id' => $candidate->id,
            'technical_score' => 0.85,
            'cultural_score' => 0.90,
            'overall_score' => 0.875,
            'shortlisted' => true,
        ]);
    }

#[Test]
    public function testARecruiter_can_retrieve_single_assessment_by_id()
    {
        // Given an assessment exists in the database
        $candidate = Candidate::create([
            'id' => Str::uuid()->toString(),
            'external_id' => 'candidate-002',
            'full_name' => 'Test Engineer',
            'source' => 'manual',
            'skills' => ['Java', 'Spring'],
            'status' => 'reviewed',
        ]);

        $assessment = CandidateAssessment::create([
            'id' => Str::uuid()->toString(),
            'candidate_id' => $candidate->id,
            'job_title' => 'Developer',
            'technical_score' => 0.75,
            'cultural_score' => 0.80,
            'overall_score' => 0.775,
            'shortlisted' => false,
            'reasoning' => ['Good skills match'],
        ]);

        // When the recruiter retrieves the assessment by ID
        $response = $this->getJson("/api/v1/assessments/{$assessment->id}");

        // Then the system should return the assessment
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'assessment' => [
                'id', 'candidate_id', 'technical_score', 'cultural_score', 'overall_score', 'shortlisted',
            ],
        ]);
        $this->assertEquals($assessment->id, $response->json('assessment.id'));
        $this->assertEquals($candidate->id, $response->json('assessment.candidate_id'));
        $this->assertEquals(0.775, $response->json('assessment.overall_score'));
    }

    #[Test]
    public function testARecruiter_can_update_assessment_scoring()
    {
        // Given an existing assessment
        $candidate = Candidate::create([
            'id' => Str::uuid()->toString(),
            'external_id' => 'candidate-003',
            'full_name' => 'Test Analyst',
            'source' => 'manual',
            'skills' => ['SQL', 'Data Analysis'],
            'status' => 'reviewed',
        ]);

        $assessment = CandidateAssessment::create([
            'id' => Str::uuid()->toString(),
            'candidate_id' => $candidate->id,
            'job_title' => 'Analyst',
            'technical_score' => 0.80,
            'cultural_score' => 0.85,
            'overall_score' => 0.825,
            'shortlisted' => true,
            'reasoning' => ['Good fit'],
        ]);

        $updatePayload = [
            'technical_score' => 0.90,
            'cultural_score' => 0.95,
            'shortlisted' => false,
        ];

        // When the recruiter updates the assessment
        $response = $this->putJson("/api/v1/assessments/{$assessment->id}", $updatePayload);

        // Then the system should update the assessment
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'assessment' => [
                'id', 'technical_score', 'cultural_score', 'overall_score', 'shortlisted',
            ],
        ]);
        $this->assertEquals(0.925, $response->json('assessment.overall_score'));
        $this->assertFalse($response->json('assessment.shortlisted'));

        // And the database should be updated
        $this->assertDatabaseHas('candidate_assessments', [
            'id' => $assessment->id,
            'technical_score' => 0.90,
            'cultural_score' => 0.95,
            'overall_score' => 0.925,
            'shortlisted' => false,
        ]);
    }

    #[Test]
    public function assessment_requires_authentication()
    {
        // Given no authenticated user
        $this->app['auth']->guard()->logout();

        // When a request is made without authentication
        $response = $this->postJson('/api/v1/assessments', [
            'candidate_id' => Str::uuid(),
            'technical_score' => 0.85,
            'cultural_score' => 0.90,
        ]);

        // Then the system should return 401 Unauthorized
        $response->assertStatus(401);
        $response->assertJsonStructure(['success', 'error', 'message']);
    }

    #[Test]
    public function assessment_returns_422_for_invalid_data()
    {
        // Given an invalid payload (missing required fields)
        // When the recruiter submits the request
        $response = $this->postJson('/api/v1/assessments', [
            'candidate_id' => Str::uuid(),
            // missing technical_score and cultural_score
        ]);

        // Then the system should return 422 validation error
        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'messages']);
    }

    #[Test]
    public function assessment_returns_404_for_nonexistent_id()
    {
        // When a request is made for a non-existent assessment
        $response = $this->getJson('/api/v1/assessments/non-existent-id');

        // Then the system should return 404
        $response->assertStatus(404);
        $response->assertJsonStructure(['error', 'message']);
    }
}