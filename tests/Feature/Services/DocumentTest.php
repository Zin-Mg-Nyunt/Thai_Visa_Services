<?php

use App\Enums\DocumentAccessOutcome;
use App\Enums\DocumentActor;
use App\Enums\DocumentDenyReason;
use App\Models\ClientService;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;

/**
 * A Work Permit record with a fee of 8,000 and the given payments.
 *
 * @param  array<int, string>  $payments
 * @param  array<string, mixed>  $state
 */
function workPermitRecord(array $payments = [], array $state = []): ClientService
{
    $service = Service::factory()->create(['has_documents' => true]);
    $record = ClientService::factory()->forService($service)->create(['fee' => '8000.00', ...$state]);

    foreach ($payments as $amount) {
        Payment::factory()->create(['client_service_id' => $record->id, 'amount' => $amount]);
    }

    return $record;
}

test('a document belongs to a client service', function () {
    $record = workPermitRecord();
    $document = Document::factory()->create(['client_service_id' => $record->id]);

    expect($document->clientService->is($record))->toBeTrue()
        ->and($record->documents)->toHaveCount(1)
        ->and($document->client_can_download)->toBeFalse()
        ->and($document->size)->toBeInt();
});

test('a client service with documents cannot be hard deleted', function () {
    $document = Document::factory()->create();

    $document->clientService->delete();
})->throws(QueryException::class);

test('a document is locked until the service is fully paid', function (array $payments, bool $unlocked) {
    $record = workPermitRecord($payments);
    $document = Document::factory()->create(['client_service_id' => $record->id]);

    expect($document->isUnlocked())->toBe($unlocked);
})->with([
    'unpaid' => [[], false],
    'deposit' => [['3000.00'], false],
    'one satang short' => [['7999.99'], false],
    'fully paid' => [['8000.00'], true],
    'fully paid in parts' => [['5000.00', '3000.00'], true],
]);

test('the admin release unlocks a document whatever the payment status', function () {
    $record = workPermitRecord(['1000.00']);
    $released = Document::factory()->released()->create(['client_service_id' => $record->id]);
    $other = Document::factory()->create(['client_service_id' => $record->id]);

    expect($released->isUnlocked())->toBeTrue()
        ->and($other->isUnlocked())->toBeFalse()
        ->and($record->paymentStatus()->value)->toBe('deposit');
});

test('a cancelled service does not unlock by payment, only by release', function () {
    $record = workPermitRecord(['8000.00'], ['status' => 'cancel', 'cancelled_at' => now(), 'cancel_refund_amount' => '8000.00']);

    expect(Document::factory()->create(['client_service_id' => $record->id])->isUnlocked())->toBeFalse()
        ->and(Document::factory()->released()->create(['client_service_id' => $record->id])->isUnlocked())->toBeTrue();
});

test('an access log row records who, from where and the result, and keeps its copies after the document is deleted', function () {
    $record = workPermitRecord();
    $document = Document::factory()->create(['client_service_id' => $record->id, 'title' => 'Work permit card']);
    $allowed = DocumentAccessLog::factory()->create([
        'document_id' => $document->id, 'client_id' => $record->client_id, 'document_title' => $document->title,
        'actor_type' => DocumentActor::Admin, 'actor_id' => 7, 'ip' => '203.0.113.9',
    ]);
    $denied = DocumentAccessLog::factory()->denied(DocumentDenyReason::Locked)->create([
        'document_id' => $document->id, 'client_id' => $record->client_id, 'document_title' => $document->title,
    ]);

    expect($allowed->actor_type)->toBe(DocumentActor::Admin)
        ->and($allowed->outcome)->toBe(DocumentAccessOutcome::Allowed)
        ->and($allowed->reason)->toBeNull()
        ->and($allowed->created_at)->not->toBeNull()
        ->and($denied->outcome)->toBe(DocumentAccessOutcome::Denied)
        ->and($denied->reason)->toBe(DocumentDenyReason::Locked)
        ->and($document->accessLogs)->toHaveCount(2);

    $document->delete();

    expect(DocumentAccessLog::count())->toBe(2)
        ->and($allowed->fresh()->document_id)->toBeNull()
        ->and($allowed->fresh()->client_id)->toBe($record->client_id)
        ->and($allowed->fresh()->document_title)->toBe('Work permit card')
        ->and($denied->fresh()->reason)->toBe(DocumentDenyReason::Locked);
});

test('an access log row keeps the client id after the client is deleted', function () {
    $record = workPermitRecord();
    $log = DocumentAccessLog::factory()->create(['document_id' => null, 'client_id' => $record->client_id]);

    $record->client->delete();

    expect($log->fresh()->client_id)->toBe($record->client_id);
});

test('only the signed-in superadmin manages documents, on a service that has documents or on a Job Search engagement that is not Cancelled', function () {
    $admin = User::factory()->create();
    $withDocuments = workPermitRecord();
    $without = ClientService::factory()->create();
    $document = Document::factory()->create(['client_service_id' => $withDocuments->id]);

    $jobSearch = ClientService::factory()->forService(
        Service::factory()->create(['key' => Service::JOB_SEARCH_KEY, 'has_documents' => true]),
    )->create();

    $cancelledJobSearch = ClientService::factory()->cancelled()->forService($jobSearch->service)->create();

    expect(Gate::forUser($admin)->allows('create', [Document::class, $jobSearch]))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', [Document::class, $cancelledJobSearch]))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('create', [Document::class, $withDocuments]))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', [Document::class, $without]))->toBeFalse()
        ->and(Gate::allows('create', [Document::class, $withDocuments]))->toBeFalse();

    foreach (['view', 'update', 'delete'] as $ability) {
        expect(Gate::forUser($admin)->allows($ability, $document))->toBeTrue()
            ->and(Gate::allows($ability, $document))->toBeFalse();
    }
});
