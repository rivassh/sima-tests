<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Models\Candidate;
use App\Models\CandidateAssessment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BDD Feature: Offline Artifact Handshake (sima-core <-> sima-nlp-worker)
 *
 * As a Platform Operator
 * I want to export pending evaluations, process them on the Lab workstation,
 * and import the results back
 * So that heavy NLP never runs on the memory-constrained production server.
 *
 * Protocol:
 *   Step A (server)      php artisan sima:export-pending-eval --job-id={id} --out=eval_input.json
 *   Step B (workstation) python run_eval.py --input=eval_input.json --output=eval_result.json
 *   Step C (server)      php artisan sima:import-eval-result eval_result.json
 */
class ArtifactHandshakeBddTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('job_posts')->insert([
            'id' => 1,
            'source' => 'jobvision',
            'title' => 'Senior Backend Developer (Laravel)',
            'company' => 'JobVision',
            'description' => 'Laravel and PostgreSQL services with Redis caching and Docker.',
            'skills' => json_encode(['PHP', 'Laravel', 'PostgreSQL', 'Redis', 'Docker', 'REST API']),
            'experience_years' => 5,
            'location' => 'Tehran',
            'employment_type' => 'full_time',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function seedCandidate(string $externalId, string $name, array $skills, int $experience = 4): Candidate
    {
        return Candidate::create([
            'id' => (string) Str::uuid(),
            'external_id' => $externalId,
            'full_name' => $name,
            'source' => 'jobvision',
            'skills' => $skills,
            'experience_years' => $experience,
            'status' => 'applied',
            'raw_profile' => [
                'raw_text' => $name.' ' . implode(' ', $skills) . " {$experience} years experience",
            ],
        ]);
    }

    protected function artifactPath(string $name): string
    {
        $path = storage_path('framework/testing/'.$name);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        return $path;
    }

    #[Test]
    public function testIt_exports_pending_evaluations_as_a_schema_valid_artifact(): void
    {
        // Given candidates awaiting evaluation
        $this->seedCandidate('JV-100', 'Majid Rafiei', ['PHP', 'Laravel', 'PostgreSQL', 'Docker']);
        $this->seedCandidate('JV-101', 'Ali Mohammadi', ['Python', 'Django', 'Redis']);

        // When the operator exports the pending evaluation for the job
        $out = $this->artifactPath('eval_input.json');

        $exitCode = $this->artisan('sima:export-pending-eval', [
            '--job-id' => 1,
            '--out' => $out,
        ])->assertExitCode(0);

        $this->assertFileExists($out);

        // Then the artifact must satisfy the sima.eval.input contract
        $artifact = json_decode((string) file_get_contents($out), true);

        $this->assertSame('sima.eval.input', $artifact['artifact_type']);
        $this->assertSame('1.0', $artifact['schema_version']);
        $this->assertSame('1', $artifact['job_id']);
        $this->assertCount(2, $artifact['candidates']);

        // And the job requirements must be carried over for the scorer
        $this->assertSame(
            ['PHP', 'Laravel', 'PostgreSQL', 'Redis', 'Docker', 'REST API'],
            $artifact['job']['required_skills']
        );

        // And each candidate must carry the raw text the worker tokenizes
        $this->assertNotEmpty($artifact['candidates'][0]['raw_text']);
        $this->assertSame('jobvision', $artifact['candidates'][0]['source']);
    }

    #[Test]
    public function testIt_fails_the_export_when_the_job_does_not_exist(): void
    {
        $this->artisan('sima:export-pending-eval', [
            '--job-id' => 9999,
            '--out' => $this->artifactPath('missing.json'),
        ])->assertExitCode(1);
    }

    #[Test]
    public function testIt_imports_worker_results_and_persists_assessments(): void
    {
        // Given candidates and a worker result artifact
        $candidate = $this->seedCandidate('JV-200', 'Majid Rafiei', ['PHP', 'Laravel', 'PostgreSQL', 'Docker']);
        $other = $this->seedCandidate('JV-201', 'Sara Ahmadi', ['Vue.js', 'JavaScript']);

        $resultPath = $this->artifactPath('eval_result.json');

        file_put_contents($resultPath, json_encode([
            'schema_version' => '1.0',
            'artifact_type' => 'sima.eval.result',
            'job_id' => '1',
            'generated_at' => now()->toIso8601String(),
            'processor' => [
                'name' => 'sima-nlp-worker',
                'version' => '1.0.0',
                'tokenizer' => 'hazm',
                'skill_taxonomy_version' => '2026.10.1',
            ],
            'candidates' => [
                [
                    'external_id' => 'JV-200',
                    'full_name' => 'Majid Rafiei',
                    'skills' => ['PHP', 'Laravel', 'PostgreSQL', 'Docker'],
                    'matched_skills' => ['PHP', 'Laravel', 'PostgreSQL', 'Docker'],
                    'missing_skills' => ['Redis', 'REST API'],
                    'score' => 76.67,
                    'experience_years' => 4,
                    'tokens' => 12,
                    'normalized_summary' => 'majid rafiei php laravel postgresql docker',
                ],
                [
                    'external_id' => 'JV-201',
                    'full_name' => 'Sara Ahmadi',
                    'skills' => ['JavaScript', 'Vue.js'],
                    'matched_skills' => ['REST API'],
                    'missing_skills' => ['PHP', 'Laravel', 'PostgreSQL', 'Redis', 'Docker'],
                    'score' => 23.67,
                    'experience_years' => 2,
                    'tokens' => 6,
                    'normalized_summary' => 'sara ahmadi javascript vue.js',
                ],
            ],
            'ranking' => [
                ['external_id' => 'JV-200', 'rank' => 1, 'score' => 76.67],
                ['external_id' => 'JV-201', 'rank' => 2, 'score' => 23.67],
            ],
        ], JSON_UNESCAPED_UNICODE));

        // When the operator imports the artifact
        $this->artisan('sima:import-eval-result', ['input' => $resultPath])->assertExitCode(0);

        // Then the assessments must be persisted in the database
        $this->assertDatabaseHas('candidate_assessments', [
            'candidate_id' => $candidate->id,
            'job_title' => '1',
            'shortlisted' => true,
        ]);

        $this->assertDatabaseHas('candidate_assessments', [
            'candidate_id' => $other->id,
            'job_title' => '1',
            'shortlisted' => true,
        ]);

        // And the assessment must carry the worker reasoning
        $assessment = CandidateAssessment::where('candidate_id', $candidate->id)->firstOrFail();

        $this->assertEqualsWithDelta(0.7667, $assessment->overall_score, 0.001);
        $this->assertSame(1, $assessment->reasoning['rank']);
        $this->assertSame(['PHP', 'Laravel', 'PostgreSQL', 'Docker'], $assessment->reasoning['matched_skills']);
        $this->assertSame('hazm', $assessment->reasoning['processor']['tokenizer']);

        // And the top ranked candidate must be shortlisted in the pipeline
        $this->assertDatabaseHas('candidates', [
            'id' => $candidate->id,
            'status' => 'shortlisted',
        ]);
    }

    #[Test]
    public function testIt_rejects_an_artifact_that_violates_the_json_schema(): void
    {
        // Given a malformed artifact (missing the required ranking block)
        $resultPath = $this->artifactPath('invalid_result.json');

        file_put_contents($resultPath, json_encode([
            'schema_version' => '1.0',
            'artifact_type' => 'sima.eval.result',
            'job_id' => '1',
            'generated_at' => now()->toIso8601String(),
            'processor' => ['name' => 'sima-nlp-worker', 'version' => '1.0.0'],
            'candidates' => [],
        ]));

        // When the operator imports it
        $exitCode = $this->artisan('sima:import-eval-result', ['input' => $resultPath])->assertExitCode(1);

        // Then nothing must be persisted
        $this->assertDatabaseCount('candidate_assessments', 0);
    }

    #[Test]
    public function testIt_rejects_an_artifact_whose_type_is_not_the_result_contract(): void
    {
        $resultPath = $this->artifactPath('wrong_type.json');

        file_put_contents($resultPath, json_encode([
            'schema_version' => '1.0',
            'artifact_type' => 'sima.eval.input',
            'job_id' => '1',
            'generated_at' => now()->toIso8601String(),
            'processor' => ['name' => 'sima-nlp-worker', 'version' => '1.0.0'],
            'candidates' => [],
            'ranking' => [],
        ]));

        $this->artisan('sima:import-eval-result', ['input' => $resultPath])->assertExitCode(1);

        $this->assertDatabaseCount('candidate_assessments', 0);
    }

    #[Test]
    public function testIt_supports_a_dry_run_import_that_persists_nothing(): void
    {
        $candidate = $this->seedCandidate('JV-300', 'Majid Rafiei', ['PHP', 'Laravel']);

        $resultPath = $this->artifactPath('dry_run.json');

        file_put_contents($resultPath, json_encode([
            'schema_version' => '1.0',
            'artifact_type' => 'sima.eval.result',
            'job_id' => '1',
            'generated_at' => now()->toIso8601String(),
            'processor' => ['name' => 'sima-nlp-worker', 'version' => '1.0.0'],
            'candidates' => [
                [
                    'external_id' => 'JV-300',
                    'full_name' => 'Majid Rafiei',
                    'skills' => ['PHP', 'Laravel'],
                    'matched_skills' => ['PHP', 'Laravel'],
                    'missing_skills' => [],
                    'score' => 100.0,
                ],
            ],
            'ranking' => [
                ['external_id' => 'JV-300', 'rank' => 1, 'score' => 100.0],
            ],
        ]));

        $this->artisan('sima:import-eval-result', [
            'input' => $resultPath,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('candidate_assessments', 0);
        $this->assertDatabaseHas('candidates', [
            'id' => $candidate->id,
            'status' => 'applied',
        ]);
    }

    #[Test]
    public function testIt_runs_the_python_worker_in_process_when_available(): void
    {
        // Given candidates awaiting evaluation + a job post
        $this->seedCandidate('JV-400', 'Majid Rafiei', ['PHP', 'Laravel', 'PostgreSQL', 'Docker']);

        $exportOut = $this->artifactPath('roundtrip_input.json');

        $this->artisan('sima:export-pending-eval', [
            '--job-id' => 1,
            '--out' => $exportOut,
        ])->assertExitCode(0);

        $resultPath = $this->artifactPath('roundtrip_output.json');

        // When the worker runs in-process through exec() — the app image ships
        // python3, so the artifact is produced live rather than mocked.
        exec(sprintf(
            'python3 %s --input=%s --output=%s',
            escapeshellarg(base_path('modules/nlp-worker/run_eval.py')),
            escapeshellarg($exportOut),
            escapeshellarg($resultPath)
        ), $output, $exitCode);

        // Then the exit code from the Python worker itself gates the assertion:
        // a non-zero code means the scorer could not produce a result.
        $this->assertSame(0, $exitCode, 'Python worker failed: '.implode(PHP_EOL, $output));

        $result = json_decode((string) file_get_contents($resultPath), true);
        $this->assertNotEmpty($result['candidates']);
        $this->assertSame('sima.eval.result', $result['artifact_type']);
        $this->assertSame('JV-400', $result['candidates'][0]['external_id']);

        // And the result must be importable into PostgreSQL without errors.
        $importOut = $this->artifactPath('roundtrip_import.json');

        file_put_contents($importOut, json_encode($result));

        $this->artisan('sima:import-eval-result', ['input' => $importOut])
            ->assertExitCode(0);
    }
}
