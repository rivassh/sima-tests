<?php

namespace Tests\Units;

use PHPUnit\Framework\TestCase;
use App\Modules\Assessment\AssessmentController;
use App\Database\Repositories\CandidateRepository;

class AssessmentControllerTest extends TestCase
{
    private AssessmentController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new AssessmentController();
    }

    public function testScoringLogicCalculatesWeightedAverage()
    {
        $result = $this->controller->scoringLogic(
            ['technical' => 60, 'cultural' => 40],
            ['technical' => 80, 'cultural' => 90]
        );

        $this->assertEquals(84.0, $result['overall_score']);
        $this->assertArrayHasKey('breakdown', $result);
        $this->assertCount(2, $result['breakdown']);
    }

    public function testScoringLogicReturnsBreakdownStructure()
    {
        $result = $this->controller->scoringLogic(
            ['technical' => 50, 'behavioral' => 50],
            ['technical' => 70, 'behavioral' => 80]
        );

        $this->assertEquals(75.0, $result['overall_score']);
        $this->assertEquals(0.5, $result['breakdown'][0]['weight']);
        $this->assertEquals(0.5, $result['breakdown'][1]['weight']);
    }

    public function testScoringLogicHandlesEmptyScores()
    {
        $result = $this->controller->scoringLogic(
            ['technical' => 50, 'cultural' => 50],
            ['technical' => 80]
        );

        $this->assertEquals(40.0, $result['overall_score']);
    }
}