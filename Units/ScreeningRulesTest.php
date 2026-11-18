<?php

declare(strict_types=1);

namespace Tests\Units;

use PHPUnit\Framework\TestCase;
use App\Services\AI\Screening\Rules\EducationScreeningRule;
use App\Services\AI\Screening\Rules\ExperienceScreeningRule;
use App\Services\AI\Screening\Rules\DesignSoftwareScreeningRule;
use App\Services\AI\Screening\Rules\PressureSystemsScreeningRule;
use App\Services\AI\Screening\Rules\MetallurgyScreeningRule;
use App\Services\AI\Screening\Rules\ManufacturingScreeningRule;
use App\Services\AI\Screening\Rules\PersonalTraitsScreeningRule;

class ScreeningRulesTest extends TestCase
{
    public function testEducationRulePassesForMasterInMechanicalEngineering(): void
    {
        $rule = new EducationScreeningRule();
        $result = $rule->evaluate([
            'education_history' => [
                ['degree' => 'Master', 'field_of_study' => 'Mechanical Engineering'],
            ],
            'job_requirements' => ['required_degree' => 'Bachelor'],
        ]);

        $this->assertTrue($result['passed']);
        $this->assertGreaterThan(0.5, $result['score']);
    }

    public function testEducationRuleFailsForAssociateInComputerScience(): void
    {
        $rule = new EducationScreeningRule();
        $result = $rule->evaluate([
            'education_history' => [
                ['degree' => 'Associate', 'field_of_study' => 'Computer Science'],
            ],
            'job_requirements' => ['required_degree' => 'Bachelor'],
        ]);

        $this->assertFalse($result['passed']);
        $this->assertEquals(0.0, $result['score']);
    }

    public function testExperienceRulePassesFor5YearsHpht(): void
    {
        $rule = new ExperienceScreeningRule();
        $result = $rule->evaluate([
            'experience' => [
                ['title' => 'HPHT Engineer', 'company' => 'OilCo', 'duration_years' => 5, 'description' => 'HPHT pressure vessel design'],
            ],
            'job_requirements' => ['min_experience_years' => 4],
        ]);

        $this->assertTrue($result['passed']);
        $this->assertGreaterThanOrEqual(4, $result['hpht_years']);
    }

    public function testExperienceRuleFailsFor3YearsNonHpht(): void
    {
        $rule = new ExperienceScreeningRule();
        $result = $rule->evaluate([
            'experience' => [
                ['title' => 'Web Developer', 'company' => 'TechCo', 'duration_years' => 3, 'description' => 'building websites'],
            ],
            'job_requirements' => ['min_experience_years' => 4],
        ]);

        $this->assertFalse($result['passed']);
    }

    public function testDesignSoftwareRulePassesWithSolidworks(): void
    {
        $rule = new DesignSoftwareScreeningRule();
        $result = $rule->evaluate([
            'skills' => ['SolidWorks', 'CNC'],
            'job_requirements' => ['required_software' => ['SolidWorks', 'CATIA']],
        ]);

        $this->assertTrue($result['passed']);
        $this->assertContains('SolidWorks', $result['found']);
    }

    public function testDesignSoftwareRuleFailsWithoutRequiredSoftware(): void
    {
        $rule = new DesignSoftwareScreeningRule();
        $result = $rule->evaluate([
            'skills' => ['Python', 'SQL'],
            'job_requirements' => ['required_software' => ['SolidWorks', 'CATIA']],
        ]);

        $this->assertFalse($result['passed']);
        $this->assertNotEmpty($result['missing']);
    }

    public function testPressureSystemsRulePassesWithPipinhValvesAndHpht(): void
    {
        $rule = new PressureSystemsScreeningRule();
        $result = $rule->evaluate([
            'skills' => ['piping', 'valves', 'hpht'],
            'specializations' => [],
            'experience' => [],
            'job_requirements' => [],
        ]);

        $this->assertTrue($result['passed']);
        $this->assertTrue($result['has_piping']);
        $this->assertTrue($result['has_valves']);
        $this->assertTrue($result['has_high_pressure']);
    }

    public function testPressureSystemsRuleFailsWithoutHighPressure(): void
    {
        $rule = new PressureSystemsScreeningRule();
        $result = $rule->evaluate([
            'skills' => ['piping', 'valves'],
            'specializations' => [],
            'experience' => [],
            'job_requirements' => [],
        ]);

        $this->assertFalse($result['passed']);
        $this->assertTrue($result['has_piping']);
        $this->assertTrue($result['has_valves']);
        $this->assertFalse($result['has_high_pressure']);
    }

    public function testMetallurgyRulePassesWithMaterialsAndSealing(): void
    {
        $rule = new MetallurgyScreeningRule();
        $result = $rule->evaluate([
            'skills' => ['metallurgy', 'sealing'],
            'raw_profile' => '',
            'job_requirements' => [],
        ]);

        $this->assertTrue($result['passed']);
        $this->assertTrue($result['has_metallurgy']);
        $this->assertTrue($result['has_sealing']);
    }

    public function testManufacturingRulePassesWithCNC(): void
    {
        $rule = new ManufacturingScreeningRule();
        $result = $rule->evaluate([
            'skills' => ['CNC', 'boring', 'honing'],
            'experience' => [],
            'job_requirements' => [],
        ]);

        $this->assertTrue($result['passed']);
        $this->assertTrue($result['has_cnc']);
        $this->assertTrue($result['has_precision']);
    }

    public function testPersonalTraitsRulePassesWithMultipleTraits(): void
    {
        $rule = new PersonalTraitsScreeningRule();
        $result = $rule->evaluate([
            'self_description' => 'I am an enterprising and energetic engineer with field assembly experience and equipment testing skills',
            'skills' => ['CNC'],
            'job_requirements' => [],
        ]);

        $this->assertTrue($result['passed']);
        $this->assertTrue($result['traits']['enterprising']);
        $this->assertTrue($result['traits']['energetic']);
        $this->assertTrue($result['traits']['field_assembly']);
        $this->assertTrue($result['traits']['field_testing']);
    }

    public function testPersonalTraitsRuleFailsWithNoTraits(): void
    {
        $rule = new PersonalTraitsScreeningRule();
        $result = $rule->evaluate([
            'self_description' => 'I have experience in software development',
            'skills' => ['Python'],
            'job_requirements' => [],
        ]);

        $this->assertFalse($result['passed']);
    }
}
