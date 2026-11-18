<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Services\AI\Screening\Rules\EducationScreeningRule;
use Tests\TestCase;

/**
 * As a Screening System Operator
 * I want education screening to enforce minimum degree requirements
 * So that unqualified candidates are filtered out before technical evaluation.
 */
class EducationScreeningRuleBddTest extends TestCase
{
    public function test_education_screening_rule_exists(): void
    {
        $this->assertTrue(class_exists(EducationScreeningRule::class));
    }

    public function test_bachelors_mechanical_engineering_passes(): void
    {
        $rule = new EducationScreeningRule();
        $result = $rule->evaluate([
            'education_history' => [[
                'degree' => 'کارشناسی',
                'institution' => 'دانشگاه صنعتی شریف',
                'field_of_study' => 'مهندسی مکانیک',
                'graduation_date' => '1398',
            ]],
            'job_requirements' => ['min_degree' => 'bachelors', 'required_field' => 'mechanical_engineering'],
        ]);

        $this->assertTrue($result['passed']);
        $this->assertGreaterThanOrEqual(0.8, $result['score']);
    }

    public function test_masters_mechanical_engineering_passes(): void
    {
        $rule = new EducationScreeningRule();
        $result = $rule->evaluate([
            'education_history' => [[
                'degree' => 'کارشناسی ارشد',
                'institution' => 'دانشگاه صنعتی شریف',
                'field_of_study' => 'مهندسی مکانیک',
                'graduation_date' => '1398',
            ]],
            'job_requirements' => ['min_degree' => 'bachelors', 'required_field' => 'mechanical_engineering'],
        ]);

        $this->assertTrue($result['passed']);
    }

    public function test_bachelors_non_mechanical_field_fails(): void
    {
        $rule = new EducationScreeningRule();
        $result = $rule->evaluate([
            'education_history' => [[
                'degree' => 'کارشناسی',
                'institution' => 'دانشگاه تهران',
                'field_of_study' => 'مهندسی برق',
                'graduation_date' => '1398',
            ]],
            'job_requirements' => ['min_degree' => 'bachelors', 'required_field' => 'mechanical_engineering'],
        ]);

        $this->assertFalse($result['passed']);
    }

    public function test_associate_degree_fails_minimum_bachelors(): void
    {
        $rule = new EducationScreeningRule();
        $result = $rule->evaluate([
            'education_history' => [[
                'degree' => 'کاردانی',
                'institution' => 'موسسه فنی و حرفه‌ای',
                'field_of_study' => 'مهندسی مکانیک',
                'graduation_date' => '1398',
            ]],
            'job_requirements' => ['min_degree' => 'bachelors', 'required_field' => 'mechanical_engineering'],
        ]);

        $this->assertFalse($result['passed']);
    }

    public function test_no_education_history_fails(): void
    {
        $rule = new EducationScreeningRule();
        $result = $rule->evaluate([
            'education_history' => [],
            'job_requirements' => ['min_degree' => 'bachelors', 'required_field' => 'mechanical_engineering'],
        ]);

        $this->assertFalse($result['passed']);
    }

    public function test_high_pressure_piping_mechanical_field_passes(): void
    {
        $rule = new EducationScreeningRule();
        $result = $rule->evaluate([
            'education_history' => [[
                'degree' => 'کارشناسی',
                'institution' => 'دانشگاه صنعتی شریف',
                'field_of_study' => 'مهندسی مکانیک - طراحی و تحلیل فشار',
                'graduation_date' => '1398',
            ]],
            'job_requirements' => ['min_degree' => 'bachelors', 'required_field' => 'mechanical_engineering'],
        ]);

        $this->assertTrue($result['passed']);
    }
}
