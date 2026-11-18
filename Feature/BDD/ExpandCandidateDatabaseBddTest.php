<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Database\Models\Candidate;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpandCandidateDatabaseBddTest extends TestCase
{
    public function test_hpht_candidates_have_required_skills(): void
    {
        $hphtCandidates = DB::table('candidates')
            ->where('external_id', 'like', 'HPHT-%')
            ->get();

        $this->assertNotEmpty($hphtCandidates, 'HPHT candidates should exist in the database');

        foreach ($hphtCandidates as $c) {
            $skills = json_decode($c->skills, true) ?? [];
            $this->assertNotEmpty($skills, "HPHT candidate {$c->external_id} should have skills");
            $this->assertContains('HPHT', $skills, "HPHT candidate {$c->external_id} should list HPHT skill");
        }
    }

    public function test_hpht_candidates_have_high_experience_years(): void
    {
        $hphtCandidates = DB::table('candidates')
            ->where('external_id', 'like', 'HPHT-%')
            ->get();

        $this->assertNotEmpty($hphtCandidates);

        foreach ($hphtCandidates as $c) {
            $this->assertGreaterThanOrEqual(
                5,
                $c->experience_years,
                "HPHT candidate {$c->external_id} should have >= 5 years experience"
            );
        }
    }

    public function test_hpht_candidates_have_raw_profile(): void
    {
        $hphtCandidates = DB::table('candidates')
            ->where('external_id', 'like', 'HPHT-%')
            ->get();

        $this->assertNotEmpty($hphtCandidates);

        foreach ($hphtCandidates as $c) {
            $this->assertNotNull($c->raw_profile, "HPHT candidate {$c->external_id} should have raw_profile");
            $this->assertIsString($c->raw_profile);
            $this->assertNotEmpty($c->raw_profile);
        }
    }

    public function test_candidate_model_can_be_instantiated_with_hpht_data(): void
    {
        $data = [
            'external_id' => 'HPHT-001',
            'full_name' => 'Test HPHT Candidate',
            'skills' => ['HPHT', 'High-Pressure Piping', 'SolidWorks'],
            'experience_years' => 8,
            'source' => 'jobvision',
            'status' => Candidate::STATUS_APPLIED,
        ];

        $candidate = new Candidate($data);

        $this->assertEquals('HPHT-001', $candidate->external_id);
        $this->assertEquals('Test HPHT Candidate', $candidate->full_name);
        $this->assertContains('HPHT', $candidate->skills);
        $this->assertEquals(8, $candidate->experience_years);
        $this->assertTrue($candidate->hasSkill('HPHT'));
    }

    public function test_candidate_model_status_transitions_work(): void
    {
        $candidate = new Candidate(['external_id' => 'TEST-001', 'full_name' => 'Test']);

        $candidate->updateStatus(Candidate::STATUS_SHORTLISTED);
        $this->assertEquals(Candidate::STATUS_SHORTLISTED, $candidate->status);

        $candidate->updateStatus(Candidate::STATUS_REJECTED);
        $this->assertEquals(Candidate::STATUS_REJECTED, $candidate->status);

        $candidate->updateStatus(Candidate::STATUS_HIRED);
        $this->assertEquals(Candidate::STATUS_HIRED, $candidate->status);
    }
}