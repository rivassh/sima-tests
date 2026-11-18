<?php

use App\Services\Integration\ProcessMakerV4Adapter;
use Illuminate\Support\Facades\Http;

function pm4Adapter(?string $token = 'test-token'): ProcessMakerV4Adapter
{
    return new ProcessMakerV4Adapter('http://pm.test', $token);
}

test('startProcess posts variables to process_events with event node id', function () {
    Http::fake([
        'pm.test/api/1.0/process_events/*' => Http::response([
            'data' => ['id' => 42, 'status' => 'ACTIVE', 'data' => ['full_name' => 'Ali']],
        ], 200),
    ]);

    $result = pm4Adapter()->startProcess('7', 'node_1', ['full_name' => 'Ali']);

    expect($result['success'])->toBeTrue()
        ->and($result['request_id'])->toBe(42)
        ->and($result['status'])->toBe('ACTIVE')
        ->and($result['data'])->toBe(['full_name' => 'Ali']);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), 'http://pm.test/api/1.0/process_events/7')
        && str_contains($request->url(), 'event=node_1')
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->data() === ['full_name' => 'Ali']);
});

test('getProcessStatus returns normalized request status', function () {
    Http::fake([
        'pm.test/api/1.0/requests/*' => Http::response([
            'data' => ['id' => 42, 'status' => 'IN_PROGRESS', 'data' => ['step' => 2]],
        ], 200),
    ]);

    $result = pm4Adapter()->getProcessStatus('42');

    expect($result['success'])->toBeTrue()
        ->and($result['status'])->toBe('IN_PROGRESS')
        ->and($result['data'])->toBe(['step' => 2]);

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && $request->url() === 'http://pm.test/api/1.0/requests/42'
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

test('getActiveTasks filters tasks by process_request_id', function () {
    Http::fake([
        'pm.test/api/1.0/tasks*' => Http::response([
            'data' => [
                ['id' => 5, 'element_name' => 'فرم اطلاعات', 'status' => 'ACTIVE', 'process_request_id' => 42],
            ],
            'meta' => ['total' => 1],
        ], 200),
    ]);

    $result = pm4Adapter()->getActiveTasks('42');

    expect($result['success'])->toBeTrue()
        ->and($result['tasks'])->toHaveCount(1)
        ->and($result['tasks'][0]['id'])->toBe(5)
        ->and($result['meta']['total'])->toBe(1);

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), 'process_request_id=42'));
});

test('getTask returns a single task', function () {
    Http::fake([
        'pm.test/api/1.0/tasks/*' => Http::response([
            'data' => ['id' => 5, 'element_name' => 'بازبینی HR', 'status' => 'ACTIVE'],
        ], 200),
    ]);

    $result = pm4Adapter()->getTask('5');

    expect($result['success'])->toBeTrue()
        ->and($result['task']['id'])->toBe(5)
        ->and($result['task']['status'])->toBe('ACTIVE');
});

test('updateVariables wraps variables in data envelope', function () {
    Http::fake([
        'pm.test/api/1.0/requests/*' => Http::response([], 204),
    ]);

    $result = pm4Adapter()->updateVariables('42', ['sima_snapshot' => ['a' => 1]]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'http://pm.test/api/1.0/requests/42'
        && $request->data() === ['data' => ['sima_snapshot' => ['a' => 1]]]);
});

test('completeTask sends COMPLETED status with data', function () {
    Http::fake([
        'pm.test/api/1.0/tasks/*' => Http::response([
            'data' => ['id' => 5, 'status' => 'CLOSED'],
        ], 200),
    ]);

    $result = pm4Adapter()->completeTask('5', ['manifest' => ['f1' => 'ok']]);

    expect($result['success'])->toBeTrue()
        ->and($result['task']['status'])->toBe('CLOSED');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'http://pm.test/api/1.0/tasks/5'
        && $request->data() === ['status' => 'COMPLETED', 'data' => ['manifest' => ['f1' => 'ok']]]);
});

test('getRequestFiles returns attachment list', function () {
    Http::fake([
        'pm.test/api/1.0/requests/*/files' => Http::response([
            'data' => [['id' => 9, 'file_name' => 'resume.pdf']],
        ], 200),
    ]);

    $result = pm4Adapter()->getRequestFiles('42');

    expect($result['success'])->toBeTrue()
        ->and($result['files'])->toHaveCount(1)
        ->and($result['files'][0]['file_name'])->toBe('resume.pdf');
});

test('syncState sends PMQL filter and since clause', function () {
    Http::fake([
        'pm.test/api/1.0/requests*' => Http::response([
            'data' => [['id' => 42, 'status' => 'IN_PROGRESS']],
            'meta' => ['total' => 1],
        ], 200),
    ]);

    $result = pm4Adapter()->syncState([
        'type' => 'in_progress',
        'since' => '2026-11-18 00:00:00',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['count'])->toBe(1)
        ->and($result['requests'])->toHaveCount(1)
        ->and($result['synced_at'])->not->toBeNull();

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), 'type=in_progress')
        && str_contains($request->url(), 'filter=')
        && str_contains($request->url(), 'updated_date'));
});

test('adapter reports failure with normalized error on http error status', function () {
    Http::fake([
        'pm.test/api/1.0/requests/*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $result = pm4Adapter()->getProcessStatus('42');

    expect($result['success'])->toBeFalse()
        ->and($result['status'])->toBe(404)
        ->and($result['error'])->toContain('404');
});

test('adapter fails gracefully when token is not configured', function () {
    Http::fake();

    $result = pm4Adapter(null)->getProcessStatus('42');

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('token');

    Http::assertNothingSent();
});