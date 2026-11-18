<?php

use App\Actions\JobSearch\JobOrderMoney;
use App\Enums\ClientServiceStatus;
use App\Enums\WorkerCaseStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\ClientServiceRemark;
use App\Models\Document;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Person deletion (business rules 1.1, Step 13): the Person row is locked first, the remaining balance of every service of a deleted
 * Person is derived as zero (nothing is written), a photo that cannot be removed is reported, and Add Service takes the same first
 * lock as the deletion, so it cannot add a service to a Person who is being deleted.
 */
beforeEach(function () {
    app()->setLocale('en');
    Storage::fake(Document::DISK);
    $this->admin = User::factory()->create();
    $this->person = Client::factory()->create(['phone' => '+66812345678']);
    $this->service = Service::factory()->create(['has_documents' => true]);
});

function deletePerson(object $test, Client $person): TestResponse
{
    return $test->actingAs($test->admin)->delete(route('admin.clients.destroy', $person), ['confirmation' => '+66812345678']);
}

function addServiceTo(object $test, Client $person): TestResponse
{
    return $test->actingAs($test->admin)->post(route('admin.clients.services.store', $person), [
        'service_id' => $test->service->id, 'sub_service_id' => null, 'status' => 'in_progress',
        'start_date' => '2026-10-01', 'end_date' => null, 'fee' => '8000.00', 'received_now' => null, 'remark' => null,
    ]);
}

function personWithPhoto(Client $person): string
{
    $path = Client::PHOTO_DIRECTORY.'/p.jpg';
    Storage::disk(Client::PHOTO_DISK)->put($path, 'jpeg');
    $person->forceFill(['photo_path' => $path])->save();

    return $path;
}

describe('the remaining balance of a deleted Person is zero, derived, and nothing else changes', function () {
    test('every service shows zero remaining and takes no payment, whatever its status, while fee, payments, refunds and status stay', function (ClientServiceStatus $status) {
        $record = ClientService::factory()->forService($this->service)->create([
            'client_id' => $this->person->id, 'status' => $status, 'fee' => '20000.00', 'refund_amount' => '0.00',
        ]);
        Payment::factory()->create(['client_service_id' => $record->id, 'amount' => '15000.00']);
        $before = ClientService::query()->find($record->id);
        $beforeRemaining = $before->remaining();

        deletePerson($this, $this->person)->assertSessionHasNoErrors();

        $after = ClientService::query()->find($record->id);

        expect($beforeRemaining)->toBe($status === ClientServiceStatus::Cancel ? '0.00' : '5000.00')
            ->and($after->remaining())->toBe('0.00')
            ->and($after->canTakePayment())->toBeFalse()
            ->and($after->fee)->toBe('20000.00')
            ->and($after->received())->toBe('15000.00')
            ->and($after->kept())->toBe($before->kept())
            ->and($after->status)->toBe($status)
            ->and(Payment::count())->toBe(1);
    })->with([ClientServiceStatus::InProgress, ClientServiceStatus::Complete, ClientServiceStatus::Cancel]);

    test('a Person who is not deleted keeps the balance', function () {
        $record = ClientService::factory()->forService($this->service)->create(['client_id' => $this->person->id, 'fee' => '20000.00']);
        Payment::factory()->create(['client_service_id' => $record->id, 'amount' => '15000.00']);

        expect(ClientService::query()->find($record->id)->remaining())->toBe('5000.00')
            ->and(ClientService::query()->find($record->id)->canTakePayment())->toBeTrue();
    });

    test('a Job Search engagement, its applications, its remarks and the Job Order money are untouched by the deletion', function () {
        $jobSearch = Service::factory()->jobSearch()->create();
        $order = JobOrder::factory()->create();
        $record = ClientService::factory()->forService($jobSearch)->create([
            'client_id' => $this->person->id, 'status' => ClientServiceStatus::Complete, 'fee' => '15000.00',
        ]);
        Payment::factory()->create(['client_service_id' => $record->id, 'amount' => '15000.00']);
        ClientServiceRemark::query()->create(['client_service_id' => $record->id, 'kind' => 'note', 'body' => 'kept']);
        $worker = JobOrderWorker::factory()->create([
            'job_order_id' => $order->id, 'client_id' => $this->person->id, 'client_service_id' => $record->id, 'case_status' => WorkerCaseStatus::Hired,
        ]);
        $moneyBefore = app(JobOrderMoney::class)->forOrder($order->id)['amount'];
        $orderBefore = DB::table('job_orders')->where('id', $order->id)->first();

        deletePerson($this, $this->person)->assertSessionHasNoErrors();

        expect($record->fresh()->status)->toBe(ClientServiceStatus::Complete)
            ->and($record->fresh()->fee)->toBe('15000.00')
            ->and($worker->fresh()->case_status)->toBe(WorkerCaseStatus::Hired)
            ->and(ClientServiceRemark::where('client_service_id', $record->id)->count())->toBe(1)
            ->and(app(JobOrderMoney::class)->forOrder($order->id)['amount'])->toBe($moneyBefore)
            ->and(DB::table('job_orders')->where('id', $order->id)->first())->toEqual($orderBefore)
            ->and(ClientService::query()->find($record->id)->remaining())->toBe('0.00');
    });

    test('a payment cannot be recorded on a deleted Person\'s service', function () {
        $record = ClientService::factory()->forService($this->service)->create(['client_id' => $this->person->id, 'fee' => '20000.00']);
        deletePerson($this, $this->person);

        $this->actingAs($this->admin)->post(route('admin.clients.services.payments.store', [$this->person->id, $record]), ['amount' => '100.00', 'paid_at' => today()->toDateString(), 'method' => 'cash'])
            ->assertNotFound();

        expect(Payment::count())->toBe(0);
    });

    test('showing a Person with services costs no query per service for the owner', function () {
        foreach (range(1, 4) as $ignored) {
            ClientService::factory()->forService($this->service)->create(['client_id' => $this->person->id]);
        }

        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'from "clients"') && str_contains($query->sql, '"clients"."id" = ?') && str_contains($query->sql, 'limit 1')) {
                $queries++;
            }
        });
        $this->actingAs($this->admin)->get(route('admin.clients.show', $this->person))->assertOk();

        // One for the route binding (the Person); none per service.
        expect($queries)->toBeLessThanOrEqual(2);
    });
});

describe('the Person is locked first', function () {
    test('the deletion reads the Person before anything else in its transaction', function () {
        $record = ClientService::factory()->forService($this->service)->create(['client_id' => $this->person->id]);
        Document::factory()->create(['client_service_id' => $record->id]);
        personWithPhoto($this->person);

        $inside = false;
        $statements = [];
        Event::listen(TransactionBeginning::class, function () use (&$inside) {
            $inside = true;
        });
        DB::listen(function ($query) use (&$inside, &$statements) {
            if ($inside) {
                $statements[] = $query->sql;
            }
        });

        deletePerson($this, $this->person)->assertSessionHasNoErrors();

        // The very first statement of the transaction reads the Person row (with the lock on MySQL), before the photo is
        // cleared or the documents are listed.
        expect($statements[0])->toStartWith('select')->toContain('from "clients"')
            ->and(collect($statements)->search(fn ($sql) => str_contains($sql, 'from "documents"')))->toBeGreaterThan(0);
    });

    test('a Person who was deleted a moment earlier is a 404, not a second deletion', function () {
        $done = false;
        Event::listen(TransactionBeginning::class, function () use (&$done) {
            if (! $done) {
                $done = true;
                DB::table('clients')->where('id', $this->person->id)->update(['deleted_at' => now()]);
            }
        });

        deletePerson($this, $this->person)->assertNotFound();
    });

    test('the deletion erases every document, even one uploaded just before', function () {
        $record = ClientService::factory()->forService($this->service)->create(['client_id' => $this->person->id]);
        $key = 'documents/'.fake()->uuid().'.pdf';
        Storage::disk(Document::DISK)->put($key, 'x');
        Document::factory()->create(['client_service_id' => $record->id, 'storage_key' => $key]);

        deletePerson($this, $this->person)->assertSessionHasNoErrors();

        expect(Document::count())->toBe(0)
            ->and(Storage::disk(Document::DISK)->exists($key))->toBeFalse();
    });

    test('a document cannot be added to a deleted Person', function () {
        $record = ClientService::factory()->forService($this->service)->create(['client_id' => $this->person->id]);
        deletePerson($this, $this->person);

        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $this->actingAs($this->admin)->post(
            route('admin.clients.services.documents.store', [$this->person->id, $record]),
            ['title' => 'CV', 'file' => new UploadedFile($path, 'cv.pdf', null, null, true)],
        )->assertNotFound();

        expect(Document::count())->toBe(0);
    });
});

describe('Add Service takes the Person lock first', function () {
    test('a service is added after the Person is locked, and the landing numbers are refreshed once', function () {
        $inside = false;
        $order = [];
        Event::listen(TransactionBeginning::class, function () use (&$inside) {
            $inside = true;
        });
        DB::listen(function ($query) use (&$inside, &$order) {
            if ($inside && ! in_array('first', $order, true)) {
                $order[] = str_contains($query->sql, 'from "clients"') ? 'person' : 'other';
                $order[] = 'first';
            }
        });

        addServiceTo($this, $this->person)->assertSessionHasNoErrors();

        expect($order[0])->toBe('person')
            ->and(ClientService::count())->toBe(1);
    });

    test('a Person deleted between the request and the lock gets no service, a 404', function () {
        $done = false;
        Event::listen(TransactionBeginning::class, function () use (&$done) {
            if (! $done) {
                $done = true;
                DB::table('clients')->where('id', $this->person->id)->update(['deleted_at' => now()]);
            }
        });

        addServiceTo($this, $this->person)->assertNotFound();

        expect(ClientService::count())->toBe(0);
    });

    test('a Person who is not deleted still gets the service, with its payment and remark', function () {
        $this->actingAs($this->admin)->post(route('admin.clients.services.store', $this->person), [
            'service_id' => $this->service->id, 'sub_service_id' => null, 'status' => 'in_progress',
            'start_date' => '2026-10-01', 'end_date' => null, 'fee' => '8000.00', 'received_now' => '1000.00', 'remark' => 'first',
        ])->assertSessionHasNoErrors();

        $record = ClientService::query()->sole();
        expect($record->received())->toBe('1000.00')
            ->and($record->remarks()->count())->toBe(1);
    });
});

describe('a photo that cannot be removed is reported', function () {
    function failingPhotoDisk(): void
    {
        $disk = Mockery::mock(Storage::disk(Client::PHOTO_DISK))->makePartial();
        $disk->shouldReceive('delete')->andReturn(false);
        Storage::set(Client::PHOTO_DISK, $disk);
    }

    test('deleting a Person: the deletion succeeds and the failure is reported with the path', function () {
        Exceptions::fake();
        $path = personWithPhoto($this->person);
        failingPhotoDisk();

        deletePerson($this, $this->person)->assertSessionHasNoErrors();

        expect(Client::withTrashed()->find($this->person->id)->trashed())->toBeTrue()
            ->and(Client::withTrashed()->find($this->person->id)->photo_path)->toBeNull();
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), $path));
    });

    test('a rolled-back deletion keeps the photo and reports nothing', function () {
        Exceptions::fake();
        $path = personWithPhoto($this->person);

        try {
            DB::transaction(function () {
                $this->person->fresh()->delete();
                throw new RuntimeException('later step failed');
            });
        } catch (RuntimeException) {
        }

        expect(Storage::disk(Client::PHOTO_DISK)->exists($path))->toBeTrue()
            ->and($this->person->fresh()->photo_path)->toBe($path);
        Exceptions::assertNothingReported();
    });

    test('removing the photo by hand reports a failure too', function () {
        Exceptions::fake();
        $path = personWithPhoto($this->person);
        failingPhotoDisk();

        $this->actingAs($this->admin)->delete(route('admin.clients.photo.destroy', $this->person))->assertSessionHasNoErrors();

        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), $path));
    });

    test('replacing a photo reports an old file that cannot be removed, and the new one is saved', function () {
        Exceptions::fake();
        $old = personWithPhoto($this->person);
        failingPhotoDisk();

        $this->person->replacePhoto(uploadedJpeg());

        expect($this->person->fresh()->photo_path)->not->toBe($old);
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), $old));
    });

    test('a photo that is already gone is not a failure', function () {
        Exceptions::fake();
        personWithPhoto($this->person);
        Storage::disk(Client::PHOTO_DISK)->delete(Client::PHOTO_DIRECTORY.'/p.jpg');

        deletePerson($this, $this->person)->assertSessionHasNoErrors();

        Exceptions::assertNothingReported();
    });
});

function uploadedJpeg(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'photo');
    file_put_contents($path, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));

    return new UploadedFile($path, 'photo.jpg', null, null, true);
}
