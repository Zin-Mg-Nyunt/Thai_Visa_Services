<?php

use App\Actions\JobSearch\AddWorker;
use App\Enums\ClientServiceStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Document;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A Job Search worker has a Client Portal of their own (approved: review finding S3 is intentional). They see their Job Search
 * service's status and money, and nothing more: never its documents, refunds, kept amount, remarks or job order. Review finding
 * S4: the portal does not even read Job Search documents (or those of a cancelled service) from the database any more.
 */
beforeEach(function () {
    app()->setLocale('en');
    $this->jobSearch = Service::factory()->jobSearch()->create(['has_documents' => true]);
    $this->admin = User::factory()->create();
    $this->order = JobOrder::factory()->create(['company_name' => 'Secret Factory Co.']);

    // A worker added the normal way, as a new person: they get a tracking token like every client.
    $this->worker = app(AddWorker::class)->handle(
        $this->order,
        null,
        ['name' => 'Ko Worker', 'phone' => '+66855554321', 'birthday' => '1995-05-05', 'gender' => 'male'],
        '12000.00',
        $this->admin->id,
    );
    $this->person = $this->worker->client;
    $this->record = $this->worker->clientService;
    Payment::factory()->create(['client_service_id' => $this->record->id, 'amount' => '5000.00']);
    $this->record->update(['refund_amount' => '0.00']);
    Document::factory()->create(['client_service_id' => $this->record->id, 'title' => 'Admin only passport scan']);
});

test('a worker added through a job order can sign in to their portal with their tracking token', function () {
    expect($this->person->unique_token)->toHaveLength(48);

    $this->post(route('track.verify', $this->person->unique_token), ['pin' => '4321'])
        ->assertRedirect(route('client.profile', $this->person->unique_token));

    $this->get(route('client.profile', $this->person->unique_token))->assertOk();
});

test('the worker sees their Job Search status, fee and money, and no document, refund, kept amount, remark or job order', function () {
    $this->post(route('track.verify', $this->person->unique_token), ['pin' => '4321']);

    $response = $this->get(route('client.profile', $this->person->unique_token));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('services.data', 1)
        ->where('services.data.0.status', 'in_progress')
        ->where('services.data.0.fee', '12000.00')
        ->where('services.data.0.received', '5000.00')
        ->where('services.data.0.remaining', '7000.00')
        ->where('services.data.0.payment_status', 'deposit'));

    $service = $response->inertiaProps('services.data.0');
    expect(array_keys($service))->toBe(['id', 'service', 'sub_service', 'status', 'fee', 'received', 'remaining', 'payment_status']);

    expect($response->getContent())->not->toContain('Admin only passport scan')
        ->not->toContain('Secret Factory Co.')
        ->not->toContain('refund')
        ->not->toContain('kept')
        ->not->toContain('storage_key');
});

test('the portal never reads a Job Search document from the database, In Progress, Complete or Cancelled', function (ClientServiceStatus $status) {
    $this->record->update(['status' => $status, 'cancelled_at' => $status === ClientServiceStatus::Cancel ? now() : null]);

    // A Work Permit of the same person still lists its documents.
    $workPermit = ClientService::factory()->forService(Service::factory()->create(['has_documents' => true]))
        ->create(['client_id' => $this->person->id]);
    Document::factory()->create(['client_service_id' => $workPermit->id, 'title' => 'Work permit card']);

    $this->post(route('track.verify', $this->person->unique_token), ['pin' => '4321']);

    DB::enableQueryLog();
    $response = $this->get(route('client.profile', $this->person->unique_token))->assertOk();
    $documentQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'from "documents"'));
    DB::disableQueryLog();

    expect($documentQueries)->toHaveCount(1)
        ->and($documentQueries->first()['query'])->toContain('in ('.$workPermit->id.')')
        ->and($documentQueries->first()['query'])->not->toContain((string) $this->record->id.')')
        ->and($response->getContent())->toContain('Work permit card')
        ->not->toContain('Admin only passport scan');
})->with([ClientServiceStatus::InProgress, ClientServiceStatus::Complete, ClientServiceStatus::Cancel]);

test('a client whose only service is Job Search makes no documents query at all', function () {
    $this->post(route('track.verify', $this->person->unique_token), ['pin' => '4321']);

    DB::enableQueryLog();
    $this->get(route('client.profile', $this->person->unique_token))->assertOk();
    $documentQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'from "documents"'));
    DB::disableQueryLog();

    expect($documentQueries)->toBeEmpty();
});

test('the worker cannot download a Job Search document, even one an admin tried to release', function () {
    $document = Document::query()->where('client_service_id', $this->record->id)->firstOrFail();
    DB::table('documents')->where('id', $document->id)->update(['client_can_download' => true]);

    $this->post(route('track.verify', $this->person->unique_token), ['pin' => '4321']);

    $this->get(route('client.documents.download', [$this->person->unique_token, $document->id]))->assertNotFound();
});

test('a soft-deleted worker has no portal any more', function () {
    $token = $this->person->unique_token;
    DB::transaction(fn () => Client::query()->lockForUpdate()->findOrFail($this->person->id)->delete());

    $this->post(route('track.verify', $token), ['pin' => '4321'])->assertNotFound();
    $this->get(route('client.profile', $token))->assertNotFound();
});
