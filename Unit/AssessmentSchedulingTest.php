<?php

declare(strict_types=1);

use App\Models\Candidate;
use App\Models\CandidateAssessment;
use App\Modules\Assessment\Models\AssessmentTemplate;
use App\Modules\Assessment\Services\AssessmentSchedulingService;
use App\Modules\ATS\Models\JobPost;
use App\Modules\ATS\Models\Shortlist;
use App\Modules\ATS\Models\ShortlistItem;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Run migrations (same order as AssessmentModuleTest working pattern)
    $this->artisan('migrate', ['--path' => 'app/Modules/ATS/database/migrations', '--force' => true]);
    $this->artisan('migrate', ['--path' => 'app/Modules/Assessment/database/migrations', '--force' => true]);
});

describe('AssessmentSchedulingService', function () {
    it('throws exception when shortlist not found', function () {
        $service = new AssessmentSchedulingService();

        expect(fn() => $service->scheduleAssessmentForShortlisted('non-existent-id', 'non-existent-item'))
            ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    });

    it('throws exception when shortlist item not found', function () {
        $service = new AssessmentSchedulingService();

        $jobPost = JobPost::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'title' => 'Test Job',
            'status' => 'active',
        ]);

        $shortlist = Shortlist::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'job_post_id' => $jobPost->id,
            'name' => 'Test Shortlist',
            'rationale' => 'test',
            'candidates' => [],
            'limit' => 5,
            'status' => 'active',
            'generated_at' => now(),
        ]);

        expect(fn() => $service->scheduleAssessmentForShortlisted($shortlist->id, 'non-existent-item'))
            ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    });

    it('schedules assessment for shortlisted candidate', function () {
        $service = new AssessmentSchedulingService();

        $template = AssessmentTemplate::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Default Technical',
            'type' => 'technical',
            'description' => 'Technical template',
            'weight' => 0.6,
            'config' => json_encode(['test_types' => []]),
            'is_active' => true,
        ]);

        $jobPost = JobPost::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'title' => 'Test Job',
            'status' => 'active',
        ]);

        $candidate = Candidate::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_id' => 'ext-1',
            'source' => 'test',
            'full_name' => 'Candidate 1',
            'email' => 'c1@example.com',
            'phone' => '000',
        ]);

        $shortlist = Shortlist::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'job_post_id' => $jobPost->id,
            'name' => 'Shortlist Single',
            'rationale' => 'test',
            'candidates' => [],
            'limit' => 1,
            'status' => 'active',
            'generated_at' => now(),
        ]);

        $item = ShortlistItem::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'shortlist_id' => $shortlist->id,
            'candidate_id' => $candidate->id,
            'rank' => 1,
            'overall_score' => 0.9,
            'rationale' => 'High score',
            'verdicts' => [],
        ]);

        $assessment = $service->scheduleAssessmentForShortlisted($shortlist->id, $item->id);

        expect($assessment->status)->toBe('scheduled');
        expect($assessment->candidate_id)->toBe($candidate->id);
    });

    it('throws exception when rejecting non-existent item', function () {
        $service = new AssessmentSchedulingService();

        expect(fn() => $service->rejectInvalidType('non-existent-item'))
            ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    });
});