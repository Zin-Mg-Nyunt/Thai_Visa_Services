<?php

use App\Enums\JobOrderStatus;
use App\Enums\WorkerCaseStatus;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, mixed>
 */
function newJobOrderInput(): array
{
    return [
        'company_name' => 'Siam Hotels Co.',
        'manpower' => 5,
        'male_count' => 3,
        'female_count' => 2,
        'interview_date' => '2026-12-01',
        'start_date' => '2026-12-15',
        'notes' => 'Night shift',
    ];
}

test('guests are redirected to the login page', function (string $method, string $routeName) {
    $order = JobOrder::factory()->create();

    $this->{$method}(route($routeName, in_array($routeName, ['admin.job-search.edit', 'admin.job-search.update']) ? $order : []))
        ->assertRedirect(route('login'));
})->with([
    'index' => ['get', 'admin.job-search.index'],
    'create' => ['get', 'admin.job-search.create'],
    'store' => ['post', 'admin.job-search.store'],
    'edit' => ['get', 'admin.job-search.edit'],
    'update' => ['put', 'admin.job-search.update'],
]);

test('users without the superadmin role are refused', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);

    $this->actingAs($user->fresh())->get(route('admin.job-search.index'))->assertForbidden();
    $this->actingAs($user->fresh())->post(route('admin.job-search.store'), newJobOrderInput())->assertForbidden();

    expect(JobOrder::count())->toBe(0);
});

describe('index', function () {
    test('lists job orders newest first, ten per page, with their worker counts', function () {
        JobOrder::factory()->count(12)->create();
        $newest = JobOrder::factory()->create();
        JobOrderWorker::factory()->count(2)->create(['job_order_id' => $newest->id]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.job-search.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/job-search/Index')
                ->has('jobOrders.data', 10)
                ->where('jobOrders.data.0.id', $newest->id)
                ->where('jobOrders.data.0.applied_workers_count', 2)
                ->where('jobOrders.meta.total', 13));
    });

    test('warns when more workers are Hired than the company asked for, and only then', function () {
        $over = JobOrder::factory()->create(['manpower' => 1]);
        $exact = JobOrder::factory()->create(['manpower' => 2]);
        $applied = JobOrder::factory()->create(['manpower' => 1]);
        JobOrderWorker::factory()->count(2)->create(['job_order_id' => $over->id, 'case_status' => 'hired']);
        JobOrderWorker::factory()->count(2)->create(['job_order_id' => $exact->id, 'case_status' => 'hired']);
        JobOrderWorker::factory()->count(3)->create(['job_order_id' => $applied->id]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.job-search.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobOrders.data', fn ($orders) => collect($orders)->firstWhere('id', $over->id)['exceeds_manpower'] === true
                    && collect($orders)->firstWhere('id', $exact->id)['exceeds_manpower'] === false
                    // Applied workers are not capacity, however many there are.
                    && collect($orders)->firstWhere('id', $applied->id)['exceeds_manpower'] === false));
    });

    test('searches by company name, and % and _ search for themselves', function () {
        JobOrder::factory()->create(['company_name' => 'Siam Hotels']);
        JobOrder::factory()->create(['company_name' => 'Bangkok 100% Fresh']);
        JobOrder::factory()->create(['company_name' => 'Bangkok 100 Fresh']);

        $admin = User::factory()->create();

        $this->actingAs($admin)->get(route('admin.job-search.index', ['q' => 'siam']))
            ->assertInertia(fn (Assert $page) => $page->has('jobOrders.data', 1)->where('search', 'siam'));

        $this->actingAs($admin)->get(route('admin.job-search.index', ['q' => '100%']))
            ->assertInertia(fn (Assert $page) => $page->has('jobOrders.data', 1)->where('jobOrders.data.0.company_name', 'Bangkok 100% Fresh'));
    });

    test('filters by status, and an unknown status is ignored', function () {
        JobOrder::factory()->create();
        JobOrder::factory()->done()->create();
        JobOrder::factory()->cancelled()->create();

        $admin = User::factory()->create();

        $this->actingAs($admin)->get(route('admin.job-search.index', ['status' => 'done']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('jobOrders.data', 1)
                ->where('jobOrders.data.0.status', 'done')
                ->where('filters.status', 'done'));

        $this->actingAs($admin)->get(route('admin.job-search.index', ['status' => 'nonsense']))
            ->assertInertia(fn (Assert $page) => $page->has('jobOrders.data', 3));
    });

    test('a closed order cannot be edited, an open one can', function () {
        $open = JobOrder::factory()->create();
        $closed = JobOrder::factory()->done()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.job-search.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobOrders.data', fn ($orders) => collect($orders)->firstWhere('id', $open->id)['can_edit'] === true
                    && collect($orders)->firstWhere('id', $closed->id)['can_edit'] === false));
    });
});

describe('create and store', function () {
    test('shows the form', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.job-search.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/job-search/Create'));
    });

    test('stores an open order with what the company asked for', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.job-search.store'), newJobOrderInput())
            ->assertRedirect(route('admin.job-search.index'));

        $order = JobOrder::sole();

        expect($order->company_name)->toBe('Siam Hotels Co.')
            ->and($order->manpower)->toBe(5)
            ->and($order->male_count)->toBe(3)
            ->and($order->female_count)->toBe(2)
            ->and($order->interview_date->toDateString())->toBe('2026-12-01')
            ->and($order->start_date->toDateString())->toBe('2026-12-15')
            ->and($order->notes)->toBe('Night shift')
            ->and($order->status)->toBe(JobOrderStatus::Open)
            ->and($order->closed_at)->toBeNull();
    });

    test('the status and closing time in the request are ignored', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.job-search.store'), [...newJobOrderInput(), 'status' => 'done', 'closed_at' => now()->toDateTimeString()])
            ->assertRedirect();

        expect(JobOrder::sole()->status)->toBe(JobOrderStatus::Open)
            ->and(JobOrder::sole()->closed_at)->toBeNull();
    });

    test('manpower is a request, not a limit: any male and female counts are accepted', function (int $manpower, int $male, int $female) {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.job-search.store'), [...newJobOrderInput(), 'manpower' => $manpower, 'male_count' => $male, 'female_count' => $female])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.job-search.index'));

        expect(JobOrder::sole()->only(['manpower', 'male_count', 'female_count']))->toBe(['manpower' => $manpower, 'male_count' => $male, 'female_count' => $female]);
    })->with([
        'split above manpower' => [2, 5, 4],
        'split below manpower' => [10, 1, 1],
        'no split at all' => [3, 0, 0],
        'no manpower' => [0, 2, 2],
    ]);

    test('only the notes and dates are optional, and the counts are checked', function (array $input, string $field) {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.job-search.store'), [...newJobOrderInput(), ...$input])
            ->assertSessionHasErrors($field);

        expect(JobOrder::count())->toBe(0);
    })->with([
        'no company' => [['company_name' => ''], 'company_name'],
        'long company' => [['company_name' => str_repeat('a', 256)], 'company_name'],
        'no manpower' => [['manpower' => ''], 'manpower'],
        'negative manpower' => [['manpower' => -1], 'manpower'],
        'fractional manpower' => [['manpower' => 1.5], 'manpower'],
        'manpower over the column size' => [['manpower' => 65536], 'manpower'],
        'male count over the column size' => [['male_count' => 65536], 'male_count'],
        'female count text' => [['female_count' => 'many'], 'female_count'],
        'bad interview date' => [['interview_date' => 'tomorrow-ish'], 'interview_date'],
        'bad start date' => [['start_date' => '31/02/2026'], 'start_date'],
        'long notes' => [['notes' => str_repeat('a', 2001)], 'notes'],
    ]);

    test('the start-working date cannot be before the interview date, but the same day or later is fine', function () {
        app()->setLocale('en');
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.job-search.store'), [...newJobOrderInput(), 'interview_date' => '2026-12-10', 'start_date' => '2026-12-09'])
            ->assertSessionHasErrors(['start_date' => 'The start-working date cannot be before the interview date.']);

        expect(JobOrder::count())->toBe(0);

        foreach (['2026-12-10', '2026-12-11'] as $start) {
            $this->actingAs($admin)
                ->post(route('admin.job-search.store'), [...newJobOrderInput(), 'interview_date' => '2026-12-10', 'start_date' => $start])
                ->assertSessionHasNoErrors();
        }

        expect(JobOrder::count())->toBe(2);
    });

    test('with no interview date any start-working date is accepted, and no start date is fine too', function () {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.job-search.store'), [...newJobOrderInput(), 'interview_date' => null, 'start_date' => '2020-01-01'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->post(route('admin.job-search.store'), [...newJobOrderInput(), 'interview_date' => '2026-12-10', 'start_date' => null])
            ->assertSessionHasNoErrors();

        expect(JobOrder::count())->toBe(2);
    });

    test('an update cannot move the start-working date before the interview date either', function () {
        $order = JobOrder::factory()->create(['interview_date' => '2026-12-10', 'start_date' => '2026-12-20']);

        $this->actingAs(User::factory()->create())
            ->put(route('admin.job-search.update', $order), [...newJobOrderInput(), 'interview_date' => '2026-12-10', 'start_date' => '2026-12-01'])
            ->assertSessionHasErrors('start_date');

        expect($order->fresh()->start_date->toDateString())->toBe('2026-12-20');
    });

    test('the Burmese message is used when the language is Burmese', function () {
        app()->setLocale('my');

        $this->actingAs(User::factory()->create())
            ->post(route('admin.job-search.store'), [...newJobOrderInput(), 'interview_date' => '2026-12-10', 'start_date' => '2026-12-09'])
            ->assertSessionHasErrors(['start_date' => 'စတင်အလုပ်လုပ်ရက်သည် အင်တာဗျူးရက်မတိုင်မီ မဖြစ်ရပါ။']);

        app()->setLocale('en');
    });

    test('the dates and notes may be empty', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.job-search.store'), [...newJobOrderInput(), 'interview_date' => null, 'start_date' => null, 'notes' => null])
            ->assertSessionHasNoErrors();

        expect(JobOrder::sole()->interview_date)->toBeNull();
    });
});

describe('edit and update', function () {
    test('shows the form for an open order, with the warning data', function () {
        $order = JobOrder::factory()->create(['manpower' => 1]);
        JobOrderWorker::factory()->count(2)->create(['job_order_id' => $order->id, 'case_status' => 'hired']);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.job-search.edit', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/job-search/Edit')
                ->where('jobOrder.data.id', $order->id)
                ->where('jobOrder.data.hired_workers_count', 2)
                ->where('jobOrder.data.exceeds_manpower', true));
    });

    test('updates an open order', function () {
        $order = JobOrder::factory()->create();

        $this->actingAs(User::factory()->create())
            ->put(route('admin.job-search.update', $order), newJobOrderInput())
            ->assertRedirect(route('admin.job-search.index'));

        expect($order->fresh()->company_name)->toBe('Siam Hotels Co.')
            ->and($order->fresh()->manpower)->toBe(5);
    });

    test('an update never changes the status, so it cannot close or reopen an order', function () {
        $order = JobOrder::factory()->create();

        $this->actingAs(User::factory()->create())
            ->put(route('admin.job-search.update', $order), [...newJobOrderInput(), 'status' => 'done', 'closed_at' => now()->toDateTimeString()])
            ->assertRedirect();

        expect($order->fresh()->status)->toBe(JobOrderStatus::Open)
            ->and($order->fresh()->closed_at)->toBeNull();
    });

    test('a closed order is completely frozen: no edit page and no update of any field', function (string $state) {
        $order = JobOrder::factory()->{$state}()->create(['company_name' => 'Original Co.', 'manpower' => 4, 'notes' => 'Original note']);
        $before = $order->fresh()->only(['company_name', 'manpower', 'male_count', 'female_count', 'interview_date', 'start_date', 'notes', 'status', 'closed_at']);
        $admin = User::factory()->create();

        $this->actingAs($admin)->get(route('admin.job-search.edit', $order))->assertForbidden();
        $this->actingAs($admin)->put(route('admin.job-search.update', $order), newJobOrderInput())->assertForbidden();
        $this->actingAs($admin)->put(route('admin.job-search.update', $order), ['notes' => 'Only a note'])->assertForbidden();

        expect($order->fresh()->only(array_keys($before)))->toEqual($before);
    })->with(['done', 'cancelled']);

    test('an unknown order is a 404', function () {
        $this->actingAs(User::factory()->create())->get(route('admin.job-search.edit', 999))->assertNotFound();
    });
});

describe('closing', function () {
    test('guests are redirected and non-superadmins are refused', function () {
        $order = JobOrder::factory()->create();

        $this->post(route('admin.job-search.close', $order), ['status' => 'done'])->assertRedirect(route('login'));

        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);
        $this->actingAs($user->fresh())->post(route('admin.job-search.close', $order), ['status' => 'done'])->assertForbidden();

        expect($order->fresh()->status)->toBe(JobOrderStatus::Open);
    });

    test('done and cancelled both close the order with the closing time', function (string $status, JobOrderStatus $expected) {
        $this->travelTo('2026-11-13 09:30:00');
        $order = JobOrder::factory()->create();

        $this->actingAs(User::factory()->create())
            ->from(route('admin.job-search.index'))
            ->post(route('admin.job-search.close', $order), ['status' => $status])
            ->assertRedirect(route('admin.job-search.index'));

        expect($order->fresh()->status)->toBe($expected)
            ->and($order->fresh()->closed_at->toDateTimeString())->toBe('2026-11-13 09:30:00');
    })->with([
        'done' => ['done', JobOrderStatus::Done],
        'cancelled' => ['cancelled', JobOrderStatus::Cancelled],
    ]);

    test('the status is required, and an order is never closed as open or as something else', function (mixed $status) {
        $order = JobOrder::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('admin.job-search.close', $order), ['status' => $status])
            ->assertSessionHasErrors('status');

        expect($order->fresh()->status)->toBe(JobOrderStatus::Open)
            ->and($order->fresh()->closed_at)->toBeNull();
    })->with([
        'missing' => [null],
        'open' => ['open'],
        'nonsense' => ['finished'],
    ]);

    test('a closed order cannot be closed again or reopened, and nothing changes', function (string $state, string $again) {
        $order = JobOrder::factory()->{$state}()->create();
        $closedAt = $order->fresh()->closed_at->toDateTimeString();
        $status = $order->fresh()->status;

        $this->actingAs(User::factory()->create())
            ->post(route('admin.job-search.close', $order), ['status' => $again])
            ->assertForbidden();

        expect($order->fresh()->status)->toBe($status)
            ->and($order->fresh()->closed_at->toDateTimeString())->toBe($closedAt);
    })->with([
        'done then cancel' => ['done', 'cancelled'],
        'cancelled then done' => ['cancelled', 'done'],
        'done then done' => ['done', 'done'],
    ]);

    test('closing leaves settled workers and their payments alone', function () {
        $order = JobOrder::factory()->create();
        // Only an open application (Applied or Interview) has to be resolved first; a settled one stays as it is.
        $worker = JobOrderWorker::factory()->create(['job_order_id' => $order->id, 'case_status' => WorkerCaseStatus::Rejected]);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.job-search.close', $order), ['status' => 'cancelled'])
            ->assertRedirect();

        expect($worker->fresh()->job_order_id)->toBe($order->id)
            ->and($worker->fresh()->case_status)->toBe(WorkerCaseStatus::Rejected)
            ->and($worker->clientService->fresh()->status->value)->toBe('in_progress')
            ->and($order->workers()->count())->toBe(1);
    });

    test('from the edit page the admin goes to the list, because a closed order has no edit page', function () {
        $order = JobOrder::factory()->create();

        $this->actingAs(User::factory()->create())
            ->from(route('admin.job-search.edit', $order))
            ->post(route('admin.job-search.close', $order), ['status' => 'done', 'from' => 'edit'])
            ->assertRedirect(route('admin.job-search.index'));

        expect($order->fresh()->status)->toBe(JobOrderStatus::Done);
    });

    test('an unknown "from" is refused', function () {
        $order = JobOrder::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('admin.job-search.close', $order), ['status' => 'done', 'from' => 'https://evil.example'])
            ->assertSessionHasErrors('from');

        expect($order->fresh()->status)->toBe(JobOrderStatus::Open);
    });

    test('an order closed by someone else a moment ago is not closed again and the first closing stays', function () {
        $order = JobOrder::factory()->create();
        $ran = false;

        // The order is loaded as open for the policy check; another admin closes it just before close() locks the row.
        JobOrder::retrieved(function (JobOrder $loaded) use (&$ran) {
            if (! $ran) {
                $ran = true;
                DB::table('job_orders')->where('id', $loaded->id)->update(['status' => 'done', 'closed_at' => '2026-11-13 08:00:00']);
            }
        });

        $this->actingAs(User::factory()->create())
            ->post(route('admin.job-search.close', $order), ['status' => 'cancelled'])
            ->assertRedirect();

        expect(DB::table('job_orders')->where('id', $order->id)->value('status'))->toBe('done')
            ->and(DB::table('job_orders')->where('id', $order->id)->value('closed_at'))->toBe('2026-11-13 08:00:00');
    });

    test('the list offers the buttons only on an open order', function () {
        $open = JobOrder::factory()->create();
        $closed = JobOrder::factory()->cancelled()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.job-search.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobOrders.data', fn ($orders) => collect($orders)->firstWhere('id', $open->id)['can_edit'] === true
                    && collect($orders)->firstWhere('id', $closed->id)['can_edit'] === false));
    });
});
