<?php

use App\Models\Client;
use App\Models\ClientService;
use App\Models\Service;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Mysql\Support\ChildWorkflow;
use Tests\Concurrency\Mysql\Support\JobSearchWorld;

/**
 * Person deletion against the two writers that add to a Person: a document upload and Add Service (Steps 12 and 13). Each test
 * plays one side on a peer connection, holding the Person row lock exactly as that workflow does, and runs the other side as a
 * real admin request (the whole HTTP stack) in its own PHP process. It waits until MySQL shows the request blocked on the lock,
 * lets the peer finish, and checks what the request then did. Rows are committed and removed again by id only. Run with:
 *
 *   DB_USERNAME=zin_visa_test php artisan test -c phpunit.mysql.xml --filter=PersonDeletionConcurrencyMysqlTest
 */
beforeEach(function () {
    app()->setLocale('en');
    $this->world = new JobSearchWorld;
    $this->children = [];
    $this->pdf = null;
    $this->plainServices = [];
});

afterEach(function () {
    // On a run without MySQL the test is skipped before beforeEach has run, so there is nothing to clean up.
    foreach ($this->children ?? [] as $child) {
        $child->kill();
    }

    $this->closePeers();
    ($this->world ?? null)?->cleanUp();

    if (! empty($this->plainServices)) {
        DB::table('services')->whereIn('id', $this->plainServices)->delete();
    }

    if (! empty($this->pdf)) {
        @unlink($this->pdf);
    }
});

function pdHttp(object $test, string $method, string $uri, array $params = [], ?string $file = null): ChildWorkflow
{
    return $test->children[] = new ChildWorkflow('http', [
        'user' => $test->admin->id, 'method' => $method, 'uri' => $uri, 'params' => $params, 'file' => $file,
    ]);
}

function pdHoldPerson(Connection $peer, int $personId): void
{
    $peer->beginTransaction();
    $peer->table('clients')->where('id', $personId)->lockForUpdate()->first();
}

function pdSetUp(object $test): void
{
    $test->admin = $test->world->user();
    $test->person = $test->world->person();
    $test->record = ClientService::factory()->forService($test->world->jobSearch())->create(['client_id' => $test->person->id]);
}

/** An ordinary service (Add Service refuses Job Search), removed again by its id. */
function pdPlainService(object $test): Service
{
    $service = Service::factory()->create();
    $test->plainServices[] = $service->id;

    return $service;
}

function pdPdf(object $test): string
{
    $test->pdf = tempnam(sys_get_temp_dir(), 'pd');
    file_put_contents($test->pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

    return $test->pdf;
}

function pdDocuments(int $recordId): int
{
    return DB::table('documents')->where('client_service_id', $recordId)->count();
}

test('an upload that waits for a deletion in progress finds the Person gone, and leaves no document and no file', function () {
    pdSetUp($this);
    $peer = $this->peerConnection();
    pdHoldPerson($peer, $this->person->id); // the deletion has locked the Person and is about to soft-delete it

    $upload = pdHttp($this, 'POST', route('admin.clients.services.documents.store', [$this->person->id, $this->record->id], false), ['title' => 'CV'], pdPdf($this));
    ChildWorkflow::waitUntilBlocked();

    expect($upload->isRunning())->toBeTrue()
        ->and(pdDocuments($this->record->id))->toBe(0);

    $peer->table('clients')->where('id', $this->person->id)->update(['deleted_at' => now()]);
    $peer->commit();

    expect($upload->result())->toMatchArray(['ok' => true, 'status' => 404, 'files' => 0])
        ->and(pdDocuments($this->record->id))->toBe(0);
});

test('Add Service that waits for a deletion in progress finds the Person gone, and adds no service', function () {
    pdSetUp($this);
    $plain = pdPlainService($this);
    $before = ClientService::query()->where('client_id', $this->person->id)->count();
    $peer = $this->peerConnection();
    pdHoldPerson($peer, $this->person->id);

    $add = pdHttp($this, 'POST', route('admin.clients.services.store', $this->person->id, false), [
        'service_id' => $plain->id, 'sub_service_id' => null, 'status' => 'in_progress',
        'start_date' => '2026-10-01', 'end_date' => null, 'fee' => '8000.00', 'received_now' => null, 'remark' => null,
    ]);
    ChildWorkflow::waitUntilBlocked();

    expect($add->isRunning())->toBeTrue();

    $peer->table('clients')->where('id', $this->person->id)->update(['deleted_at' => now()]);
    $peer->commit();

    expect($add->result())->toMatchArray(['ok' => true, 'status' => 404])
        ->and(ClientService::query()->where('client_id', $this->person->id)->count())->toBe($before);
});

test('a deletion that waits for an upload in progress then erases the document that upload added', function () {
    pdSetUp($this);
    $peer = $this->peerConnection();
    pdHoldPerson($peer, $this->person->id); // the upload has locked the Person (and then the service) and is about to add the document

    $delete = pdHttp($this, 'DELETE', route('admin.clients.destroy', $this->person->id, false), ['confirmation' => $this->person->phone]);
    ChildWorkflow::waitUntilBlocked();

    expect($delete->isRunning())->toBeTrue();

    $peer->table('documents')->insert([
        'client_service_id' => $this->record->id, 'title' => 'CV', 'storage_key' => 'documents/pd-test.pdf', 'mime' => 'application/pdf',
        'size' => 10, 'client_can_download' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $peer->commit();

    $result = $delete->result();

    expect($result['ok'])->toBeTrue()
        ->and($result['status'])->toBeIn([200, 204, 302])
        ->and(pdDocuments($this->record->id))->toBe(0)
        ->and(Client::withTrashed()->find($this->person->id)->trashed())->toBeTrue();
});

test('a deletion that waits for an Add Service in progress deletes the Person, and the new service stays with a zero balance', function () {
    pdSetUp($this);
    $peer = $this->peerConnection();
    pdHoldPerson($peer, $this->person->id);

    $delete = pdHttp($this, 'DELETE', route('admin.clients.destroy', $this->person->id, false), ['confirmation' => $this->person->phone]);
    ChildWorkflow::waitUntilBlocked();

    $peer->table('client_services')->insert([
        'client_id' => $this->person->id, 'service_id' => $this->record->service_id, 'status' => 'in_progress',
        'start_date' => '2026-10-01', 'fee' => '8000.00', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $peer->commit();

    expect($delete->result()['ok'])->toBeTrue()
        ->and(Client::withTrashed()->find($this->person->id)->trashed())->toBeTrue();

    $added = ClientService::query()->where('client_id', $this->person->id)->latest('id')->first();
    expect($added->status->value)->toBe('in_progress')
        ->and($added->remaining())->toBe('0.00')
        ->and($added->fee)->toBe('8000.00');
});
