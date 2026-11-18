<?php

use App\Modules\HR\Models\FormAssignment;
use App\Modules\HR\Models\FormSubmission;
use App\Modules\HR\Models\PersonnelDocument;
use App\Modules\HR\Services\PersonnelDocumentService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

test('can upload personnel document with valid category and subitem', function () {
    Storage::fake('personnel_documents');

    $creator = createUser();
    $employee = createUser();

    $assignment = FormAssignment::create([
        'title' => 'Personnel Info',
        'type' => 'structured',
        'created_by' => $creator->id,
        'assigned_to_type' => 'user',
        'assigned_to_ids' => [$employee->id],
        'status' => 'active',
    ]);

    $submission = FormSubmission::create([
        'form_assignment_id' => $assignment->id,
        'user_id' => $employee->id,
        'status' => 'draft',
    ]);

    $file = UploadedFile::fake()->image('national_id.jpg', 100, 100);

$document = app(PersonnelDocumentService::class)->upload(
        $submission,
        $employee,
        '5-کارت ملی',
        'روی کارت',
        $file
    );

    expect($document)
        ->toBeInstanceOf(PersonnelDocument::class)
        ->category_key->toBe('5-کارت ملی')
        ->subitem_key->toBe('روی کارت')
        ->checksum->not->toBeNull()
        ->version->toBe(1);

    $this->assertDatabaseHas('personnel_documents', [
        'id' => $document->id,
        'submission_id' => $submission->id,
        'user_id' => $employee->id,
        'category_key' => '5-کارت ملی',
        'subitem_key' => 'روی کارت',
        'checksum' => $document->checksum,
    ]);
});

test('cannot upload document to unknown category', function () {
    Storage::fake('personnel_documents');

    $creator = createUser();
    $employee = createUser();

    $assignment = FormAssignment::create([
        'title' => 'Test',
        'type' => 'structured',
        'created_by' => $creator->id,
        'assigned_to_type' => 'user',
        'assigned_to_ids' => [$employee->id],
        'status' => 'active',
    ]);

    $submission = FormSubmission::create([
        'form_assignment_id' => $assignment->id,
        'user_id' => $employee->id,
        'status' => 'draft',
    ]);

    $file = UploadedFile::fake()->image('test.jpg', 100, 100);

    expect(fn () => app(PersonnelDocumentService::class)->upload(
        $submission,
        $employee,
        'unknown-category',
        null,
        $file
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('cannot upload document with invalid subitem for category', function () {
    Storage::fake('personnel_documents');

    $creator = createUser();
    $employee = createUser();

    $assignment = FormAssignment::create([
        'title' => 'Test',
        'type' => 'structured',
        'created_by' => $creator->id,
        'assigned_to_type' => 'user',
        'assigned_to_ids' => [$employee->id],
        'status' => 'active',
    ]);

    $submission = FormSubmission::create([
        'form_assignment_id' => $assignment->id,
        'user_id' => $employee->id,
        'status' => 'draft',
    ]);

    $file = UploadedFile::fake()->image('test.jpg', 100, 100);

    expect(fn () => app(PersonnelDocumentService::class)->upload(
        $submission,
        $employee,
        '5-کارت ملی',
        'invalid-subitem',
        $file
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('can list personnel documents for a submission', function () {
    Storage::fake('personnel_documents');

    $creator = createUser();
    $employee = createUser();

    $assignment = FormAssignment::create([
        'title' => 'Test',
        'type' => 'structured',
        'created_by' => $creator->id,
        'assigned_to_type' => 'user',
        'assigned_to_ids' => [$employee->id],
        'status' => 'active',
    ]);

    $submission = FormSubmission::create([
        'form_assignment_id' => $assignment->id,
        'user_id' => $employee->id,
        'status' => 'draft',
    ]);

    $file1 = UploadedFile::fake()->image('doc1.jpg', 100, 100);
    $file2 = UploadedFile::fake()->image('doc2.jpg', 100, 100);

    app(PersonnelDocumentService::class)->upload($submission, $employee, '3-عکس پرسنلی', null, $file1);
    app(PersonnelDocumentService::class)->upload($submission, $employee, '5-کارت ملی', 'روی کارت', $file2);

    $documents = app(PersonnelDocumentService::class)->list($submission);

    expect($documents)->toHaveCount(2);
});

test('can remove personnel document', function () {
    Storage::fake('personnel_documents');

    $creator = createUser();
    $employee = createUser();

    $assignment = FormAssignment::create([
        'title' => 'Test',
        'type' => 'structured',
        'created_by' => $creator->id,
        'assigned_to_type' => 'user',
        'assigned_to_ids' => [$employee->id],
        'status' => 'active',
    ]);

    $submission = FormSubmission::create([
        'form_assignment_id' => $assignment->id,
        'user_id' => $employee->id,
        'status' => 'draft',
    ]);

    $file = UploadedFile::fake()->image('doc.jpg', 100, 100);

    $document = app(PersonnelDocumentService::class)->upload($submission, $employee, '3-عکس پرسنلی', null, $file);

    app(PersonnelDocumentService::class)->remove($document);

    $this->assertSoftDeleted('personnel_documents', [
        'id' => $document->id,
    ]);
});

test('conditional category upload fails when condition not met', function () {
    Storage::fake('personnel_documents');

    $creator = createUser();
    $employee = createUser();

    $assignment = FormAssignment::create([
        'title' => 'Test',
        'type' => 'structured',
        'created_by' => $creator->id,
        'assigned_to_type' => 'user',
        'assigned_to_ids' => [$employee->id],
        'status' => 'active',
    ]);

    $submission = FormSubmission::create([
        'form_assignment_id' => $assignment->id,
        'user_id' => $employee->id,
        'status' => 'draft',
        'form_data' => ['has_military_service' => false],
    ]);

    $file = UploadedFile::fake()->image('doc.jpg', 100, 100);

    expect(fn () => app(PersonnelDocumentService::class)->upload(
        $submission,
        $employee,
        '6-کارت پایان خدمت',
        'روی کارت',
        $file
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
});