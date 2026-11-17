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
use Illuminate\Testing\TestResponse;

/**
 * Changing the status of applications on an open job order, one at a time or in bulk, all or nothing.
 */
beforeEach(function () {
    app()->setLocale('en');
    Service::factory()->jobSearch()->create();
    $this->admin = User::factory()->create();
    $this->order = JobOrder::factory()->create();
});

function application(JobOrder $order, WorkerCaseStatus $status = WorkerCaseStatus::Applied): JobOrderWorker
{
    return JobOrderWorker::factory()->create(['job_order_id' => $order->id, 'case_status' => $status]);
}

/**
 * @param  array<int, array{0: JobOrderWorker, 1: WorkerCaseStatus}>  $pairs
 * @return array<string, mixed>
 */
function statusInput(array $pairs, bool $confirmHired = false): array
{
    return [
        'changes' => array_map(fn (array $pair) => ['worker_id' => $pair[0]->id, 'status' => $pair[1]->value], $pairs),
        'confirm_hired' => $confirmHired,
    ];
}

function changeStatuses(mixed $test, array $pairs, bool $confirmHired = false, ?JobOrder $order = null): TestResponse
{
    return $test->actingAs($test->admin)->patch(
        route('admin.job-search.workers.status', $order ?? $test->order),
        statusInput($pairs, $confirmHired),
    );
}

function statusToast(): ?string
{
    return session('inertia.flash_data.toast.message') ?? session('toast.message');
}

test('the transition table: Applied and Interview move freely, the other three are final', function () {
    expect(WorkerCaseStatus::Applied->allowedNext())->toBe([WorkerCaseStatus::Interview, WorkerCaseStatus::Hired, WorkerCaseStatus::Rejected, WorkerCaseStatus::Cancelled])
        ->and(WorkerCaseStatus::Interview->allowedNext())->toBe([WorkerCaseStatus::Applied, WorkerCaseStatus::Hired, WorkerCaseStatus::Rejected, WorkerCaseStatus::Cancelled])
        ->and(WorkerCaseStatus::Hired->isFinal())->toBeTrue()
        ->and(WorkerCaseStatus::Rejected->isFinal())->toBeTrue()
        ->and(WorkerCaseStatus::Cancelled->isFinal())->toBeTrue()
        ->and(WorkerCaseStatus::Applied->isFinal())->toBeFalse();
});

test('every allowed transition works, and only Hired completes the Job Search record', function (WorkerCaseStatus $from, WorkerCaseStatus $to) {
    $application = application($this->order, $from);

    changeStatuses($this, [[$application, $to]], confirmHired: true)->assertSessionHasNoErrors();

    expect($application->fresh()->case_status)->toBe($to)
        ->and(statusToast())->toBe('Status updated.')
        ->and($application->clientService->fresh()->status)->toBe(
            $to === WorkerCaseStatus::Hired ? ClientServiceStatus::Complete : ClientServiceStatus::InProgress,
        );
})->with(function () {
    foreach ([WorkerCaseStatus::Applied, WorkerCaseStatus::Interview] as $from) {
        foreach ($from->allowedNext() as $to) {
            yield "{$from->value} → {$to->value}" => [$from, $to];
        }
    }
});

test('every other transition is refused and changes nothing', function (WorkerCaseStatus $from, WorkerCaseStatus $to) {
    $application = application($this->order, $from);

    changeStatuses($this, [[$application, $to]], confirmHired: true)->assertSessionHasNoErrors();

    expect(statusToast())->toContain('cannot change from')
        ->and($application->fresh()->case_status)->toBe($from)
        ->and($application->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress);
})->with(function () {
    foreach (WorkerCaseStatus::cases() as $from) {
        foreach (WorkerCaseStatus::cases() as $to) {
            if (! $from->canChangeTo($to)) {
                yield "{$from->value} → {$to->value}" => [$from, $to];
            }
        }
    }
});

test('Hired needs the explicit confirmation, for one application and for a bulk update', function () {
    $one = application($this->order);
    $two = application($this->order);

    changeStatuses($this, [[$one, WorkerCaseStatus::Hired]])->assertSessionHasErrors('confirm_hired');
    changeStatuses($this, [[$one, WorkerCaseStatus::Interview], [$two, WorkerCaseStatus::Hired]])->assertSessionHasErrors('confirm_hired');

    expect($one->fresh()->case_status)->toBe(WorkerCaseStatus::Applied)
        ->and($two->fresh()->case_status)->toBe(WorkerCaseStatus::Applied)
        ->and($two->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress);

    changeStatuses($this, [[$one, WorkerCaseStatus::Hired]], confirmHired: true)->assertSessionHasNoErrors();

    expect($one->fresh()->case_status)->toBe(WorkerCaseStatus::Hired);
});

test('Hired completes the record and leaves the money alone; Rejected and Cancelled do not complete it', function () {
    $hired = application($this->order);
    $rejected = application($this->order);
    $cancelled = application($this->order);
    Payment::factory()->create(['client_service_id' => $hired->client_service_id, 'amount' => '500.00']);
    $feeBefore = $hired->clientService->fee;

    changeStatuses($this, [
        [$hired, WorkerCaseStatus::Hired],
        [$rejected, WorkerCaseStatus::Rejected],
        [$cancelled, WorkerCaseStatus::Cancelled],
    ], confirmHired: true);

    expect($hired->clientService->fresh()->status)->toBe(ClientServiceStatus::Complete)
        ->and($rejected->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress)
        // An application-level Cancelled is not a whole-service cancel: no refund, still In Progress.
        ->and($cancelled->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress)
        ->and($cancelled->clientService->fresh()->cancel_refund_amount)->toBe('0.00')
        ->and($hired->clientService->fresh()->fee)->toBe($feeBefore)
        ->and(Payment::count())->toBe(1)
        ->and($hired->clientService->remarks()->count())->toBe(0);
});

test('a bulk update is all or nothing: one refused change leaves every application as it was', function () {
    $first = application($this->order);
    $second = application($this->order, WorkerCaseStatus::Interview);
    $final = application($this->order, WorkerCaseStatus::Rejected);

    changeStatuses($this, [
        [$first, WorkerCaseStatus::Hired],
        [$second, WorkerCaseStatus::Rejected],
        [$final, WorkerCaseStatus::Applied],
    ], confirmHired: true);

    expect(statusToast())->toContain('cannot change from')
        ->and($first->fresh()->case_status)->toBe(WorkerCaseStatus::Applied)
        ->and($first->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress)
        ->and($second->fresh()->case_status)->toBe(WorkerCaseStatus::Interview)
        ->and($final->fresh()->case_status)->toBe(WorkerCaseStatus::Rejected);
});

test('a bulk update gives each application its own status, and several Hired complete several records', function () {
    $one = application($this->order);
    $two = application($this->order, WorkerCaseStatus::Interview);
    $three = application($this->order);
    $four = application($this->order);

    changeStatuses($this, [
        [$one, WorkerCaseStatus::Hired],
        [$two, WorkerCaseStatus::Hired],
        [$three, WorkerCaseStatus::Rejected],
        [$four, WorkerCaseStatus::Interview],
    ], confirmHired: true)->assertSessionHasNoErrors();

    expect($one->fresh()->case_status)->toBe(WorkerCaseStatus::Hired)
        ->and($two->fresh()->case_status)->toBe(WorkerCaseStatus::Hired)
        ->and($three->fresh()->case_status)->toBe(WorkerCaseStatus::Rejected)
        ->and($four->fresh()->case_status)->toBe(WorkerCaseStatus::Interview)
        ->and($one->clientService->fresh()->status)->toBe(ClientServiceStatus::Complete)
        ->and($two->clientService->fresh()->status)->toBe(ClientServiceStatus::Complete)
        ->and($three->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress)
        ->and($four->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress);
});

test('a closed order is frozen: its application statuses cannot change', function (string $state) {
    $closed = JobOrder::factory()->{$state}()->create();
    $application = application($closed);

    changeStatuses($this, [[$application, WorkerCaseStatus::Rejected]], order: $closed)->assertForbidden();

    expect($application->fresh()->case_status)->toBe(WorkerCaseStatus::Applied);
})->with(['done', 'cancelled']);

test('an order closed a moment ago refuses the change and nothing is changed', function () {
    $application = application($this->order);
    $ran = false;

    // Seen as open by the policy; another admin closes it just before the action locks the row.
    JobOrder::retrieved(function (JobOrder $loaded) use (&$ran) {
        if (! $ran) {
            $ran = true;
            DB::table('job_orders')->where('id', $loaded->id)->update(['status' => 'done', 'closed_at' => now()]);
        }
    });

    changeStatuses($this, [[$application, WorkerCaseStatus::Rejected]])->assertRedirect();

    expect(statusToast())->toBe('This job order is already closed.')
        ->and($application->fresh()->case_status)->toBe(WorkerCaseStatus::Applied);
});

test('an application of another order cannot be changed, and takes the rest of the request down with it', function () {
    $mine = application($this->order);
    $foreign = application(JobOrder::factory()->create());

    changeStatuses($this, [[$mine, WorkerCaseStatus::Interview], [$foreign, WorkerCaseStatus::Rejected]]);

    expect(statusToast())->toBe('One of these applications is not on this job order.')
        ->and($mine->fresh()->case_status)->toBe(WorkerCaseStatus::Applied)
        ->and($foreign->fresh()->case_status)->toBe(WorkerCaseStatus::Applied);
});

test('the application of a deleted person cannot be changed', function () {
    $application = application($this->order);
    $application->client->delete();

    changeStatuses($this, [[$application, WorkerCaseStatus::Rejected]]);

    expect(statusToast())->toContain('was deleted')
        ->and($application->fresh()->case_status)->toBe(WorkerCaseStatus::Applied);
});

test('a Job Search record that is no longer In Progress keeps its applications as they are', function (ClientServiceStatus $status) {
    $application = application($this->order);
    $application->clientService->update(['status' => $status]);

    changeStatuses($this, [[$application, WorkerCaseStatus::Rejected]]);

    expect(statusToast())->toContain('no longer in progress')
        ->and($application->fresh()->case_status)->toBe(WorkerCaseStatus::Applied);
})->with([ClientServiceStatus::Complete, ClientServiceStatus::Cancel]);

test('two changes to the same application: the second one sees the first and is refused', function () {
    $application = application($this->order);

    changeStatuses($this, [[$application, WorkerCaseStatus::Rejected]]);
    expect(statusToast())->toBe('Status updated.');

    // Another admin's screen still showed Applied, and asks for Hired.
    changeStatuses($this, [[$application, WorkerCaseStatus::Hired]], confirmHired: true);

    expect(statusToast())->toContain('cannot change from Rejected to Hired')
        ->and($application->fresh()->case_status)->toBe(WorkerCaseStatus::Rejected)
        ->and($application->clientService->fresh()->status)->toBe(ClientServiceStatus::InProgress);
});

test('the request is checked: something to change, a real status, each application once', function () {
    $application = application($this->order);
    $url = route('admin.job-search.workers.status', $this->order);

    $this->actingAs($this->admin)->patch($url, ['changes' => []])->assertSessionHasErrors('changes');
    $this->actingAs($this->admin)->patch($url, ['changes' => [['worker_id' => $application->id, 'status' => 'working']]])->assertSessionHasErrors('changes.0.status');
    $this->actingAs($this->admin)->patch($url, ['changes' => [
        ['worker_id' => $application->id, 'status' => 'interview'],
        ['worker_id' => $application->id, 'status' => 'rejected'],
    ]])->assertSessionHasErrors('changes.0.worker_id');

    expect($application->fresh()->case_status)->toBe(WorkerCaseStatus::Applied);
});

test('guests are redirected and non-superadmins are refused', function () {
    $application = application($this->order);
    $url = route('admin.job-search.workers.status', $this->order);
    $input = statusInput([[$application, WorkerCaseStatus::Rejected]]);

    $this->patch($url, $input)->assertRedirect(route('login'));

    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);
    $this->actingAs($user->fresh())->patch($url, $input)->assertForbidden();

    expect($application->fresh()->case_status)->toBe(WorkerCaseStatus::Applied)
        ->and(Client::count())->toBe(1)->and(ClientService::count())->toBe(1);
});

test('the new messages and the five statuses have Burmese translations', function (string $message) {
    app()->setLocale('my');

    expect(__($message))->not->toBe($message);

    app()->setLocale('en');
})->with([
    'deleted' => [':name was deleted, so the application cannot change.'],
    'not in progress' => ['The Job Search record of :name is no longer in progress, so the application cannot change.'],
    'transition' => ['The application of :name cannot change from :from to :to.'],
    'not on order' => ['One of these applications is not on this job order.'],
    'updated' => ['Status updated.'],
    'confirm' => ['Confirm Hired first: the Job Search service becomes Complete, the person cannot be assigned to another job order, and Hired cannot be undone.'],
    'applied' => ['Applied'],
    'interview' => ['Interview'],
    'hired' => ['Hired'],
    'rejected' => ['Rejected'],
    'cancelled' => ['Cancelled'],
]);
