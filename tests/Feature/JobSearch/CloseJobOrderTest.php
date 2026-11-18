<?php

use App\Enums\ClientServiceStatus;
use App\Enums\JobOrderStatus;
use App\Enums\WorkerCaseStatus as S;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Closing a job order: deleted people's open applications are cancelled, everyone else's are resolved by the admin first, and
 * all of it happens in one transaction.
 */
beforeEach(function () {
    app()->setLocale('en');
    Service::factory()->jobSearch()->create();
    $this->admin = User::factory()->create();
    $this->order = JobOrder::factory()->create();
});

function openApplication(JobOrder $order, S $status = S::Applied, bool $deleted = false): JobOrderWorker
{
    $application = JobOrderWorker::factory()->create(['job_order_id' => $order->id, 'case_status' => $status]);

    if ($deleted) {
        $application->client->delete();
    }

    return $application;
}

/**
 * The screen's view of the order, as the close dialog sends it back.
 *
 * @return array<string, mixed>
 */
function screenOf(JobOrder $order): array
{
    $rows = $order->workers()->open()->with('client')->orderBy('id')->get();

    return [
        'open' => $rows->reject(fn ($w) => $w->client->trashed())->map(fn ($w) => ['worker_id' => $w->id, 'status' => $w->case_status->value])->values()->all(),
        'deleted_open' => $rows->filter(fn ($w) => $w->client->trashed())->modelKeys(),
    ];
}

/**
 * @param  array<int, array{0: JobOrderWorker, 1: S}>  $resolutions
 * @return array<string, mixed>
 */
function closeInput(JobOrder $order, string $status = 'done', array $resolutions = [], bool $confirmHired = false, ?array $screen = null): array
{
    return [
        'status' => $status,
        'resolutions' => array_map(fn ($pair) => ['worker_id' => $pair[0]->id, 'status' => $pair[1]->value], $resolutions),
        'confirm_hired' => $confirmHired,
        'expected' => $screen ?? screenOf($order),
    ];
}

function closeOrder(mixed $test, array $input, ?JobOrder $order = null): TestResponse
{
    $order ??= $test->order;

    return $test->actingAs($test->admin)->post(route('admin.job-search.close', $order), $input);
}

function closeToast(): ?string
{
    return session('inertia.flash_data.toast.message') ?? session('toast.message');
}

test('with nothing open the order closes directly as Done or Cancelled', function (string $status, JobOrderStatus $expected) {
    $this->travelTo('2026-11-17 10:00:00');
    openApplication($this->order, S::Hired);
    openApplication($this->order, S::Rejected);
    openApplication($this->order, S::Cancelled);

    closeOrder($this, closeInput($this->order, $status))->assertSessionHasNoErrors();

    expect($this->order->fresh()->status)->toBe($expected)
        ->and($this->order->fresh()->closed_at->toDateTimeString())->toBe('2026-11-17 10:00:00')
        // Settled applications are not touched.
        ->and($this->order->workers()->orderBy('id')->pluck('case_status')->all())->toBe([S::Hired, S::Rejected, S::Cancelled]);
})->with([
    'done' => ['done', JobOrderStatus::Done],
    'cancelled' => ['cancelled', JobOrderStatus::Cancelled],
]);

test('open applications of people who are not deleted are resolved by the admin, each its own way', function (string $status) {
    $hired = openApplication($this->order, S::Interview);
    $rejected = openApplication($this->order);
    $cancelled = openApplication($this->order);

    closeOrder($this, closeInput($this->order, $status, [
        [$hired, S::Hired], [$rejected, S::Rejected], [$cancelled, S::Cancelled],
    ], confirmHired: true))->assertSessionHasNoErrors();

    expect($this->order->fresh()->isClosed())->toBeTrue()
        ->and($hired->fresh()->case_status)->toBe(S::Hired)
        ->and($rejected->fresh()->case_status)->toBe(S::Rejected)
        ->and($cancelled->fresh()->case_status)->toBe(S::Cancelled)
        // Hired completes its Job Search record; the others stay In Progress, with no refund.
        ->and($hired->clientService->fresh()->status)->toBe(ClientServiceStatus::Complete)
        ->and($rejected->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress)
        ->and($cancelled->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress)
        ->and($cancelled->clientService->fresh()->cancel_refund_amount)->toBe('0.00');
})->with(['done', 'cancelled']);

test('the order cannot close while an open application is left unresolved, and nothing changes', function () {
    $resolved = openApplication($this->order);
    $left = openApplication($this->order, S::Interview);

    closeOrder($this, closeInput($this->order, 'done', [[$resolved, S::Rejected]]));

    expect(closeToast())->toBe('Every open application must be resolved before the job order can close.')
        ->and($this->order->fresh()->isOpen())->toBeTrue()
        ->and($resolved->fresh()->case_status)->toBe(S::Applied)
        ->and($left->fresh()->case_status)->toBe(S::Interview);
});

test('a resolution can only be Hired, Rejected or Cancelled', function (string $status) {
    $application = openApplication($this->order);
    $input = closeInput($this->order, 'done', [[$application, S::Rejected]]);
    $input['resolutions'][0]['status'] = $status;

    closeOrder($this, $input)->assertSessionHasErrors('resolutions.0.status');

    expect($this->order->fresh()->isOpen())->toBeTrue()->and($application->fresh()->case_status)->toBe(S::Applied);
})->with(['applied', 'interview', 'working', 'nonsense']);

test('Hired in the resolutions needs the explicit confirmation', function () {
    $application = openApplication($this->order);

    closeOrder($this, closeInput($this->order, 'done', [[$application, S::Hired]]))->assertSessionHasErrors('confirm_hired');

    expect($this->order->fresh()->isOpen())->toBeTrue()
        ->and($application->fresh()->case_status)->toBe(S::Applied)
        ->and($application->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress);
});

test('the open applications of deleted people are cancelled by the close, and nothing else is touched', function () {
    $deletedApplied = openApplication($this->order, S::Applied, deleted: true);
    $deletedInterview = openApplication($this->order, S::Interview, deleted: true);
    $deletedHired = openApplication($this->order, S::Hired, deleted: true);
    $deletedRejected = openApplication($this->order, S::Rejected, deleted: true);
    Payment::factory()->create(['client_service_id' => $deletedApplied->client_service_id, 'amount' => '700.00']);
    $feeBefore = $deletedApplied->clientService->fee;

    // A deleted person leaves nothing for the admin to resolve: no modal, the order closes directly.
    closeOrder($this, closeInput($this->order))->assertSessionHasNoErrors();

    expect($this->order->fresh()->isClosed())->toBeTrue()
        ->and($deletedApplied->fresh()->case_status)->toBe(S::Cancelled)
        ->and($deletedInterview->fresh()->case_status)->toBe(S::Cancelled)
        ->and($deletedHired->fresh()->case_status)->toBe(S::Hired)
        ->and($deletedRejected->fresh()->case_status)->toBe(S::Rejected)
        // The deleted person's Job Search record and money are exactly as they were.
        ->and($deletedApplied->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress)
        ->and($deletedApplied->clientService->fresh()->fee)->toBe($feeBefore)
        ->and($deletedApplied->clientService->fresh()->kept())->toBe('700.00')
        ->and(Payment::count())->toBe(1)
        ->and($deletedApplied->clientService->remarks()->count())->toBe(0);
});

test('deleted people are cancelled and everyone else is resolved in the same close, whatever their order', function () {
    // A person who is not deleted comes first and a deleted one after, then another live one: positions must not matter.
    $liveFirst = openApplication($this->order, S::Interview);
    $deleted = openApplication($this->order, S::Applied, deleted: true);
    $live = openApplication($this->order);

    closeOrder($this, closeInput($this->order, 'done', [[$live, S::Hired], [$liveFirst, S::Rejected]], confirmHired: true))->assertSessionHasNoErrors();

    expect($liveFirst->fresh()->case_status)->toBe(S::Rejected)
        ->and($deleted->fresh()->case_status)->toBe(S::Cancelled)
        ->and($live->fresh()->case_status)->toBe(S::Hired)
        ->and($live->clientService->fresh()->status)->toBe(ClientServiceStatus::Complete)
        ->and($this->order->fresh()->isClosed())->toBeTrue();
});

test('it is all or nothing: a failing step undoes the automatic cancellation and every resolution', function () {
    $deleted = openApplication($this->order, S::Applied, deleted: true);
    $fine = openApplication($this->order);
    $broken = openApplication($this->order);
    // One Job Search record is no longer In Progress, so resolving its application is refused after the others were done.
    $broken->clientService->update(['status' => ClientServiceStatus::Complete]);

    closeOrder($this, closeInput($this->order, 'done', [[$fine, S::Rejected], [$broken, S::Rejected]]));

    expect(closeToast())->toContain('no longer in progress')
        ->and($this->order->fresh()->isOpen())->toBeTrue()
        ->and($this->order->fresh()->closed_at)->toBeNull()
        ->and($deleted->fresh()->case_status)->toBe(S::Applied)
        ->and($fine->fresh()->case_status)->toBe(S::Applied)
        ->and($broken->fresh()->case_status)->toBe(S::Applied);
});

test('a changed screen aborts the whole close and applies nothing stale', function (Closure $change, string $why) {
    $screenApplication = openApplication($this->order);
    $other = openApplication($this->order, S::Interview);
    $screen = screenOf($this->order);

    $change($this, $screenApplication, $other);

    closeOrder($this, closeInput($this->order, 'done', [[$screenApplication, S::Rejected], [$other, S::Rejected]], screen: $screen));

    expect(closeToast())->toBe('The data changed while you were working. Please review the current state and try again.', $why)
        ->and($this->order->fresh()->isOpen())->toBeTrue();
})->with([
    'a status changed' => [fn ($test, $application, $other) => $application->update(['case_status' => S::Interview]), 'status changed'],
    'an application was settled' => [fn ($test, $application, $other) => $other->update(['case_status' => S::Rejected]), 'settled'],
    'a new open application appeared' => [fn ($test) => openApplication($test->order), 'new application'],
    'a person was deleted since' => [fn ($test, $application) => $application->client->delete(), 'deleted'],
]);

test('after a changed screen the admin can review and submit again', function () {
    $application = openApplication($this->order);
    $stale = screenOf($this->order);
    $application->update(['case_status' => S::Interview]);

    closeOrder($this, closeInput($this->order, 'done', [[$application, S::Rejected]], screen: $stale));
    expect($this->order->fresh()->isOpen())->toBeTrue();

    closeOrder($this, closeInput($this->order, 'done', [[$application, S::Rejected]]))->assertSessionHasNoErrors();

    expect($this->order->fresh()->isClosed())->toBeTrue()->and($application->fresh()->case_status)->toBe(S::Rejected);
});

test('a close that sends no screen at all is refused while anything is open', function () {
    $application = openApplication($this->order);

    $this->actingAs($this->admin)->post(route('admin.job-search.close', $this->order), ['status' => 'done']);

    expect(closeToast())->toContain('The data changed')
        ->and($this->order->fresh()->isOpen())->toBeTrue()
        ->and($application->fresh()->case_status)->toBe(S::Applied);
});

test('the preview lists the open applications of people who are not deleted and the ids of the deleted ones', function () {
    $live = openApplication($this->order, S::Interview);
    $deleted = openApplication($this->order, S::Applied, deleted: true);
    openApplication($this->order, S::Hired);
    openApplication(JobOrder::factory()->create());

    $response = $this->actingAs($this->admin)->getJson(route('admin.job-search.close-preview', $this->order))->assertOk();

    expect($response->json('open'))->toBe([[
        'worker_id' => $live->id,
        'name' => $live->client->name,
        'phone' => $live->client->phone,
        'status' => 'interview',
    ]])->and($response->json('deleted_open'))->toBe([$deleted->id]);
});

test('the preview is for the superadmin and an open order only', function () {
    $this->getJson(route('admin.job-search.close-preview', $this->order))->assertUnauthorized();

    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);
    $this->actingAs($user->fresh())->getJson(route('admin.job-search.close-preview', $this->order))->assertForbidden();

    $closed = JobOrder::factory()->done()->create();
    $this->actingAs($this->admin)->getJson(route('admin.job-search.close-preview', $closed))->assertForbidden();
});

test('a closed order is frozen: it cannot be closed again, and its applications stay as they were', function () {
    $application = openApplication($this->order);
    closeOrder($this, closeInput($this->order, 'done', [[$application, S::Rejected]]));

    closeOrder($this, closeInput($this->order, 'cancelled'))->assertForbidden();

    expect($this->order->fresh()->status)->toBe(JobOrderStatus::Done)
        ->and($application->fresh()->case_status)->toBe(S::Rejected);
});

test('an edit that passed the policy just before another admin closed the order is not saved', function () {
    $ran = false;
    $name = $this->order->company_name;

    JobOrder::retrieved(function (JobOrder $loaded) use (&$ran) {
        if (! $ran) {
            $ran = true;
            DB::table('job_orders')->where('id', $loaded->id)->update(['status' => 'done', 'closed_at' => now()]);
        }
    });

    $this->actingAs($this->admin)
        ->put(route('admin.job-search.update', $this->order), [
            'company_name' => 'Changed Co', 'manpower' => 3, 'male_count' => 1, 'female_count' => 1,
        ])->assertRedirect(route('admin.job-search.index'));

    expect(closeToast())->toBe('This job order is already closed.')
        ->and($this->order->fresh()->company_name)->toBe($name)
        ->and(Client::count())->toBe(0)->and(ClientService::count())->toBe(0);
});

test('the close is not retried as a data error: a stale screen fails at once, with the friendly message', function () {
    $application = openApplication($this->order);
    $screen = screenOf($this->order);
    $application->update(['case_status' => S::Interview]);

    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'from "job_orders"') && str_contains($query->sql, 'limit 1')) {
            $queries++;
        }
    });

    closeOrder($this, closeInput($this->order, 'done', [[$application, S::Rejected]], screen: $screen));

    // Route binding reads the order once, the close reads it once: a retry would read it again.
    expect($queries)->toBeLessThanOrEqual(3)->and(closeToast())->toContain('The data changed');
});

test('the new messages have Burmese translations', function (string $message) {
    app()->setLocale('my');

    expect(__($message))->not->toBe($message);

    app()->setLocale('en');
})->with([
    'changed' => ['The data changed while you were working. Please review the current state and try again.'],
    'unresolved' => ['Every open application must be resolved before the job order can close.'],
    'resolution' => ['An open application can only be resolved as Hired, Rejected or Cancelled.'],
]);
