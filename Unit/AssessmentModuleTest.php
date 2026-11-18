<?php

declare(strict_types=1);

use App\Models\CandidateAssessment;
use App\Modules\Assessment\Models\AssessmentTemplate;
use App\Modules\Assessment\Services\AssessmentScoringService;
use App\Modules\Assessment\Services\AssessmentTemplateService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'app/Modules/Assessment/database/migrations', '--force' => true]);
    $this->artisan('migrate', ['--path' => 'app/Modules/ATS/database/migrations', '--force' => true]);
});

describe('AssessmentTemplateService', function () {
    it('creates a template with valid data', function () {
        $service = new AssessmentTemplateService();

        $template = $service->createTemplate([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Unit Test Template',
            'type' => 'technical',
            'description' => 'Unit test description',
            'weight' => 0.5,
            'config' => ['test_types' => ['coding']],
            'is_active' => true,
        ]);

        expect($template)->toBeInstanceOf(AssessmentTemplate::class);
        expect($template->name)->toBe('Unit Test Template');
        expect($template->type)->toBe('technical');
        expect($template->is_active)->toBeTrue();
    });

    it('returns all active templates', function () {
        $service = new AssessmentTemplateService();

        $service->createTemplate([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Active Template',
            'type' => 'technical',
            'description' => 'desc',
            'weight' => 0.6,
            'config' => [],
            'is_active' => true,
        ]);

        $service->createTemplate([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Inactive Template',
            'type' => 'behavioral',
            'description' => 'desc',
            'weight' => 0.4,
            'config' => [],
            'is_active' => false,
        ]);

        $active = $service->getAllActiveTemplates();

        expect($active->count())->toBe(1);
        expect($active->first()->name)->toBe('Active Template');
    });

    it('returns templates by type', function () {
        $service = new AssessmentTemplateService();

        $service->createTemplate([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Behavioral 1',
            'type' => 'behavioral',
            'description' => 'desc',
            'weight' => 0.3,
            'config' => [],
            'is_active' => true,
        ]);

        $service->createTemplate([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Technical 1',
            'type' => 'technical',
            'description' => 'desc',
            'weight' => 0.6,
            'config' => [],
            'is_active' => true,
        ]);

        $behavioral = $service->getTemplatesByType('behavioral');

        expect($behavioral->count())->toBe(1);
        expect($behavioral->first()->type)->toBe('behavioral');
    });

    it('updates an existing template', function () {
        $service = new AssessmentTemplateService();

        $template = $service->createTemplate([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Before Update',
            'type' => 'technical',
            'description' => 'desc',
            'weight' => 0.6,
            'config' => [],
            'is_active' => true,
        ]);

        $result = $service->updateTemplate($template->id, ['name' => 'After Update']);

        expect($result)->toBeTrue();
        expect($template->fresh()->name)->toBe('After Update');
    });

    it('deletes an existing template', function () {
        $service = new AssessmentTemplateService();

        $template = $service->createTemplate([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'To Delete',
            'type' => 'cognitive',
            'description' => 'desc',
            'weight' => 0.1,
            'config' => [],
            'is_active' => true,
        ]);

        $result = $service->deleteTemplate($template->id);

        expect($result)->toBeTrue();
        expect(AssessmentTemplate::find($template->id))->toBeNull();
    });

    it('seeds three default templates', function () {
        $service = new AssessmentTemplateService();

        $service->seedDefaultTemplates();

        expect(AssessmentTemplate::count())->toBe(3);
        expect(AssessmentTemplate::where('type', 'technical')->count())->toBe(1);
        expect(AssessmentTemplate::where('type', 'behavioral')->count())->toBe(1);
        expect(AssessmentTemplate::where('type', 'cognitive')->count())->toBe(1);
    });
});

describe('AssessmentScoringService::calculateWeightedScore', function () {
    it('returns default weights when none provided', function () {
        $service = new AssessmentScoringService();

        $assessments = [
            new CandidateAssessment([
                'id' => 'test-1',
                'candidate_id' => 'cand-1',
                'technical_score' => 0.8,
                'cultural_score' => 0.6,
                'job_title' => 'Engineer',
                'shortlist_id' => 'shortlist-1',
                'assessment_template_id' => 'template-1',
                'status' => 'completed',
            ]),
        ];

        $result = $service->calculateWeightedScore($assessments);

        expect($result['average_score'])->toBe((0.8 * 0.6) + (0.6 * 0.4));
        expect($result['total_assessments'])->toBe(1);
    });

    it('returns zero average when assessments empty', function () {
        $service = new AssessmentScoringService();

        $result = $service->calculateWeightedScore([]);

        expect($result['average_score'])->toBe(0.0);
        expect($result['total_assessments'])->toBe(0);
    });

    it('uses custom weights when provided', function () {
        $service = new AssessmentScoringService();

        $assessments = [
            new CandidateAssessment([
                'id' => 'test-2',
                'candidate_id' => 'cand-2',
                'technical_score' => 1.0,
                'cultural_score' => 0.0,
                'job_title' => 'Engineer',
                'shortlist_id' => 'shortlist-2',
                'assessment_template_id' => 'template-2',
                'status' => 'completed',
            ]),
        ];

        $result = $service->calculateWeightedScore($assessments, ['technical' => 0.8, 'cultural' => 0.2]);

        expect($result['average_score'])->toBe((1.0 * 0.8) + (0.0 * 0.2));
    });
});

describe('CandidateAssessment::shouldShortlist', function () {
    it('returns true when average score >= 0.7', function () {
        $assessment = new CandidateAssessment();

        expect($assessment->shouldShortlist(0.8, 0.6))->toBeTrue();
        expect($assessment->shouldShortlist(0.7, 0.7))->toBeTrue();
    });

    it('returns false when average score < 0.7', function () {
        $assessment = new CandidateAssessment();

        expect($assessment->shouldShortlist(0.6, 0.6))->toBeFalse();
        expect($assessment->shouldShortlist(0.5, 0.8))->toBeFalse();
    });

    it('works at boundary 0.7', function () {
        $assessment = new CandidateAssessment();

        expect($assessment->shouldShortlist(0.7, 0.7))->toBeTrue();
        expect($assessment->shouldShortlist(0.65, 0.7))->toBeFalse();
    });
});

describe('AssessmentScoringService::calculateScoreWithReasoning', function () {
    it('generates detailed reasoning for completed assessment', function () {
        $service = new AssessmentScoringService();

        $assessment = new CandidateAssessment([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'candidate_id' => 'cand-reasoning',
            'technical_score' => 0.9,
            'cultural_score' => 0.7,
            'overall_score' => 0.8,
            'status' => 'completed',
        ]);

        $result = $service->calculateScoreWithReasoning([$assessment], 'detailed');

        expect($result)->toHaveCount(1);
        expect($result[0]['technical_score'])->toBe(0.9);
        expect($result[0]['cultural_score'])->toBe(0.7);
        expect($result[0]['overall_score'])->toBe(0.8);
        expect($result[0]['reasoning'])->toHaveKey('technical_assessment');
        expect($result[0]['reasoning'])->toHaveKey('cultural_assessment');
        expect($result[0]['reasoning'])->toHaveKey('summary');
    });

    it('generates simple reasoning type', function () {
        $service = new AssessmentScoringService();

        $assessment = new CandidateAssessment([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'candidate_id' => 'cand-simple',
            'technical_score' => 0.8,
            'cultural_score' => 0.6,
            'overall_score' => 0.7,
        ]);

        $result = $service->calculateScoreWithReasoning([$assessment], 'simple');

        expect($result[0]['reasoning'])->toHaveKey('overall_score');
        expect($result[0]['reasoning'])->toHaveKey('technical_influence');
        expect($result[0]['reasoning'])->toHaveKey('cultural_influence');
    });

    it('generates technical_only reasoning type', function () {
        $service = new AssessmentScoringService();

        $assessment = new CandidateAssessment([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'candidate_id' => 'cand-tech',
            'technical_score' => 0.85,
            'cultural_score' => 0.5,
            'overall_score' => 0.67,
        ]);

        $result = $service->calculateScoreWithReasoning([$assessment], 'technical_only');

        expect($result[0]['reasoning'])->toHaveKey('technical_score');
        expect($result[0]['reasoning'])->toHaveKey('interpretation');
    });
});

describe('AssessmentController::scoringLogic', function () {
    it('computes weighted score from criteria and scores', function () {
        $controller = new \App\Modules\Assessment\AssessmentController(
            new App\Modules\Assessment\Services\AssessmentSchedulingService(),
            new App\Modules\Assessment\Services\AssessmentScoringService()
        );

        $result = $controller->scoringLogic(
            ['technical' => 60, 'cultural' => 40],
            ['technical' => 80, 'cultural' => 60]
        );

        expect($result['overall_score'])->toBe(round((80 * 0.6) + (60 * 0.4), 2));
        expect($result['breakdown'])->toHaveCount(2);
    });

    it('normalizes weights correctly', function () {
        $controller = new \App\Modules\Assessment\AssessmentController(
            new App\Modules\Assessment\Services\AssessmentSchedulingService(),
            new App\Modules\Assessment\Services\AssessmentScoringService()
        );

        $result = $controller->scoringLogic(
            ['a' => 1, 'b' => 1],
            ['a' => 50, 'b' => 50]
        );

        expect($result['breakdown'][0]['weight'])->toBe(0.5);
        expect($result['breakdown'][1]['weight'])->toBe(0.5);
        expect($result['overall_score'])->toBe(50.0);
    });
});
