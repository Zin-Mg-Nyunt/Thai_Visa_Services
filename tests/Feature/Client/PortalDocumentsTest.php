<?php

use App\Models\Client;
use App\Models\ClientService;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->client = Client::factory()->create(['phone' => '+66812345678']);
    $this->workPermit = Service::factory()->create(['title' => 'Work Permit', 'has_documents' => true]);
    $this->record = ClientService::factory()->forService($this->workPermit)->create(['client_id' => $this->client->id, 'fee' => '8000.00']);

    $this->post(route('track.verify', $this->client->unique_token), ['pin' => '5678']);
});

function portalDocs(mixed $test): TestResponse
{
    return $test->get(route('client.profile', $test->client->unique_token));
}

function pay(ClientService $record, string $amount): void
{
    Payment::factory()->create(['client_service_id' => $record->id, 'amount' => $amount]);
}

test('a Work Permit lists its documents by title, locked until it is fully paid', function (array $payments, bool $unlocked) {
    Document::factory()->create(['client_service_id' => $this->record->id, 'title' => 'Work permit card']);
    foreach ($payments as $amount) {
        pay($this->record, $amount);
    }

    portalDocs($this)->assertInertia(fn (Assert $page) => $page
        ->has('services.data.0.documents', 1)
        ->where('services.data.0.documents.0.title', 'Work permit card')
        ->where('services.data.0.documents.0.unlocked', $unlocked));
})->with([
    'unpaid' => [[], false],
    'a deposit' => [['3000.00'], false],
    'one satang short' => [['7999.99'], false],
    'fully paid' => [['8000.00'], true],
    'fully paid in parts' => [['5000.00', '3000.00'], true],
]);

test('a document released by the admin is downloadable even when not paid, and unlocking it again locks it', function () {
    $document = Document::factory()->released()->create(['client_service_id' => $this->record->id]);
    pay($this->record, '1000.00');

    portalDocs($this)->assertInertia(fn (Assert $page) => $page
        ->where('services.data.0.documents.0.unlocked', true)
        ->where('services.data.0.payment_status', 'deposit'));

    $document->update(['client_can_download' => false]);

    portalDocs($this)->assertInertia(fn (Assert $page) => $page->where('services.data.0.documents.0.unlocked', false));
});

test('only the id, the title and the state of a document are sent, never the file key, size, type or release flag', function () {
    Document::factory()->released()->create([
        'client_service_id' => $this->record->id, 'title' => 'Permit', 'storage_key' => 'documents/very-secret-key.pdf', 'mime' => 'application/pdf', 'size' => 4242421,
    ]);

    portalDocs($this)->assertInertia(fn (Assert $page) => $page
        ->where('services.data.0.documents.0', fn ($document) => collect($document)->keys()->sort()->values()->all() === ['id', 'title', 'unlocked']));

    $props = json_encode(portalDocs($this)->viewData('page')['props'] ?? []);

    expect($props)->not->toContain('very-secret-key')
        ->and($props)->not->toContain('storage_key')
        ->and($props)->not->toContain('client_can_download')
        ->and($props)->not->toContain('4242421')
        ->and($props)->not->toContain('uploaded_by');
});

test('a Work Permit with no documents lists none', function () {
    portalDocs($this)->assertInertia(fn (Assert $page) => $page->where('services.data.0.documents', []));
});

test('a cancelled service lists no documents, even a released one', function () {
    Document::factory()->released()->create(['client_service_id' => $this->record->id, 'title' => 'Released card']);
    $this->record->update(['status' => 'cancel', 'cancelled_at' => now()]);

    portalDocs($this)->assertInertia(fn (Assert $page) => $page
        ->where('services.data.0.status', 'cancel')
        ->missing('services.data.0.documents'));

    expect(json_encode(portalDocs($this)->viewData('page')['props'] ?? []))->not->toContain('Released card');
});

test('Job Search documents are never listed and a service without documents has no list', function () {
    $jobSearch = Service::factory()->create(['key' => Service::JOB_SEARCH_KEY, 'has_documents' => true]);
    $plain = Service::factory()->create(['has_documents' => false]);
    $jobRecord = ClientService::factory()->forService($jobSearch)->create(['client_id' => $this->client->id]);
    $plainRecord = ClientService::factory()->forService($plain)->create(['client_id' => $this->client->id]);
    Document::factory()->released()->create(['client_service_id' => $jobRecord->id, 'title' => 'Job order secret']);
    Document::factory()->released()->create(['client_service_id' => $plainRecord->id, 'title' => 'Plain secret']);

    portalDocs($this)->assertInertia(fn (Assert $page) => $page
        ->has('services.data', 3)
        ->where('services.data', fn ($services) => collect($services)->filter(fn ($service) => array_key_exists('documents', $service))->count() === 1));

    $props = json_encode(portalDocs($this)->viewData('page')['props'] ?? []);

    expect($props)->not->toContain('Job order secret')
        ->and($props)->not->toContain('Plain secret');
});

test('a client never sees the documents of another client', function () {
    $other = Client::factory()->create();
    $otherRecord = ClientService::factory()->forService($this->workPermit)->create(['client_id' => $other->id]);
    Document::factory()->released()->create(['client_service_id' => $otherRecord->id, 'title' => 'Someone else card']);
    Document::factory()->create(['client_service_id' => $this->record->id, 'title' => 'My card']);

    portalDocs($this)->assertInertia(fn (Assert $page) => $page
        ->has('services.data.0.documents', 1)
        ->where('services.data.0.documents.0.title', 'My card'));

    expect(json_encode(portalDocs($this)->viewData('page')['props'] ?? []))->not->toContain('Someone else card');
});

test('the number of queries does not grow with the number of documents', function () {
    Document::factory()->create(['client_service_id' => $this->record->id]);
    pay($this->record, '500.00');

    DB::enableQueryLog();
    portalDocs($this)->assertOk();
    $one = count(DB::getQueryLog());

    foreach (range(1, 6) as $unused) {
        Document::factory()->create(['client_service_id' => $this->record->id]);
    }
    ClientService::factory()->forService($this->workPermit)->create(['client_id' => $this->client->id]);

    DB::flushQueryLog();
    portalDocs($this)->assertOk();

    expect(count(DB::getQueryLog()))->toBe($one);
});
