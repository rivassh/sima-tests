<?php

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\FormAssignment;
use App\Modules\HR\Models\FormSubmission;
use App\Models\User;
use Illuminate\Support\Str;

function createUser(array $attributes = []): User
{
    return User::factory()->create($attributes);
}

test('hr can create a structured form assignment and notify assigned users', function () {
    $creator = createUser();
    $assignee = createUser();

    $response = $this->actingAs($creator)->postJson('/api/v1/hr/form-assignments', [
        'title' => 'فرم اطلاعات پرسنلی',
        'description' => 'لطفا تکمیل شود',
        'type' => 'structured',
        'form_schema' => [
            'fields' => [
                ['key' => 'full_name', 'type' => 'text', 'label' => 'نام'],
            ],
            'required' => ['full_name'],
        ],
        'assigned_to_type' => 'user',
        'assigned_to_ids' => [$assignee->id],
        'due_date' => now()->addDays(7)->toDateString(),
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'فرم اطلاعات پرسنلی')
        ->assertJsonPath('data.type', 'structured');

    $assignmentId = $response->json('data.id');
    expect($assignmentId)->not->toBeNull();

    $this->assertDatabaseHas('form_assignments', [
        'id' => $assignmentId,
        'title' => 'فرم اطلاعات پرسنلی',
        'created_by' => $creator->id,
        'assigned_to_type' => 'user',
        'status' => 'active',
    ]);

    $this->assertDatabaseHas('form_submissions', [
        'form_assignment_id' => $assignmentId,
        'user_id' => $assignee->id,
        'status' => 'draft',
    ]);

    expect($assignee->fresh()->notifications()->count())->toBe(1);
    expect($assignee->fresh()->notifications()->first()->data['type'])->toBe('form_assignment');
    expect($assignee->fresh()->notifications()->first()->data['form_assignment_id'])->toBe($assignmentId);
});

test('department assignment creates submissions for all department employees', function () {
    $creator = createUser();
    $department = Department::create([
        'name' => 'Engineering',
        'code' => 'ENG',
        'is_active' => true,
    ]);

    $employeeA = createUser(['department_id' => $department->id]);
    $employeeB = createUser(['department_id' => $department->id]);
    $outside = createUser();

    $response = $this->actingAs($creator)->postJson('/api/v1/hr/form-assignments', [
        'title' => 'Checklist فرم',
        'type' => 'checklist',
        'checklist_items' => [
            ['key' => 'resume', 'label' => 'رزومه', 'required' => true],
        ],
        'assigned_to_type' => 'department',
        'assigned_to_ids' => [$department->id],
    ]);

    $response->assertCreated();
    $assignmentId = $response->json('data.id');

    $this->assertDatabaseHas('form_submissions', [
        'form_assignment_id' => $assignmentId,
        'user_id' => $employeeA->id,
    ]);
    $this->assertDatabaseHas('form_submissions', [
        'form_assignment_id' => $assignmentId,
        'user_id' => $employeeB->id,
    ]);
    $this->assertDatabaseMissing('form_submissions', [
        'form_assignment_id' => $assignmentId,
        'user_id' => $outside->id,
    ]);
});

test('employee can save draft and submit structured form and reviewer can approve', function () {
    $creator = createUser();
    $employee = createUser();

    $assignment = FormAssignment::create([
        'title' => 'Structured',
        'type' => 'structured',
        'form_schema' => ['required' => ['full_name']],
        'created_by' => $creator->id,
        'assigned_to_type' => 'user',
        'assigned_to_ids' => [$employee->id],
        'status' => 'active',
    ]);

    FormSubmission::create([
        'form_assignment_id' => $assignment->id,
        'user_id' => $employee->id,
        'status' => 'draft',
    ]);

    $this->actingAs($employee)
        ->postJson("/api/v1/my-forms/{$assignment->id}/draft", [
            'form_data' => ['full_name' => 'Ali'],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');

    $this->assertDatabaseHas('form_submissions', [
        'form_assignment_id' => $assignment->id,
        'user_id' => $employee->id,
        'status' => 'draft',
    ]);

    $submit = $this->actingAs($employee)
        ->postJson("/api/v1/my-forms/{$assignment->id}/submit", [
            'form_data' => ['full_name' => 'Ali'],
        ]);

    $submit->assertOk()
        ->assertJsonPath('data.status', 'submitted');

    $submission = FormSubmission::where('form_assignment_id', $assignment->id)
        ->where('user_id', $employee->id)
        ->firstOrFail();

    $this->actingAs($creator)
        ->postJson("/api/v1/hr/form-submissions/{$submission->id}/approve", [
            'notes' => 'ok',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $this->assertDatabaseHas('form_submissions', [
        'id' => $submission->id,
        'status' => 'approved',
        'reviewed_by' => $creator->id,
    ]);

    expect($employee->fresh()->notifications->pluck('data.type')->all())->toContain('form_review_result');
});

test('my forms endpoint lists submissions for authenticated employee', function () {
    $creator = createUser();
    $employee = createUser();

    $assignment = FormAssignment::create([
        'title' => 'Mine',
        'type' => 'structured',
        'created_by' => $creator->id,
        'assigned_to_type' => 'user',
        'assigned_to_ids' => [$employee->id],
        'status' => 'active',
    ]);

    FormSubmission::create([
        'form_assignment_id' => $assignment->id,
        'user_id' => $employee->id,
        'status' => 'draft',
    ]);

    $this->actingAs($employee)
        ->getJson('/api/v1/my-forms')
        ->assertOk()
        ->assertJsonPath('data.0.form_assignment_id', $assignment->id);
});
