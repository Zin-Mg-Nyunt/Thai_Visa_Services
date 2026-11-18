<?php

use App\Actions\JobSearch\JobOrderMoney;
use App\Enums\ClientServiceStatus;
use App\Enums\WorkerCaseStatus as S;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The Job Order detail page (business rules, Section 11): four cards (Manpower Requested, Applied Workers, Hired Workers, Money),
 * a manpower warning that compares the Hired Workers with the manpower, worker names that open Client Detail, and the historical
 * cancelled applications shown apart from the operational counts.
 */
beforeEach(function () {
    app()->setLocale('en');
    $this->admin = User::factory()->create();
    $this->jobSearch = Service::factory()->jobSearch()->create();
});

/**
 * @param  array<string, mixed>  $record
 */
function rowFor(JobOrder $order, S $status, array $record = [], ?Client $client = null): JobOrderWorker
{
    $client ??= Client::factory()->create();
    $service = ClientService::factory()->forService(Service::query()->where('key', Service::JOB_SEARCH_KEY)->first())->create([
        'client_id' => $client->id, 'fee' => '15000.00',
        'status' => $status === S::Hired ? ClientServiceStatus::Complete : ClientServiceStatus::InProgress,
        ...$record,
    ]);

    return JobOrderWorker::factory()->create([
        'job_order_id' => $order->id, 'client_id' => $client->id, 'client_service_id' => $service->id, 'case_status' => $status,
    ]);
}

function detailPage(object $test, JobOrder $order): TestResponse
{
    return $test->actingAs($test->admin)->get(route('admin.job-search.show', $order));
}

test('the four cards count Applied, Hired and cancelled applications apart, leaving out deleted people', function () {
    $order = JobOrder::factory()->create(['manpower' => 10]);

    rowFor($order, S::Applied);
    rowFor($order, S::Applied);
    rowFor($order, S::Interview);
    rowFor($order, S::Hired);
    rowFor($order, S::Hired);
    rowFor($order, S::Rejected);
    rowFor($order, S::Cancelled);
    rowFor($order, S::Cancelled);
    // A deleted person is in no count and no list.
    rowFor($order, S::Applied)->client->delete();
    rowFor($order, S::Hired)->client->delete();
    rowFor($order, S::Cancelled)->client->delete();
    // An application on a whole-service Cancelled service is not an Applied Worker.
    rowFor($order, S::Applied, ['status' => ClientServiceStatus::Cancel, 'cancelled_at' => now()]);

    detailPage($this, $order)->assertInertia(fn (Assert $page) => $page
        ->where('jobOrder.data.manpower', 10)
        ->where('jobOrder.data.applied_workers_count', 3)
        ->where('jobOrder.data.hired_workers_count', 2)
        ->where('jobOrder.data.cancelled_workers_count', 2)
        ->where('money.amount', '0.00'));
});

test('Money is the live Kept Amount attributed to this order, with the deleted-people hint, and never the Unassigned amount', function () {
    $order = JobOrder::factory()->create();
    $hired = rowFor($order, S::Hired);
    Payment::factory()->create(['client_service_id' => $hired->client_service_id, 'amount' => '15000.00']);
    $unassigned = rowFor($order, S::Rejected);
    Payment::factory()->create(['client_service_id' => $unassigned->client_service_id, 'amount' => '4000.00']);

    detailPage($this, $order)->assertInertia(fn (Assert $page) => $page->where('money.amount', '15000.00')->where('money.includes_deleted', false));

    $hired->client->delete();

    detailPage($this, $order)->assertInertia(fn (Assert $page) => $page->where('money.amount', '15000.00')->where('money.includes_deleted', true));
    expect(app(JobOrderMoney::class)->totals()['unassigned'])->toBe('4000.00');
});

test('the manpower warning compares Hired Workers with Manpower Requested: 9 of 10 and 10 of 10 are quiet, 11 of 10 warns', function (int $hired, bool $warns) {
    $order = JobOrder::factory()->create(['manpower' => 10]);

    foreach (range(1, $hired) as $ignored) {
        rowFor($order, S::Hired);
    }

    // Plenty of applied, interview, rejected and cancelled rows never count as capacity.
    foreach (range(1, 12) as $ignored) {
        rowFor($order, S::Applied);
        rowFor($order, S::Interview);
    }
    rowFor($order, S::Rejected);
    rowFor($order, S::Cancelled);

    detailPage($this, $order)->assertInertia(fn (Assert $page) => $page
        ->where('jobOrder.data.hired_workers_count', $hired)
        ->where('jobOrder.data.exceeds_manpower', $warns));
})->with([
    'below capacity' => [9, false],
    'capacity reached' => [10, false],
    'over capacity' => [11, true],
]);

test('the warning also shows on a closed order and never blocks anything', function () {
    $order = JobOrder::factory()->done()->create(['manpower' => 1]);
    rowFor($order, S::Hired);
    rowFor($order, S::Hired);

    detailPage($this, $order)->assertOk()->assertInertia(fn (Assert $page) => $page->where('jobOrder.data.exceeds_manpower', true));
});

test('cancelling a Hired application lowers the Hired Workers and the Money, on an open and on a closed order, and keeps the row as history', function (string $state) {
    $order = ($state === 'open' ? JobOrder::factory() : JobOrder::factory()->{$state}())->create(['manpower' => 1]);
    $hired = rowFor($order, S::Hired);
    Payment::factory()->create(['client_service_id' => $hired->client_service_id, 'amount' => '15000.00']);
    rowFor($order, S::Hired);

    detailPage($this, $order)->assertInertia(fn (Assert $page) => $page
        ->where('jobOrder.data.hired_workers_count', 2)
        ->where('jobOrder.data.exceeds_manpower', true)
        ->where('money.amount', '15000.00'));

    $this->actingAs($this->admin)
        ->patch(route('admin.job-search.workers.cancel-hired', [$order, $hired]), ['confirm_cancel_hired' => true])
        ->assertSessionHasNoErrors();

    detailPage($this, $order)->assertInertia(fn (Assert $page) => $page
        ->where('jobOrder.data.hired_workers_count', 1)
        ->where('jobOrder.data.cancelled_workers_count', 1)
        ->where('jobOrder.data.exceeds_manpower', false)
        ->where('money.amount', '0.00')
        ->has('workers.data', 1)
        ->has('cancelledWorkers.data', 1)
        ->where('cancelledWorkers.data.0.id', $hired->id));

    expect(JobOrderWorker::find($hired->id)->case_status)->toBe(S::Cancelled);
})->with([
    'open' => ['open'],
    'closed' => ['done'],
]);

test('the same person after a cancelled application: one history row and one current row, neither inflating the counts', function () {
    $order = JobOrder::factory()->create();
    $client = Client::factory()->create();
    $old = rowFor($order, S::Cancelled, client: $client);
    $new = rowFor($order, S::Applied, client: $client);

    detailPage($this, $order)->assertInertia(fn (Assert $page) => $page
        ->where('jobOrder.data.applied_workers_count', 1)
        ->where('jobOrder.data.cancelled_workers_count', 1)
        ->where('workers.data', fn ($list) => collect($list)->pluck('id')->all() === [$new->id])
        ->where('cancelledWorkers.data', fn ($list) => collect($list)->pluck('id')->all() === [$old->id]));
});

test('the operational list holds every application that is not Cancelled, history holds only Cancelled, and a deleted person is in neither', function () {
    $order = JobOrder::factory()->create();
    $rows = collect([S::Applied, S::Interview, S::Hired, S::Rejected, S::Cancelled])->mapWithKeys(fn (S $status) => [$status->value => rowFor($order, $status)]);
    rowFor($order, S::Applied)->client->delete();
    rowFor($order, S::Cancelled)->client->delete();

    detailPage($this, $order)->assertInertia(fn (Assert $page) => $page
        ->where('workers.data', fn ($list) => collect($list)->pluck('id')->sort()->values()->all()
            === $rows->only(['applied', 'interview', 'hired', 'rejected'])->pluck('id')->sort()->values()->all())
        ->where('cancelledWorkers.data', fn ($list) => collect($list)->pluck('id')->all() === [$rows['cancelled']->id]));
});

test('every worker sent to the page has the person id that its name link opens, and Client Detail opens for it', function () {
    $order = JobOrder::factory()->create();
    $active = rowFor($order, S::Applied);
    $cancelled = rowFor($order, S::Cancelled);

    detailPage($this, $order)->assertInertia(fn (Assert $page) => $page
        ->where('workers.data.0.client_id', $active->client_id)
        ->where('cancelledWorkers.data.0.client_id', $cancelled->client_id));

    $this->actingAs($this->admin)->get(route('admin.clients.show', $active->client_id))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.clients.show', $cancelled->client_id))->assertOk();
});

test('the page has exactly four cards, links worker names to Client Detail, and shows cancelled applications apart', function () {
    $page = file_get_contents(resource_path('js/pages/admin/job-search/Show.vue'));
    $workers = file_get_contents(resource_path('js/components/JobOrderWorkers.vue'));
    $history = file_get_contents(resource_path('js/components/CancelledApplications.vue'));

    expect(substr_count($page, '<StatCard'))->toBe(4)
        ->and($page)->toContain('manpower-card')->toContain('applied-workers-card')->toContain('hired-workers-card')->toContain('money-card')
        ->not->toContain('active-workers-card')->not->toContain('cancelled-workers-card')
        // The warning reads the Hired count, not the active count.
        ->toContain('hired_workers_count')->not->toContain('active_workers_count')
        ->and($page)->toContain('<CancelledApplications')
        ->and($workers)->toContain('showClient(worker.client_id)')
        ->and($history)->toContain('showClient(worker.client_id)');
});

test('the Job Orders list shows the same counts and the same warning rule', function () {
    $order = JobOrder::factory()->create(['manpower' => 1]);
    rowFor($order, S::Hired);
    rowFor($order, S::Hired);
    rowFor($order, S::Applied);
    rowFor($order, S::Cancelled);

    $this->actingAs($this->admin)->get(route('admin.job-search.index'))->assertInertia(fn (Assert $page) => $page
        ->where('jobOrders.data.0.hired_workers_count', 2)
        ->where('jobOrders.data.0.applied_workers_count', 1)
        ->where('jobOrders.data.0.cancelled_workers_count', 1)
        ->where('jobOrders.data.0.exceeds_manpower', true));
});
