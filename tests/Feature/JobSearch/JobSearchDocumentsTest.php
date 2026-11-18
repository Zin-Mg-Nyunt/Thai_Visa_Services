<?php

use App\Enums\ClientServiceStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Documents of a Job Search engagement (business rules, Sections 7 to 9): full admin CRUD while In Progress or Complete,
 * read-only once Cancelled, admin only (never the client portal), and every change re-checks the Person and the service under a
 * lock, so an upload or edit that was on its way cannot land after a cancel or a deletion.
 */
beforeEach(function () {
    Storage::fake(Document::DISK);
    app()->setLocale('en');

    $this->admin = User::factory()->create();
    $this->jobSearch = Service::factory()->jobSearch()->create();
    $this->client = Client::factory()->create(['phone' => '+66812345678']);
    $this->record = ClientService::factory()->forService($this->jobSearch)->create([
        'client_id' => $this->client->id, 'status' => ClientServiceStatus::InProgress,
    ]);
});

function jsPdf(string $name = 'cv.pdf'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

    return new UploadedFile($path, $name, null, null, true);
}

function jsUpload(object $test, ?ClientService $record = null): TestResponse
{
    $record ??= $test->record;

    return $test->actingAs($test->admin)->post(
        route('admin.clients.services.documents.store', [$record->client_id, $record]),
        ['title' => 'CV', 'file' => jsPdf()],
    );
}

function jsDocument(object $test, ?ClientService $record = null): Document
{
    $record ??= $test->record;
    $key = 'documents/'.fake()->uuid().'.pdf';
    Storage::disk(Document::DISK)->put($key, 'old file');

    return Document::factory()->create(['client_service_id' => $record->id, 'storage_key' => $key, 'title' => 'Old title']);
}

function jsTarget(Document $document): array
{
    return [$document->clientService->client_id, $document->client_service_id, $document->id];
}

function setStatusBehindTheRequest(ClientService $record, ClientServiceStatus $status): void
{
    // The change commits after the request's first policy check and before the lock: it fires when the transaction begins.
    $done = false;
    Event::listen(TransactionBeginning::class, function () use ($record, $status, &$done) {
        if (! $done) {
            $done = true;
            DB::table('client_services')->where('id', $record->id)->update(['status' => $status->value]);
        }
    });
}

describe('In Progress and Complete engagements take full document CRUD', function () {
    test('upload works on an In Progress engagement, even though the Job Search service has no documents setting', function () {
        $this->jobSearch->update(['has_documents' => false]);

        jsUpload($this)->assertSessionHasNoErrors()->assertRedirect(route('admin.clients.show', $this->client));

        $document = Document::query()->sole();
        expect($document->client_service_id)->toBe($this->record->id)
            ->and($document->client_can_download)->toBeFalse()
            ->and($document->uploaded_by)->toBe($this->admin->id)
            ->and(Storage::disk(Document::DISK)->exists($document->storage_key))->toBeTrue();
    });

    test('upload, rename, replace and delete all work on a Complete engagement', function () {
        $this->record->update(['status' => ClientServiceStatus::Complete]);

        jsUpload($this)->assertSessionHasNoErrors();
        $document = Document::query()->sole();
        $oldKey = $document->storage_key;

        $this->actingAs($this->admin)->patch(route('admin.clients.services.documents.update', jsTarget($document)), ['title' => 'Renamed'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.clients.services.documents.replace', jsTarget($document)), ['file' => jsPdf('new.pdf')])
            ->assertSessionHasNoErrors();

        $document->refresh();
        expect($document->title)->toBe('Renamed')
            ->and($document->storage_key)->not->toBe($oldKey)
            ->and(Storage::disk(Document::DISK)->exists($oldKey))->toBeFalse()
            ->and(Storage::disk(Document::DISK)->exists($document->storage_key))->toBeTrue();

        $this->actingAs($this->admin)->delete(route('admin.clients.services.documents.destroy', jsTarget($document)))->assertSessionHasNoErrors();

        expect(Document::count())->toBe(0)
            ->and(Storage::disk(Document::DISK)->allFiles())->toBe([]);
    });

    test('the documents belong to the engagement, so they stay when the next job order application is made', function () {
        $document = jsDocument($this);

        expect($document->clientService->is($this->record))->toBeTrue();
        $this->actingAs($this->admin)->get(route('admin.clients.show', $this->client))->assertOk();
    });
});

describe('a Cancelled engagement is read-only for documents', function () {
    beforeEach(function () {
        $this->document = jsDocument($this);
        $this->record->update(['status' => ClientServiceStatus::Cancel, 'cancelled_at' => now()]);
    });

    test('upload, rename, replace and delete are refused and change nothing', function () {
        $key = $this->document->storage_key;

        jsUpload($this)->assertForbidden();
        $this->actingAs($this->admin)->patch(route('admin.clients.services.documents.update', jsTarget($this->document)), ['title' => 'Renamed'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('admin.clients.services.documents.replace', jsTarget($this->document)), ['file' => jsPdf()])->assertForbidden();
        $this->actingAs($this->admin)->delete(route('admin.clients.services.documents.destroy', jsTarget($this->document)))->assertForbidden();

        expect(Document::count())->toBe(1)
            ->and($this->document->fresh()->title)->toBe('Old title')
            ->and($this->document->fresh()->storage_key)->toBe($key)
            ->and(Storage::disk(Document::DISK)->allFiles())->toBe([$key]);
    });

    test('the admin can still download, and the download is logged', function () {
        $this->actingAs($this->admin)->get(route('admin.clients.services.documents.download', jsTarget($this->document)))->assertOk();

        expect(DocumentAccessLog::query()->where('document_id', $this->document->id)->count())->toBe(1);
    });

    test('the page lists the documents as read-only', function () {
        $this->actingAs($this->admin)->get(route('admin.clients.show', $this->client))->assertInertia(
            fn (Assert $page) => $page
                ->where('clientServices.data.0.accepts_documents', true)
                ->where('clientServices.data.0.documents_read_only', true)
                ->where('clientServices.data.0.documents_releasable', false)
                ->has('clientServices.data.0.documents', 1),
        );
    });

    test('cancelling the whole service through the workflow makes the documents read-only', function () {
        $this->record->update(['status' => ClientServiceStatus::InProgress, 'cancelled_at' => null]);
        jsUpload($this)->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('admin.clients.services.cancel', [$this->client, $this->record]), ['cancel_refund_amount' => null]);
        expect($this->record->fresh()->status)->toBe(ClientServiceStatus::Cancel);

        jsUpload($this)->assertForbidden();
        expect(Document::count())->toBe(2);
    });

    test('an In Progress engagement that returns from Hired -> Cancelled takes documents again', function () {
        // Cancel is only the whole-service state; an engagement back In Progress is not Cancelled.
        $this->record->update(['status' => ClientServiceStatus::InProgress, 'cancelled_at' => null]);

        jsUpload($this)->assertSessionHasNoErrors();
    });
});

describe('the locked re-check', function () {
    test('an upload on its way when the engagement is cancelled is refused, and its file is removed', function () {
        setStatusBehindTheRequest($this->record, ClientServiceStatus::Cancel);

        jsUpload($this)->assertForbidden();

        expect(Document::count())->toBe(0)
            ->and(Storage::disk(Document::DISK)->allFiles())->toBe([]);
    });

    test('a rename, a replacement and a delete on their way when the engagement is cancelled are refused', function (string $action) {
        $document = jsDocument($this);
        $key = $document->storage_key;
        setStatusBehindTheRequest($this->record, ClientServiceStatus::Cancel);

        $response = match ($action) {
            'rename' => $this->actingAs($this->admin)->patch(route('admin.clients.services.documents.update', jsTarget($document)), ['title' => 'Renamed']),
            'replace' => $this->actingAs($this->admin)->post(route('admin.clients.services.documents.replace', jsTarget($document)), ['file' => jsPdf()]),
            'delete' => $this->actingAs($this->admin)->delete(route('admin.clients.services.documents.destroy', jsTarget($document))),
        };

        $response->assertForbidden();
        expect($document->fresh())->not->toBeNull()
            ->and($document->fresh()->title)->toBe('Old title')
            ->and($document->fresh()->storage_key)->toBe($key)
            ->and(Storage::disk(Document::DISK)->allFiles())->toBe([$key]);
    })->with(['rename', 'replace', 'delete']);

    test('an upload on its way when the Person is deleted is refused, and its file is removed', function () {
        $done = false;
        Event::listen(TransactionBeginning::class, function () use (&$done) {
            if (! $done) {
                $done = true;
                DB::table('clients')->where('id', $this->client->id)->update(['deleted_at' => now()]);
            }
        });

        jsUpload($this)->assertNotFound();

        expect(Document::count())->toBe(0)
            ->and(Storage::disk(Document::DISK)->allFiles())->toBe([]);
    });

    test('the change takes the Person lock before the service lock', function () {
        // Only the queries inside the change's own transaction count: the route binding has read both rows before it.
        $inside = false;
        $order = [];
        Event::listen(TransactionBeginning::class, function () use (&$inside) {
            $inside = true;
        });
        DB::listen(function ($query) use (&$inside, &$order) {
            if (! $inside) {
                return;
            }
            foreach (['clients', 'client_services'] as $table) {
                if (str_contains($query->sql, 'from "'.$table.'"') && ! in_array($table, $order, true)) {
                    $order[] = $table;
                }
            }
        });

        jsUpload($this)->assertSessionHasNoErrors();

        expect($order)->toBe(['clients', 'client_services']);
    });
});

describe('Job Search documents are admin only', function () {
    test('the release switch is refused for a Job Search document', function () {
        $document = jsDocument($this);

        $this->actingAs($this->admin)->patch(route('admin.clients.services.documents.update', jsTarget($document)), ['client_can_download' => true])
            ->assertSessionHasErrors('client_can_download');

        expect($document->fresh()->client_can_download)->toBeFalse();
    });

    test('the client portal cannot download one, even if the flag was set by hand and the engagement is Complete', function () {
        $document = jsDocument($this);
        $document->update(['client_can_download' => true]);
        $this->record->update(['status' => ClientServiceStatus::Complete]);

        $this->post(route('track.verify', $this->client->unique_token), ['pin' => '5678']);

        $this->get(route('client.documents.download', [$this->client->unique_token, $document->id]))->assertNotFound();
    });

    test('a guest cannot upload', function () {
        $this->post(route('admin.clients.services.documents.store', [$this->client, $this->record]), ['title' => 'CV', 'file' => jsPdf()])
            ->assertRedirect();

        expect(Document::count())->toBe(0);
    });

    test('an ordinary service keeps its rules: no documents setting refuses upload, and its release switch still works', function () {
        $plain = ClientService::factory()->forService(Service::factory()->create(['has_documents' => false]))->create();
        jsUpload($this, $plain)->assertForbidden();

        $permit = ClientService::factory()->forService(Service::factory()->create(['has_documents' => true]))->create();
        jsUpload($this, $permit)->assertSessionHasNoErrors();
        $document = Document::query()->where('client_service_id', $permit->id)->sole();

        $this->actingAs($this->admin)->patch(route('admin.clients.services.documents.update', jsTarget($document)), ['client_can_download' => true])->assertSessionHasNoErrors();
        expect($document->fresh()->client_can_download)->toBeTrue();
    });

    test('a cancelled ordinary service is not made read-only by this rule', function () {
        $permit = ClientService::factory()->cancelled()->forService(Service::factory()->create(['has_documents' => true]))->create();

        jsUpload($this, $permit)->assertSessionHasNoErrors();
    });
});

test('deleting the Person deletes the engagement documents and their files', function () {
    $document = jsDocument($this);

    $this->actingAs($this->admin)->delete(route('admin.clients.destroy', $this->client), ['confirmation' => $this->client->phone])->assertSessionHasNoErrors();

    expect(Document::count())->toBe(0)
        ->and(Storage::disk(Document::DISK)->exists($document->storage_key))->toBeFalse();
});
