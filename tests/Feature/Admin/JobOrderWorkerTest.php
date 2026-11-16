<?php

use App\Enums\ClientServiceStatus;
use App\Enums\WorkerCaseStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    app()->setLocale('en');
    $this->jobSearch = Service::factory()->jobSearch()->create(['default_fee' => '3000.00']);
    $this->admin = User::factory()->create();
    $this->order = JobOrder::factory()->create();
});

/**
 * @return array<string, mixed>
 */
function newWorkerInput(array $overrides = []): array
{
    return [
        'name' => 'Aung Min',
        'phone' => '081 234 5678',
        'birthday' => '1995-03-12',
        'gender' => 'male',
        'fee' => '5000',
        ...$overrides,
    ];
}

test('guests are redirected and non-superadmins are refused', function () {
    $this->post(route('admin.job-search.workers.store', $this->order), newWorkerInput())->assertRedirect(route('login'));
    $this->post(route('admin.job-search.workers.lookup', $this->order))->assertRedirect(route('login'));

    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);

    $this->actingAs($user->fresh())->post(route('admin.job-search.workers.store', $this->order), newWorkerInput())->assertForbidden();
    $this->actingAs($user->fresh())->post(route('admin.job-search.workers.lookup', $this->order), ['phone' => '+66812345678'])->assertForbidden();

    expect(Client::count())->toBe(0)->and(JobOrderWorker::count())->toBe(0);
});

describe('looking a phone number up', function () {
    test('finds an active person whatever way the number is typed', function (string $typed) {
        $client = Client::factory()->create(['phone' => '+66812345678', 'name' => 'Aung Min']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.job-search.workers.lookup', $this->order), ['phone' => $typed])
            ->assertOk()
            ->assertJson([
                'phone' => '+66812345678',
                'client' => ['id' => $client->id, 'name' => 'Aung Min', 'phone' => '+66812345678'],
                'on_this_order' => false,
            ]);
    })->with(['081 234 5678', '+66 81 234 5678', '0066812345678']);

    test('says when no one has the number, and never finds a deleted person', function () {
        Client::factory()->create(['phone' => '+66812345678'])->delete();

        $this->actingAs($this->admin)
            ->postJson(route('admin.job-search.workers.lookup', $this->order), ['phone' => '0812345678'])
            ->assertOk()
            ->assertJson(['phone' => '+66812345678', 'client' => null, 'on_this_order' => false]);
    });

    test('says when the person is already on this order, but not on another', function () {
        $worker = JobOrderWorker::factory()->create(['job_order_id' => $this->order->id]);
        $other = JobOrder::factory()->create();

        $this->actingAs($this->admin)
            ->postJson(route('admin.job-search.workers.lookup', $this->order), ['phone' => $worker->client->phone])
            ->assertJson(['on_this_order' => true]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.job-search.workers.lookup', $other), ['phone' => $worker->client->phone])
            ->assertJson(['on_this_order' => false]);
    });

    test('refuses a missing or invalid phone number, and a closed order', function () {
        $this->actingAs($this->admin)
            ->postJson(route('admin.job-search.workers.lookup', $this->order))
            ->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->actingAs($this->admin)
            ->postJson(route('admin.job-search.workers.lookup', $this->order), ['phone' => '12'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');

        $closed = JobOrder::factory()->done()->create();
        $this->actingAs($this->admin)
            ->postJson(route('admin.job-search.workers.lookup', $closed), ['phone' => '0812345678'])
            ->assertForbidden();
    });
});

describe('adding a worker', function () {
    test('an existing person is reused and gets a Job Search record with the fee', function () {
        $client = Client::factory()->create();
        ClientService::factory()->create(['client_id' => $client->id]);

        $this->actingAs($this->admin)
            ->from(route('admin.job-search.show', $this->order))
            ->post(route('admin.job-search.workers.store', $this->order), ['client_id' => $client->id, 'fee' => '4500.5'])
            ->assertRedirect(route('admin.job-search.show', $this->order));

        $worker = JobOrderWorker::sole();
        $record = $worker->clientService;

        expect(Client::count())->toBe(1)
            ->and($worker->client_id)->toBe($client->id)
            ->and($worker->job_order_id)->toBe($this->order->id)
            ->and($worker->case_status)->toBe(WorkerCaseStatus::Applied)
            ->and($record->client_id)->toBe($client->id)
            ->and($record->service_id)->toBe($this->jobSearch->id)
            ->and($record->sub_service_id)->toBeNull()
            ->and($record->status)->toBe(ClientServiceStatus::InProgress)
            ->and($record->fee)->toBe('4500.50')
            ->and($client->clientServices()->count())->toBe(2);
    });

    test('a new person is created from the dialog, with the phone normalised', function () {
        $this->actingAs($this->admin)
            ->from(route('admin.job-search.edit', $this->order))
            ->post(route('admin.job-search.workers.store', $this->order), newWorkerInput())
            ->assertRedirect(route('admin.job-search.edit', $this->order));

        $client = Client::sole();

        expect($client->name)->toBe('Aung Min')
            ->and($client->phone)->toBe('+66812345678')
            ->and($client->birthday->toDateString())->toBe('1995-03-12')
            ->and($client->unique_token)->not->toBeEmpty()
            ->and(JobOrderWorker::sole()->client_id)->toBe($client->id)
            ->and(JobOrderWorker::sole()->clientService->fee)->toBe('5000.00');
    });

    test('the fee stays exact', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.job-search.workers.store', $this->order), newWorkerInput(['fee' => '9999999999.99']))
            ->assertSessionHasNoErrors();

        expect(JobOrderWorker::sole()->clientService->fresh()->fee)->toBe('9999999999.99');
    });

    test('a new person cannot reuse the phone number of an active person', function () {
        Client::factory()->create(['phone' => '+66812345678']);

        $this->actingAs($this->admin)
            ->post(route('admin.job-search.workers.store', $this->order), newWorkerInput())
            ->assertSessionHasErrors('phone');

        expect(Client::count())->toBe(1)->and(JobOrderWorker::count())->toBe(0);
    });

    test('the phone number of a deleted person can be used for a new person', function () {
        Client::factory()->create(['phone' => '+66812345678'])->delete();

        $this->actingAs($this->admin)
            ->post(route('admin.job-search.workers.store', $this->order), newWorkerInput())
            ->assertSessionHasNoErrors();

        expect(JobOrderWorker::count())->toBe(1);
    });

    test('a person is on an order once, but may be on another', function () {
        $client = Client::factory()->create();
        $other = JobOrder::factory()->create();
        $url = route('admin.job-search.workers.store', $this->order);

        $this->actingAs($this->admin)->post($url, ['client_id' => $client->id, 'fee' => '1000'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post($url, ['client_id' => $client->id, 'fee' => '1000'])
            ->assertSessionHasErrors(['client_id' => 'This person is already on this job order.']);
        $this->actingAs($this->admin)->post(route('admin.job-search.workers.store', $other), ['client_id' => $client->id, 'fee' => '1000'])
            ->assertSessionHasNoErrors();

        expect(JobOrderWorker::count())->toBe(2)
            ->and($client->clientServices()->count())->toBe(2);
    });

    test('a deleted or unknown person cannot be picked', function () {
        $deleted = Client::factory()->create();
        $deleted->delete();

        foreach ([$deleted->id, 9999] as $id) {
            $this->actingAs($this->admin)
                ->post(route('admin.job-search.workers.store', $this->order), ['client_id' => $id, 'fee' => '1000'])
                ->assertSessionHasErrors('client_id');
        }

        expect(JobOrderWorker::count())->toBe(0);
    });

    test('manpower is a request, not a limit: more workers than asked can be added', function () {
        $order = JobOrder::factory()->create(['manpower' => 1]);

        foreach ([1, 2] as $number) {
            $this->actingAs($this->admin)
                ->post(route('admin.job-search.workers.store', $order), newWorkerInput(['phone' => "+6681234567{$number}", 'name' => "Worker {$number}"]))
                ->assertSessionHasNoErrors();
        }

        expect($order->workers()->count())->toBe(2);

        $this->actingAs($this->admin)
            ->get(route('admin.job-search.edit', $order))
            ->assertInertia(fn (Assert $page) => $page->where('jobOrder.data.exceeds_manpower', true)->has('workers.data', 2));
    });

    test('a closed order takes no new worker and nothing is created', function (string $state) {
        $closed = JobOrder::factory()->{$state}()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.job-search.workers.store', $closed), newWorkerInput())
            ->assertForbidden();

        expect(Client::count())->toBe(0)->and(JobOrderWorker::count())->toBe(0)->and(ClientService::count())->toBe(0);
    })->with(['done', 'cancelled']);

    test('an order closed a moment ago takes no worker and the new person is not left behind', function () {
        $ran = false;

        // Loaded as open for the policy check; another admin closes it just before the action locks the row.
        JobOrder::retrieved(function (JobOrder $loaded) use (&$ran) {
            if (! $ran) {
                $ran = true;
                DB::table('job_orders')->where('id', $loaded->id)->update(['status' => 'done', 'closed_at' => now()]);
            }
        });

        $this->actingAs($this->admin)
            ->post(route('admin.job-search.workers.store', $this->order), newWorkerInput())
            ->assertRedirect(route('admin.job-search.index'));

        expect(Client::count())->toBe(0)->and(JobOrderWorker::count())->toBe(0)->and(ClientService::count())->toBe(0);
    });

    test('a missing Job Search service gives a clear message, not a 404, and nothing is left behind', function () {
        DB::table('services')->where('id', $this->jobSearch->id)->update(['key' => null]);

        $this->actingAs($this->admin)
            ->post(route('admin.job-search.workers.store', $this->order), newWorkerInput())
            ->assertRedirect();

        expect(Client::count())->toBe(0)->and(JobOrderWorker::count())->toBe(0)->and(ClientService::count())->toBe(0)
            ->and(session('inertia.flash_data.toast.message') ?? session('toast.message'))->toBe('The Job Search service is not set up.');
    });

    test('a soft-deleted Job Search service is also reported, not a 404', function () {
        $this->jobSearch->delete();

        $this->actingAs($this->admin)
            ->post(route('admin.job-search.workers.store', $this->order), newWorkerInput())
            ->assertRedirect();

        expect(Client::count())->toBe(0)->and(JobOrderWorker::count())->toBe(0);
    });

    test('the fee and the new person are checked', function (array $input, string $field) {
        $this->actingAs($this->admin)
            ->post(route('admin.job-search.workers.store', $this->order), newWorkerInput($input))
            ->assertSessionHasErrors($field);

        expect(Client::count())->toBe(0)->and(JobOrderWorker::count())->toBe(0);
    })->with([
        'no fee' => [['fee' => ''], 'fee'],
        'negative fee' => [['fee' => '-1'], 'fee'],
        'three decimals' => [['fee' => '10.001'], 'fee'],
        'fee over the limit' => [['fee' => '10000000000'], 'fee'],
        'fee text' => [['fee' => 'abc'], 'fee'],
        'no name' => [['name' => ''], 'name'],
        'no birthday' => [['birthday' => ''], 'birthday'],
        'birthday today' => [['birthday' => now()->toDateString()], 'birthday'],
        'no gender' => [['gender' => ''], 'gender'],
        'bad gender' => [['gender' => 'other'], 'gender'],
        'no phone' => [['phone' => ''], 'phone'],
        'bad phone' => [['phone' => '12'], 'phone'],
    ]);
});

describe('the edit page', function () {
    test('lists the workers with their money as exact strings, newest first, and offers the Job Search fee', function () {
        $first = JobOrderWorker::factory()->create(['job_order_id' => $this->order->id]);
        $first->clientService->update(['fee' => '5000.00']);
        Payment::factory()->create(['client_service_id' => $first->client_service_id, 'amount' => '1500.50']);
        $second = JobOrderWorker::factory()->create(['job_order_id' => $this->order->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.job-search.edit', $this->order))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/job-search/Edit')
                ->where('workerFee', '3000.00')
                ->has('workers.data', 2)
                ->where('workers.data.0.id', $second->id)
                ->where('workers.data.0.payment_status', 'unpaid')
                ->where('workers.data.1.fee', '5000.00')
                ->where('workers.data.1.received', '1500.50')
                ->where('workers.data.1.remaining', '3499.50')
                ->where('workers.data.1.payment_status', 'deposit')
                ->where('workers.data.1.case_status', 'applied'));
    });

    test('hides a deleted person but keeps the other orders\' workers out', function () {
        $visible = JobOrderWorker::factory()->create(['job_order_id' => $this->order->id]);
        $deleted = JobOrderWorker::factory()->create(['job_order_id' => $this->order->id]);
        JobOrderWorker::factory()->create();
        $deleted->client->delete();

        $this->actingAs($this->admin)
            ->get(route('admin.job-search.edit', $this->order))
            ->assertInertia(fn (Assert $page) => $page
                ->has('workers.data', 1)
                ->where('workers.data.0.id', $visible->id));
    });

    test('the Job Search fee is empty when none is set', function () {
        $this->jobSearch->update(['default_fee' => null]);

        $this->actingAs($this->admin)
            ->get(route('admin.job-search.edit', $this->order))
            ->assertInertia(fn (Assert $page) => $page->where('workerFee', null));
    });
});

describe('worker counts', function () {
    /**
     * @return array{active: JobOrderWorker, cancelled: JobOrderWorker, deleted: JobOrderWorker}
     */
    function orderWithEveryKindOfWorker(JobOrder $order): array
    {
        $active = JobOrderWorker::factory()->create(['job_order_id' => $order->id]);
        $complete = JobOrderWorker::factory()->create(['job_order_id' => $order->id]);
        $complete->clientService->update(['status' => ClientServiceStatus::Complete]);
        $cancelled = JobOrderWorker::factory()->create(['job_order_id' => $order->id]);
        $cancelled->clientService->update(['status' => ClientServiceStatus::Cancel, 'cancelled_at' => now()]);
        $deleted = JobOrderWorker::factory()->create(['job_order_id' => $order->id]);
        $deleted->client->delete();

        return ['active' => $active, 'cancelled' => $cancelled, 'deleted' => $deleted];
    }

    test('active workers are not deleted and not cancelled; cancelled ones are counted apart; deleted people are in neither', function () {
        orderWithEveryKindOfWorker($this->order);

        $this->actingAs($this->admin)
            ->get(route('admin.job-search.show', $this->order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobOrder.data.active_workers_count', 2)
                ->where('jobOrder.data.cancelled_workers_count', 1));

        $this->actingAs($this->admin)
            ->get(route('admin.job-search.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobOrders.data.0.active_workers_count', 2)
                ->where('jobOrders.data.0.cancelled_workers_count', 1));
    });

    test('the over-manpower warning compares manpower with the active workers only', function (int $manpower, bool $warns) {
        $order = JobOrder::factory()->create(['manpower' => $manpower]);
        orderWithEveryKindOfWorker($order);

        // 2 active, 1 cancelled, 1 deleted: only the 2 active count.
        $this->actingAs($this->admin)
            ->get(route('admin.job-search.show', $order))
            ->assertInertia(fn (Assert $page) => $page->where('jobOrder.data.exceeds_manpower', $warns));
    })->with([
        'manpower 1: 2 active exceed it' => [1, true],
        'manpower 2: cancelled and deleted do not push it over' => [2, false],
        'manpower 3' => [3, false],
    ]);

    test('a rejected worker is not active, even with a service in progress, and is not counted as cancelled', function () {
        $order = JobOrder::factory()->create(['manpower' => 1]);
        JobOrderWorker::factory()->create(['job_order_id' => $order->id]);
        $rejected = JobOrderWorker::factory()->create(['job_order_id' => $order->id, 'case_status' => WorkerCaseStatus::Rejected]);

        expect($rejected->clientService->status)->toBe(ClientServiceStatus::InProgress);

        $this->actingAs($this->admin)
            ->get(route('admin.job-search.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobOrder.data.active_workers_count', 1)
                ->where('jobOrder.data.cancelled_workers_count', 0)
                ->where('jobOrder.data.exceeds_manpower', false)
                // Still listed: a rejected worker stays part of the order's history.
                ->has('workers.data', 2));

        $this->actingAs($this->admin)
            ->get(route('admin.job-search.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobOrders.data.0.active_workers_count', 1)
                ->where('jobOrders.data.0.exceeds_manpower', false));
    });

    test('rejecting a worker frees a place: the over-manpower warning goes away', function () {
        $order = JobOrder::factory()->create(['manpower' => 1]);
        JobOrderWorker::factory()->create(['job_order_id' => $order->id]);
        $second = JobOrderWorker::factory()->create(['job_order_id' => $order->id, 'case_status' => WorkerCaseStatus::Interview]);

        $this->actingAs($this->admin)
            ->get(route('admin.job-search.show', $order))
            ->assertInertia(fn (Assert $page) => $page->where('jobOrder.data.exceeds_manpower', true));

        $second->update(['case_status' => WorkerCaseStatus::Rejected]);

        $this->actingAs($this->admin)
            ->get(route('admin.job-search.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobOrder.data.active_workers_count', 1)
                ->where('jobOrder.data.exceeds_manpower', false));
    });

    test('rejecting a worker keeps their record, payments and worker row', function () {
        $worker = JobOrderWorker::factory()->create(['job_order_id' => $this->order->id]);
        Payment::factory()->create(['client_service_id' => $worker->client_service_id, 'amount' => '1500.25']);

        $worker->update(['case_status' => WorkerCaseStatus::Rejected]);

        $record = ClientService::with('payments')->find($worker->client_service_id);

        expect(JobOrderWorker::find($worker->id))->not->toBeNull()
            ->and($record->status)->toBe(ClientServiceStatus::InProgress)
            ->and($record->kept())->toBe('1500.25');
    });

    test('every case status but rejected and cancelled counts as active', function (WorkerCaseStatus $status, int $active) {
        $order = JobOrder::factory()->create();
        JobOrderWorker::factory()->create(['job_order_id' => $order->id, 'case_status' => $status]);

        expect(JobOrder::query()->withWorkerCounts()->find($order->id)->active_workers_count)->toBe($active);
    })->with([
        'applied' => [WorkerCaseStatus::Applied, 1],
        'interview' => [WorkerCaseStatus::Interview, 1],
        'hired' => [WorkerCaseStatus::Hired, 1],
        'rejected' => [WorkerCaseStatus::Rejected, 0],
        'cancelled' => [WorkerCaseStatus::Cancelled, 0],
    ]);

    test('a cancelled worker stays on the order and is listed and marked; a deleted person is not listed', function () {
        $workers = orderWithEveryKindOfWorker($this->order);

        $this->actingAs($this->admin)
            ->get(route('admin.job-search.show', $this->order))
            ->assertInertia(fn (Assert $page) => $page
                ->has('workers.data', 3)
                ->where('workers.data', fn ($list) => collect($list)->pluck('id')->doesntContain($workers['deleted']->id)
                    && collect($list)->firstWhere('id', $workers['cancelled']->id)['service_status'] === 'cancel'
                    && collect($list)->firstWhere('id', $workers['active']->id)['service_status'] === 'in_progress'));
    });

    test('deleting a person keeps the money of their Job Search record', function () {
        $worker = JobOrderWorker::factory()->create(['job_order_id' => $this->order->id]);
        $worker->clientService->update(['fee' => '5000.00']);
        Payment::factory()->create(['client_service_id' => $worker->client_service_id, 'amount' => '1200.50']);

        $worker->client->delete();

        $record = ClientService::with('payments')->find($worker->client_service_id);

        expect($record->received())->toBe('1200.50')
            ->and($record->kept())->toBe('1200.50')
            ->and(JobOrderWorker::find($worker->id))->not->toBeNull()
            ->and(JobOrder::query()->withWorkerCounts()->find($this->order->id)->active_workers_count)->toBe(0);
    });

    test('a cancelled worker does not count as active, so it frees a place and the order is not over manpower', function () {
        $order = JobOrder::factory()->create(['manpower' => 1]);
        $first = JobOrderWorker::factory()->create(['job_order_id' => $order->id]);
        $first->clientService->update(['status' => ClientServiceStatus::Cancel, 'cancelled_at' => now()]);
        JobOrderWorker::factory()->create(['job_order_id' => $order->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.job-search.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobOrder.data.active_workers_count', 1)
                ->where('jobOrder.data.cancelled_workers_count', 1)
                ->where('jobOrder.data.exceeds_manpower', false));
    });
});

describe('the show page', function () {
    test('guests are redirected and non-superadmins are refused', function () {
        $this->get(route('admin.job-search.show', $this->order))->assertRedirect(route('login'));

        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);
        $this->actingAs($user->fresh())->get(route('admin.job-search.show', $this->order))->assertForbidden();
    });

    test('works for an open order and for a closed one, which has no edit page', function (string $state) {
        $order = JobOrder::factory()->{$state}()->create();
        $worker = JobOrderWorker::factory()->create(['job_order_id' => $order->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.job-search.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/job-search/Show')
                ->where('jobOrder.data.id', $order->id)
                ->where('jobOrder.data.can_edit', false)
                ->has('workers.data', 1)
                ->where('workers.data.0.id', $worker->id));

        $this->actingAs($this->admin)->get(route('admin.job-search.edit', $order))->assertForbidden();
    })->with(['done', 'cancelled']);

    test('an open order shows as editable with its workers', function () {
        JobOrderWorker::factory()->create(['job_order_id' => $this->order->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.job-search.show', $this->order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobOrder.data.can_edit', true)
                ->where('workerFee', '3000.00')
                ->has('workers.data', 1));
    });

    test('an unknown order is a 404', function () {
        $this->actingAs($this->admin)->get(route('admin.job-search.show', 999))->assertNotFound();
    });

    test('it loads the workers in a fixed number of queries', function () {
        JobOrderWorker::factory()->count(2)->create(['job_order_id' => $this->order->id]);
        $this->actingAs($this->admin)->get(route('admin.job-search.show', $this->order));

        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('admin.job-search.show', $this->order));
        $few = count(DB::getQueryLog());
        DB::flushQueryLog();

        JobOrderWorker::factory()->count(6)->create(['job_order_id' => $this->order->id]);
        DB::flushQueryLog();
        $this->actingAs($this->admin)->get(route('admin.job-search.show', $this->order));
        $many = count(DB::getQueryLog());

        expect($many)->toBe($few);
    });
});

describe('messages', function () {
    test('the errors the worker flow can show have Burmese translations', function (string $message) {
        app()->setLocale('my');

        expect(__($message))->not->toBe($message);

        app()->setLocale('en');
    })->with([
        'closed' => ['This job order is already closed.'],
        'duplicate' => ['This person is already on this job order.'],
        'missing service' => ['The Job Search service is not set up.'],
        'other person' => ["A worker's service record must belong to the same person."],
        'not job search' => ["A worker's service record must be a Job Search record."],
    ]);
});
