<?php

namespace Tests\Feature;

use App\Exceptions\JobVisionTokenExpiredException;
use App\Repositories\CacheJobVisionTokenRepository;
use App\Services\Talent\Crawlers\JobVisionApiClient;
use App\Services\Talent\Crawlers\JobVisionClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JobVisionTokenRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.jobvision.auth_token' => '']);
    }

    public function test_expired_cached_token_is_evicted_and_environment_token_is_used(): void
    {
        $freshToken = $this->jwt(time() + 3600);
        $repository = new CacheJobVisionTokenRepository;
        Cache::forever('jobvision.auth_token', $this->jwt(time() - 60));
        Cache::forever('jobvision.auth_token_expiry', time() - 60);
        config(['services.jobvision.auth_token' => $freshToken]);

        $this->assertSame($freshToken, $repository->getCurrentToken());
        $this->assertFalse(Cache::has('jobvision.auth_token'));
        $this->assertFalse($repository->isTokenExpired());
    }

    public function test_expired_environment_token_is_not_returned(): void
    {
        config(['services.jobvision.auth_token' => $this->jwt(time() - 60)]);

        $repository = new CacheJobVisionTokenRepository;

        $this->assertNull($repository->getCurrentToken());
        $this->assertTrue($repository->isTokenExpired());
    }

    public function test_expired_token_cannot_be_cached(): void
    {
        $repository = new CacheJobVisionTokenRepository;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already expired');

        $repository->setToken($this->jwt(time() - 60));
    }

    public function test_rejected_cached_token_is_cleared_and_environment_token_is_retried_once(): void
    {
        $oldToken = $this->jwt(time() + 600);
        $newToken = $this->jwt(time() + 3600);
        config(['services.jobvision.auth_token' => $newToken]);

        $repository = new CacheJobVisionTokenRepository;
        $repository->setToken($oldToken);
        Http::fakeSequence('employerapi.jobvision.ir/*')
            ->push(['message' => 'unauthorized'], 401)
            ->push(['data' => ['listOfJobPostSummaries' => []]], 200);

        $client = new JobVisionClient(tokenRepository: $repository);
        $response = $client->getJobPosts(1, 20);

        $this->assertSame([], $response['data']['listOfJobPostSummaries']);
        $this->assertCount(2, Http::recorded());
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.$oldToken));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.$newToken));
        $this->assertFalse(Cache::has('jobvision.auth_token'));
    }

    public function test_application_api_client_also_retries_with_replacement_environment_token(): void
    {
        $oldToken = $this->jwt(time() + 600);
        $newToken = $this->jwt(time() + 3600);
        config(['services.jobvision.auth_token' => $newToken]);

        $repository = new CacheJobVisionTokenRepository;
        $repository->setToken($oldToken);
        Http::fakeSequence('employerapi.jobvision.ir/*')
            ->push(['message' => 'unauthorized'], 401)
            ->push(['data' => [7777]], 200);

        $client = new JobVisionApiClient($repository);
        $ids = $client->getFilteredApplicationIds(42);

        $this->assertSame([7777], $ids);
        $this->assertCount(2, Http::recorded());
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.$oldToken));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.$newToken));
    }

    public function test_rejected_environment_token_fails_without_exposing_its_value(): void
    {
        $token = $this->jwt(time() + 3600);
        config(['services.jobvision.auth_token' => $token]);
        Http::fake(['employerapi.jobvision.ir/*' => Http::response(['message' => 'unauthorized'], 401)]);

        $client = new JobVisionClient(tokenRepository: new CacheJobVisionTokenRepository);

        try {
            $client->getJobPosts(1, 20);
            $this->fail('Expected an expired-token exception.');
        } catch (JobVisionTokenExpiredException $exception) {
            $this->assertStringNotContainsString($token, $exception->getMessage());
        }
    }

    public function test_set_token_command_reads_from_hidden_prompt_when_requested(): void
    {
        $token = $this->jwt(time() + 3600);

        $this->artisan('jobvision:set-token')
            ->expectsQuestion('JobVision employer bearer token', $token)
            ->expectsOutputToContain('Token stored')
            ->assertExitCode(0);

        $this->assertSame($token, Cache::get('jobvision.auth_token'));
    }

    private function jwt(int $expiresAt): string
    {
        $encode = static fn (array $value): string => rtrim(strtr(
            base64_encode(json_encode($value, JSON_THROW_ON_ERROR)),
            '+/',
            '-_'
        ), '=');

        return $encode(['alg' => 'none']).'.'.$encode(['exp' => $expiresAt]).'.signature';
    }
}
