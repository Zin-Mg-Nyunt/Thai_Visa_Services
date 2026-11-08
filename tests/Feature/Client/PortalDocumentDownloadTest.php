<?php

use App\Enums\DocumentAccessOutcome;
use App\Enums\DocumentActor;
use App\Enums\DocumentDenyReason;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

const FILE_BYTES = "%PDF-1.4\nthe real permit\n%%EOF\n";

beforeEach(function () {
    Storage::fake(Document::DISK);

    $this->client = Client::factory()->create(['phone' => '+66812345678']);
    $this->workPermit = Service::factory()->create(['title' => 'Work Permit', 'has_documents' => true]);
    $this->record = ClientService::factory()->forService($this->workPermit)->create(['client_id' => $this->client->id, 'fee' => '8000.00']);

    $this->post(route('track.verify', $this->client->unique_token), ['pin' => '5678']);
});

/**
 * A document with a real file on the (fake) private disk.
 *
 * @param  array<string, mixed>  $state
 */
function documentWithFile(ClientService $record, array $state = []): Document
{
    $document = Document::factory()->create([
        'client_service_id' => $record->id, 'title' => 'Work permit card', 'storage_key' => 'documents/'.fake()->uuid().'.pdf', ...$state,
    ]);
    Storage::disk(Document::DISK)->put($document->storage_key, FILE_BYTES);

    return $document;
}

function fullyPaid(ClientService $record): void
{
    Payment::factory()->create(['client_service_id' => $record->id, 'amount' => $record->fee]);
}

function downloadFor(Client $client, Document $document): TestResponse
{
    return test()->get(route('client.documents.download', [$client->unique_token, $document->id]));
}

test('a fully paid document downloads with the real file and safe headers, and the access is logged', function () {
    fullyPaid($this->record);
    $document = documentWithFile($this->record);

    $response = downloadFor($this->client, $document)->assertOk();

    expect($response->streamedContent())->toBe(FILE_BYTES)
        ->and($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->headers->get('Content-Disposition'))->toContain('Work permit card.pdf')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    $log = DocumentAccessLog::sole();

    expect($log->outcome)->toBe(DocumentAccessOutcome::Allowed)
        ->and($log->reason)->toBeNull()
        ->and($log->actor_type)->toBe(DocumentActor::Client)
        ->and($log->actor_id)->toBe($this->client->id)
        ->and($log->client_id)->toBe($this->client->id)
        ->and($log->document_id)->toBe($document->id)
        ->and($log->document_title)->toBe('Work permit card')
        ->and($log->ip)->not->toBeNull();
});

test('a document released by the admin downloads even when the service is not paid', function () {
    $document = documentWithFile($this->record, ['client_can_download' => true]);

    downloadFor($this->client, $document)->assertOk();

    expect(DocumentAccessLog::sole()->outcome)->toBe(DocumentAccessOutcome::Allowed);
});

test('a locked document is refused with 403, gives no file and is logged as locked', function (array $payments) {
    $document = documentWithFile($this->record);
    foreach ($payments as $amount) {
        Payment::factory()->create(['client_service_id' => $this->record->id, 'amount' => $amount]);
    }

    $response = downloadFor($this->client, $document)->assertForbidden();

    expect($response->getContent())->not->toContain('the real permit');

    $log = DocumentAccessLog::sole();

    expect($log->outcome)->toBe(DocumentAccessOutcome::Denied)
        ->and($log->reason)->toBe(DocumentDenyReason::Locked)
        ->and($log->actor_id)->toBe($this->client->id)
        ->and($log->document_id)->toBe($document->id);
})->with([
    'unpaid' => [[]],
    'a deposit' => [['3000.00']],
    'one satang short' => [['7999.99']],
]);

test('another client\'s document is a 404 that reveals nothing, and the attempt is logged as not_owner', function () {
    $owner = Client::factory()->create();
    $ownerRecord = ClientService::factory()->forService($this->workPermit)->create(['client_id' => $owner->id, 'fee' => '8000.00']);
    fullyPaid($ownerRecord);
    $secret = documentWithFile($ownerRecord, ['title' => 'Someone else card', 'client_can_download' => true]);

    $response = downloadFor($this->client, $secret)->assertNotFound();

    expect($response->getContent())->not->toContain('Someone else card')
        ->and($response->getContent())->not->toContain('the real permit');

    $log = DocumentAccessLog::sole();

    expect($log->outcome)->toBe(DocumentAccessOutcome::Denied)
        ->and($log->reason)->toBe(DocumentDenyReason::NotOwner)
        ->and($log->actor_id)->toBe($this->client->id)
        ->and($log->client_id)->toBe($owner->id)
        ->and($log->document_id)->toBe($secret->id);
});

test('a document that does not exist is a 404 with no log row', function () {
    $this->get(route('client.documents.download', [$this->client->unique_token, 99999]))->assertNotFound();

    expect(DocumentAccessLog::count())->toBe(0);
});

test('another client\'s link with this client\'s session goes to the PIN page and logs nothing', function () {
    $other = Client::factory()->create();
    $otherRecord = ClientService::factory()->forService($this->workPermit)->create(['client_id' => $other->id]);
    $document = documentWithFile($otherRecord, ['client_can_download' => true]);

    $this->get(route('client.documents.download', [$other->unique_token, $document->id]))
        ->assertRedirect(route('track.show', $other->unique_token));

    expect(DocumentAccessLog::count())->toBe(0);
});

test('without a client session the download goes to the PIN page, gives no file and logs nothing', function () {
    $fresh = Client::factory()->create();
    $record = ClientService::factory()->forService($this->workPermit)->create(['client_id' => $fresh->id]);
    $document = documentWithFile($record, ['client_can_download' => true]);

    $this->get(route('client.documents.download', [$fresh->unique_token, $document->id]))
        ->assertRedirect(route('track.show', $fresh->unique_token));

    expect(DocumentAccessLog::count())->toBe(0);
});

test('own documents that are not offered to the client are a 404 logged as not_available, even if released', function (Closure $make) {
    $document = $make($this);

    downloadFor($this->client, $document)->assertNotFound();

    $log = DocumentAccessLog::sole();

    expect($log->outcome)->toBe(DocumentAccessOutcome::Denied)
        ->and($log->reason)->toBe(DocumentDenyReason::NotAvailable);
})->with([
    'a cancelled service' => [function ($test) {
        $test->record->update(['status' => 'cancel', 'cancelled_at' => now()]);

        return documentWithFile($test->record, ['client_can_download' => true]);
    }],
    'a Job Search record' => [function ($test) {
        $jobSearch = Service::factory()->create(['key' => Service::JOB_SEARCH_KEY, 'has_documents' => true]);
        $record = ClientService::factory()->forService($jobSearch)->create(['client_id' => $test->client->id]);

        return documentWithFile($record, ['client_can_download' => true]);
    }],
    'a service without documents' => [function ($test) {
        $plain = Service::factory()->create(['has_documents' => false]);
        $record = ClientService::factory()->forService($plain)->create(['client_id' => $test->client->id]);

        return documentWithFile($record, ['client_can_download' => true]);
    }],
]);

test('a record whose file is gone from the disk is a 404 logged as not_available', function () {
    $document = documentWithFile($this->record, ['client_can_download' => true]);
    Storage::disk(Document::DISK)->delete($document->storage_key);

    downloadFor($this->client, $document)->assertNotFound();

    expect(DocumentAccessLog::sole()->reason)->toBe(DocumentDenyReason::NotAvailable);
});

test('knowing the file location is not enough: the private disk has no URL at all', function () {
    $document = documentWithFile($this->record, ['client_can_download' => true]);

    expect(Route::has('storage.private'))->toBeFalse()
        ->and(Route::has('storage.private.upload'))->toBeFalse()
        ->and(Route::has('storage.local'))->toBeFalse();

    $this->get('/private-files/'.$document->storage_key)->assertNotFound();
    $this->get('/private-files/'.$document->storage_key.'?signature=abc&expires=1')->assertNotFound();
    $this->put('/private-files/documents/planted.pdf', ['file' => 'x'])->assertNotFound();
});

test('a file name that is unsafe in a header is cleaned and a Burmese title survives', function (string $title, string $expected) {
    $document = documentWithFile($this->record, ['title' => $title, 'client_can_download' => true]);

    $response = downloadFor($this->client, $document)->assertOk();
    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->toContain('attachment')
        ->and($disposition)->not->toContain("\n")
        ->and(rawurldecode($disposition))->toContain($expected);
})->with([
    'slashes and quotes' => ['../../etc/passwd "x"', 'etc'],
    'a new line' => ["Line1\r\nInjected: header", 'Line1'],
    'Burmese' => ['အလုပ်လုပ်ခွင့် ကတ်', 'အလုပ်လုပ်ခွင့် ကတ်.pdf'],
]);

test('downloads are rate limited to 10 a minute for each client and the 11th writes no log row', function () {
    $document = documentWithFile($this->record, ['client_can_download' => true]);

    foreach (range(1, 10) as $unused) {
        downloadFor($this->client, $document)->assertOk();
    }

    downloadFor($this->client, $document)->assertStatus(429);

    expect(DocumentAccessLog::count())->toBe(10);
});

test('the limit is per client, so another client is not blocked', function () {
    $document = documentWithFile($this->record, ['client_can_download' => true]);
    foreach (range(1, 10) as $unused) {
        downloadFor($this->client, $document)->assertOk();
    }
    downloadFor($this->client, $document)->assertStatus(429);

    $other = Client::factory()->create(['phone' => '+66899990000']);
    $otherRecord = ClientService::factory()->forService($this->workPermit)->create(['client_id' => $other->id]);
    $otherDocument = documentWithFile($otherRecord, ['client_can_download' => true]);
    $this->post(route('track.verify', $other->unique_token), ['pin' => '0000']);

    downloadFor($other, $otherDocument)->assertOk();
});

test('on Spaces the browser is sent to a signed link that lasts a couple of minutes, and the issue is logged', function () {
    config(['filesystems.disks.'.Document::DISK.'.driver' => 's3']);
    $document = documentWithFile($this->record, ['client_can_download' => true]);

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->with($document->storage_key)->andReturn(true);
    $disk->shouldReceive('temporaryUrl')
        ->once()
        ->withArgs(function (string $key, $expires, array $options) use ($document) {
            return $key === $document->storage_key
                && $expires->lessThanOrEqualTo(now()->addMinutes(5))
                && $expires->greaterThan(now())
                && str_contains($options['ResponseContentDisposition'], 'attachment');
        })
        ->andReturn('https://spaces.example.test/signed-link');
    Storage::set(Document::DISK, $disk);

    downloadFor($this->client, $document)->assertRedirect('https://spaces.example.test/signed-link');

    expect(DocumentAccessLog::sole()->outcome)->toBe(DocumentAccessOutcome::Allowed)
        ->and(Document::SIGNED_URL_MINUTES)->toBeLessThanOrEqual(5);
});

test('a denied attempt on Spaces never creates a signed link', function () {
    config(['filesystems.disks.'.Document::DISK.'.driver' => 's3']);
    $document = documentWithFile($this->record);

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldNotReceive('temporaryUrl');
    Storage::set(Document::DISK, $disk);

    downloadFor($this->client, $document)->assertForbidden();
});
