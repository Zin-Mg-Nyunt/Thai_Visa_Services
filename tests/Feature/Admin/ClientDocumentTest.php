<?php

use App\Enums\DocumentActor;
use App\Models\ClientService;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

const PDF_BYTES = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAHjwV7gAAAABJRU5ErkJggg==';

beforeEach(function () {
    Storage::fake(Document::DISK);

    $this->admin = User::factory()->create();
    $this->workPermit = Service::factory()->create(['title' => 'Work Permit', 'has_documents' => true]);
    $this->record = ClientService::factory()->forService($this->workPermit)->create(['fee' => '8000.00']);
    $this->client = $this->record->client;
});

/**
 * A real file on disk, so the type is read from the content as in production (a fake upload takes it from the name).
 */
function realFile(string $content, string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function pdfFile(string $name = 'permit.pdf', int $extraKilobytes = 0): UploadedFile
{
    return realFile(PDF_BYTES.str_repeat('0', $extraKilobytes * 1024), $name);
}

function pngFile(string $name = 'scan.png', int $extraKilobytes = 0): UploadedFile
{
    return realFile(base64_decode(PNG_BASE64).str_repeat("\0", $extraKilobytes * 1024), $name);
}

/**
 * @param  array<string, mixed>  $input
 */
function uploadTo(mixed $test, ClientService $record, array $input = []): TestResponse
{
    return $test->actingAs($test->admin)->post(
        route('admin.clients.services.documents.store', [$record->client_id, $record]),
        ['title' => 'Work permit card', 'file' => pdfFile(), ...$input],
    );
}

describe('access', function () {
    test('guests are redirected to the login page', function () {
        $document = Document::factory()->create(['client_service_id' => $this->record->id]);

        $this->post(route('admin.clients.services.documents.store', [$this->client, $this->record]))->assertRedirect(route('login'));
        $this->patch(route('admin.clients.services.documents.update', [$this->client, $this->record, $document]))->assertRedirect(route('login'));
        $this->delete(route('admin.clients.services.documents.destroy', [$this->client, $this->record, $document]))->assertRedirect(route('login'));
    });

    test('a service without documents refuses an upload', function () {
        $record = ClientService::factory()->create();

        uploadTo($this, $record)->assertForbidden();

        expect(Document::count())->toBe(0);
    });

    test('a Job Search record refuses an upload for now', function () {
        $jobSearch = Service::factory()->create(['key' => Service::JOB_SEARCH_KEY, 'has_documents' => true]);
        $record = ClientService::factory()->forService($jobSearch)->create();

        uploadTo($this, $record)->assertForbidden();

        expect(Document::count())->toBe(0);
    });

    test('a document is only reachable through its own service and client', function () {
        $document = Document::factory()->create(['client_service_id' => $this->record->id]);
        $other = ClientService::factory()->forService($this->workPermit)->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.clients.services.documents.update', [$other->client_id, $other, $document]), ['title' => 'x'])
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->delete(route('admin.clients.services.documents.destroy', [$other->client_id, $other, $document]))
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->post(route('admin.clients.services.documents.store', [$other->client_id, $this->record]), ['title' => 'x', 'file' => pdfFile()])
            ->assertNotFound();

        expect($document->fresh())->not->toBeNull();
    });
});

describe('upload', function () {
    test('a PDF is saved on the private disk under a random name, never the title or the file name', function () {
        uploadTo($this, $this->record, ['title' => '../../etc/passwd Work permit', 'file' => pdfFile('my secret name.pdf')])
            ->assertRedirect(route('admin.clients.show', $this->client))
            ->assertSessionHasNoErrors();

        $document = Document::sole();

        expect($document->client_service_id)->toBe($this->record->id)
            ->and($document->title)->toBe('../../etc/passwd Work permit')
            ->and($document->mime)->toBe('application/pdf')
            ->and($document->size)->toBe(strlen(PDF_BYTES))
            ->and($document->client_can_download)->toBeFalse()
            ->and($document->uploaded_by)->toBe($this->admin->id)
            ->and($document->storage_key)->toMatch('#^documents/[0-9a-f-]{36}\.pdf$#');

        Storage::disk(Document::DISK)->assertExists($document->storage_key);
        expect(Storage::disk(Document::DISK)->allFiles())->toHaveCount(1);
    });

    test('a PNG is accepted and a new upload never takes the name of an earlier one', function () {
        uploadTo($this, $this->record, ['file' => pngFile('same.png')]);
        uploadTo($this, $this->record, ['file' => pngFile('same.png')]);

        $keys = Document::pluck('storage_key');

        expect($keys)->toHaveCount(2)
            ->and($keys->unique())->toHaveCount(2)
            ->and($keys->every(fn ($key) => str_ends_with($key, '.png')))->toBeTrue()
            ->and(Document::first()->mime)->toBe('image/png');
    });

    test('the file is judged by its content, not its name', function () {
        // A PNG named .pdf is stored as the PNG it really is.
        uploadTo($this, $this->record, ['file' => pngFile('looks-like.pdf')])->assertSessionHasNoErrors();

        $document = Document::sole();

        expect($document->mime)->toBe('image/png')
            ->and($document->storage_key)->toEndWith('.png');
    });

    test('a file that only has the right name is refused', function (Closure $file) {
        uploadTo($this, $this->record, ['file' => $file()])->assertSessionHasErrors('file');

        expect(Document::count())->toBe(0)
            ->and(Storage::disk(Document::DISK)->allFiles())->toBe([]);
    })->with([
        'text named .pdf' => [fn () => realFile('just some text', 'permit.pdf')],
        'script named .pdf' => [fn () => realFile('<?php echo 1;', 'permit.pdf')],
        'html named .png' => [fn () => realFile('<html><script>alert(1)</script></html>', 'scan.png')],
        'a PDF header with a wrong claimed type is fine, but a zip is not' => [fn () => realFile("PK\x03\x04zipdata", 'permit.pdf')],
        'an empty file' => [fn () => realFile('', 'permit.pdf')],
    ]);

    test('a PDF may be up to 5 MB and a picture up to 2 MB', function (Closure $file, bool $accepted) {
        uploadTo($this, $this->record, ['file' => $file()]);

        expect(Document::count())->toBe($accepted ? 1 : 0);
    })->with([
        'a 4 MB PDF' => [fn () => pdfFile(extraKilobytes: 4 * 1024), true],
        'a PDF over 5 MB' => [fn () => pdfFile(extraKilobytes: 5 * 1024 + 1), false],
        'a 1 MB picture' => [fn () => pngFile(extraKilobytes: 1024), true],
        'a picture over 2 MB' => [fn () => pngFile(extraKilobytes: 2 * 1024 + 1), false],
    ]);

    test('a file over the limit says so in a clear message, in both languages', function (string $locale, string $pdfMessage, string $imageMessage) {
        app()->setLocale($locale);

        uploadTo($this, $this->record, ['file' => pdfFile(extraKilobytes: 5 * 1024 + 1)])
            ->assertSessionHasErrors(['file' => $pdfMessage]);
        uploadTo($this, $this->record, ['file' => pngFile(extraKilobytes: 2 * 1024 + 1)])
            ->assertSessionHasErrors(['file' => $imageMessage]);
    })->with([
        'English' => [
            'en',
            'The file is too large. A PDF can be at most 5 MB and a JPG or PNG at most 2 MB.',
            'A JPG or PNG can be at most 2 MB.',
        ],
        'Burmese' => [
            'my',
            'ဖိုင်ကြီးလွန်းသည်။ PDF သည် ၅ MB အထိ၊ JPG သို့မဟုတ် PNG သည် ၂ MB အထိ ဖြစ်နိုင်သည်။',
            'JPG သို့မဟုတ် PNG သည် ၂ MB ထက် မကြီးရပါ။',
        ],
    ]);

    test('a file the server itself refused (its own upload limit) gets a clear message', function () {
        app()->setLocale('en');
        $tooBigForServer = new UploadedFile(pdfFile()->getRealPath(), 'big.pdf', null, UPLOAD_ERR_INI_SIZE, true);

        uploadTo($this, $this->record, ['file' => $tooBigForServer])
            ->assertSessionHasErrors(['file' => 'The server did not accept this file. It may be larger than the upload limit of the server (a PDF can be at most 5 MB, a JPG or PNG at most 2 MB).']);

        expect(Document::count())->toBe(0);
    });

    test('a missing title or file gets a clear message', function () {
        app()->setLocale('en');

        uploadTo($this, $this->record, ['title' => '', 'file' => null])
            ->assertSessionHasErrors([
                'title' => 'Enter a title for the document.',
                'file' => 'Choose a PDF, JPG or PNG file.',
            ]);
    });

    test('the title and the file are required and checked', function (array $input, string $field) {
        uploadTo($this, $this->record, $input)->assertSessionHasErrors($field);

        expect(Document::count())->toBe(0);
    })->with([
        'no title' => [['title' => ''], 'title'],
        'a very long title' => [['title' => str_repeat('a', 151)], 'title'],
        'no file' => [['file' => null], 'file'],
    ]);

    test('a failed save leaves no record and no file', function () {
        uploadTo($this, $this->record, ['title' => ''])->assertSessionHasErrors('title');

        expect(Document::count())->toBe(0)
            ->and(Storage::disk(Document::DISK)->allFiles())->toBe([]);
    });
});

describe('rename and release', function () {
    test('the title can be changed, and the file cannot', function () {
        $document = Document::factory()->create(['client_service_id' => $this->record->id, 'storage_key' => 'documents/keep.pdf']);

        $this->actingAs($this->admin)
            ->patch(route('admin.clients.services.documents.update', [$this->client, $this->record, $document]), [
                'title' => '  New title  ', 'storage_key' => 'documents/other.pdf', 'size' => 1,
            ])
            ->assertSessionHasNoErrors();

        expect($document->fresh()->title)->toBe('New title')
            ->and($document->fresh()->storage_key)->toBe('documents/keep.pdf')
            ->and($document->fresh()->size)->toBe($document->size);
    });

    test('the release switch changes only the release flag', function () {
        $document = Document::factory()->create(['client_service_id' => $this->record->id, 'title' => 'Keep me']);
        $url = route('admin.clients.services.documents.update', [$this->client, $this->record, $document]);

        $this->actingAs($this->admin)->patch($url, ['client_can_download' => true])->assertSessionHasNoErrors();
        expect($document->fresh()->client_can_download)->toBeTrue()
            ->and($document->fresh()->title)->toBe('Keep me');

        $this->actingAs($this->admin)->patch($url, ['client_can_download' => false]);
        expect($document->fresh()->client_can_download)->toBeFalse();
    });

    test('a release does not change the payment status', function () {
        $document = Document::factory()->create(['client_service_id' => $this->record->id]);

        $this->actingAs($this->admin)
            ->patch(route('admin.clients.services.documents.update', [$this->client, $this->record, $document]), ['client_can_download' => true]);

        expect($this->record->fresh()->paymentStatus()->value)->toBe('unpaid');
    });

    test('bad values are refused', function (array $input, string $field) {
        $document = Document::factory()->create(['client_service_id' => $this->record->id]);

        $this->actingAs($this->admin)
            ->patch(route('admin.clients.services.documents.update', [$this->client, $this->record, $document]), $input)
            ->assertSessionHasErrors($field);
    })->with([
        'an empty title' => [['title' => ''], 'title'],
        'a release that is not a boolean' => [['client_can_download' => 'maybe'], 'client_can_download'],
    ]);
});

describe('replace file', function () {
    /**
     * A saved document with a real file on the private disk, as an upload would leave it.
     */
    function savedDocument(mixed $test, array $state = []): Document
    {
        uploadTo($test, $test->record, ['title' => 'Work permit card']);
        $document = Document::sole();

        if ($state !== []) {
            $document->update($state);
        }

        return $document->fresh();
    }

    function replaceUrl(mixed $test, Document $document): string
    {
        return route('admin.clients.services.documents.replace', [$test->client, $test->record, $document]);
    }

    test('the new file takes the place of the old one and the title, release and history stay', function () {
        $document = savedDocument($this, ['client_can_download' => true]);
        $oldKey = $document->storage_key;
        $log = DocumentAccessLog::factory()->create(['document_id' => $document->id, 'client_id' => $this->client->id]);
        $other = User::factory()->create();

        $this->actingAs($other)
            ->post(replaceUrl($this, $document), ['file' => pngFile('new.png')])
            ->assertRedirect(route('admin.clients.show', $this->client))
            ->assertSessionHasNoErrors();

        $fresh = $document->fresh();

        expect($fresh->id)->toBe($document->id)
            ->and($fresh->title)->toBe('Work permit card')
            ->and($fresh->client_can_download)->toBeTrue()
            ->and($fresh->mime)->toBe('image/png')
            ->and($fresh->size)->toBe(strlen(base64_decode(PNG_BASE64)))
            ->and($fresh->uploaded_by)->toBe($other->id)
            ->and($fresh->storage_key)->not->toBe($oldKey)
            ->and($fresh->storage_key)->toMatch('#^documents/[0-9a-f-]{36}\.png$#')
            ->and($log->fresh()->document_id)->toBe($document->id)
            ->and(Document::count())->toBe(1);

        Storage::disk(Document::DISK)->assertMissing($oldKey);
        Storage::disk(Document::DISK)->assertExists($fresh->storage_key);
        expect(Storage::disk(Document::DISK)->allFiles())->toHaveCount(1);
    });

    test('the file is judged by its content, with the same size limits as an upload', function (Closure $file) {
        $document = savedDocument($this);
        $oldKey = $document->storage_key;

        $this->actingAs($this->admin)->post(replaceUrl($this, $document), ['file' => $file()])->assertSessionHasErrors('file');

        expect($document->fresh()->storage_key)->toBe($oldKey);
        Storage::disk(Document::DISK)->assertExists($oldKey);
        expect(Storage::disk(Document::DISK)->allFiles())->toHaveCount(1);
    })->with([
        'text named .pdf' => [fn () => realFile('just some text', 'permit.pdf')],
        'a PDF over 5 MB' => [fn () => pdfFile(extraKilobytes: 5 * 1024 + 1)],
        'a picture over 2 MB' => [fn () => pngFile(extraKilobytes: 2 * 1024 + 1)],
        'an empty file' => [fn () => realFile('', 'permit.pdf')],
    ]);

    test('a missing file gets a clear message', function () {
        app()->setLocale('en');
        $document = savedDocument($this);

        $this->actingAs($this->admin)->post(replaceUrl($this, $document), [])
            ->assertSessionHasErrors(['file' => 'Choose a PDF, JPG or PNG file.']);
    });

    test('replacing never changes the title or the release even if they are sent', function () {
        $document = savedDocument($this);

        $this->actingAs($this->admin)->post(replaceUrl($this, $document), [
            'file' => pdfFile('again.pdf'), 'title' => 'Hacked', 'client_can_download' => true, 'client_service_id' => 999,
        ]);

        expect($document->fresh()->title)->toBe('Work permit card')
            ->and($document->fresh()->client_can_download)->toBeFalse()
            ->and($document->fresh()->client_service_id)->toBe($this->record->id);
    });

    test('an old file that is already gone does not stop the replacement', function () {
        $document = savedDocument($this);
        Storage::disk(Document::DISK)->delete($document->storage_key);

        $this->actingAs($this->admin)->post(replaceUrl($this, $document), ['file' => pdfFile()])->assertSessionHasNoErrors();

        Storage::disk(Document::DISK)->assertExists($document->fresh()->storage_key);
    });

    test('only a signed-in superadmin may replace, and only through the document\'s own service', function () {
        $document = savedDocument($this);
        $oldKey = $document->storage_key;
        $other = ClientService::factory()->forService($this->workPermit)->create();

        // Saving the document signed the admin in; a guest is a visitor with no session.
        auth()->logout();
        $this->flushSession();

        $this->post(replaceUrl($this, $document), ['file' => pdfFile()])->assertRedirect(route('login'));
        $this->actingAs($this->admin)
            ->post(route('admin.clients.services.documents.replace', [$other->client_id, $other, $document]), ['file' => pdfFile()])
            ->assertNotFound();

        expect($document->fresh()->storage_key)->toBe($oldKey);
    });

    test('a replaced document keeps unlocking by payment like before', function () {
        $document = savedDocument($this);
        Payment::factory()->create(['client_service_id' => $this->record->id, 'amount' => '8000.00']);

        $this->actingAs($this->admin)->post(replaceUrl($this, $document), ['file' => pdfFile()]);

        expect($document->fresh()->isUnlocked())->toBeTrue();
    });
});

describe('delete', function () {
    test('deleting a document removes the record and the file but keeps the access history', function () {
        uploadTo($this, $this->record);
        $document = Document::sole();
        $key = $document->storage_key;
        $log = DocumentAccessLog::factory()->create([
            'document_id' => $document->id, 'client_id' => $this->client->id, 'document_title' => $document->title, 'actor_type' => DocumentActor::Client,
        ]);

        Storage::disk(Document::DISK)->assertExists($key);

        $this->actingAs($this->admin)
            ->delete(route('admin.clients.services.documents.destroy', [$this->client, $this->record, $document]))
            ->assertRedirect(route('admin.clients.show', $this->client));

        Storage::disk(Document::DISK)->assertMissing($key);
        expect(Document::count())->toBe(0)
            ->and($log->fresh()->document_id)->toBeNull()
            ->and($log->fresh()->document_title)->toBe('Work permit card')
            ->and($log->fresh()->client_id)->toBe($this->client->id);
    });

    test('a document whose file is already gone is still deleted', function () {
        $document = Document::factory()->create(['client_service_id' => $this->record->id, 'storage_key' => 'documents/missing.pdf']);

        $this->actingAs($this->admin)
            ->delete(route('admin.clients.services.documents.destroy', [$this->client, $this->record, $document]))
            ->assertSessionHasNoErrors();

        expect(Document::count())->toBe(0);
    });

    test('deleting a client erases all their documents and files, and only theirs', function () {
        uploadTo($this, $this->record, ['title' => 'First']);
        $second = ClientService::factory()->forService($this->workPermit)->create(['client_id' => $this->client->id]);
        uploadTo($this, $second, ['title' => 'Second']);
        $other = ClientService::factory()->forService($this->workPermit)->create();
        uploadTo($this, $other, ['title' => 'Someone else']);

        $keys = Document::whereIn('client_service_id', [$this->record->id, $second->id])->pluck('storage_key');
        $otherKey = Document::where('client_service_id', $other->id)->value('storage_key');
        $log = DocumentAccessLog::factory()->create(['document_id' => Document::first()->id, 'client_id' => $this->client->id]);

        $this->client->delete();

        foreach ($keys as $key) {
            Storage::disk(Document::DISK)->assertMissing($key);
        }
        Storage::disk(Document::DISK)->assertExists($otherKey);
        expect(Document::count())->toBe(1)
            ->and(Document::sole()->title)->toBe('Someone else')
            ->and($log->fresh())->not->toBeNull();
    });
});

describe('client page', function () {
    test('the page lists the documents and whether each is unlocked for the client', function () {
        Payment::factory()->create(['client_service_id' => $this->record->id, 'amount' => '3000.00']);
        $locked = Document::factory()->create(['client_service_id' => $this->record->id, 'title' => 'Locked']);
        $released = Document::factory()->released()->create(['client_service_id' => $this->record->id, 'title' => 'Released']);

        $this->actingAs($this->admin)
            ->get(route('admin.clients.show', $this->client))
            ->assertInertia(fn (Assert $page) => $page
                ->where('clientServices.data.0.accepts_documents', true)
                ->has('clientServices.data.0.documents', 2)
                ->where('clientServices.data.0.documents', fn ($documents) => collect($documents)->pluck('unlocked', 'id')->all() === [$released->id => true, $locked->id => false])
                ->missing('clientServices.data.0.documents.0.storage_key'));
    });

    test('a fully paid service unlocks its documents without a release', function () {
        Payment::factory()->create(['client_service_id' => $this->record->id, 'amount' => '8000.00']);
        Document::factory()->create(['client_service_id' => $this->record->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.clients.show', $this->client))
            ->assertInertia(fn (Assert $page) => $page
                ->where('clientServices.data.0.documents.0.unlocked', true)
                ->where('clientServices.data.0.documents.0.client_can_download', false));
    });

    test('only services that take documents offer them', function () {
        $jobSearch = Service::factory()->create(['key' => Service::JOB_SEARCH_KEY, 'has_documents' => true]);
        $plain = Service::factory()->create(['has_documents' => false]);
        ClientService::factory()->forService($jobSearch)->create(['client_id' => $this->client->id]);
        ClientService::factory()->forService($plain)->create(['client_id' => $this->client->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.clients.show', $this->client))
            ->assertInertia(fn (Assert $page) => $page
                ->where('clientServices.data', fn ($services) => collect($services)->pluck('accepts_documents')->sort()->values()->all() === [false, false, true]));
    });
});
