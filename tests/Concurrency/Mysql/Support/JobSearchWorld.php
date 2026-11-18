<?php

namespace Tests\Concurrency\Mysql\Support;

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

/**
 * The committed rows one MySQL concurrency test needs (other processes must see them, so nothing is wrapped in a rolled-back
 * transaction), and the clean-up of exactly those rows afterwards. Nothing else in the testing database is touched, and no
 * table is truncated: everything is deleted by the ids this object created.
 */
final class JobSearchWorld
{
    /** @var array<int, int> */
    private array $orders = [];

    /** @var array<int, int> */
    private array $clients = [];

    /** @var array<int, int> */
    private array $users = [];

    private ?int $createdService = null;

    public function jobSearch(): Service
    {
        $existing = Service::query()->where('key', Service::JOB_SEARCH_KEY)->first();

        if ($existing !== null) {
            return $existing;
        }

        $service = Service::factory()->jobSearch()->create();
        $this->createdService = $service->id;

        return $service;
    }

    public function order(bool $closed = false): JobOrder
    {
        $order = $closed ? JobOrder::factory()->done()->create() : JobOrder::factory()->create();
        $this->orders[] = $order->id;

        return $order;
    }

    public function user(): User
    {
        $user = User::factory()->create();
        $this->users[] = $user->id;

        return $user;
    }

    public function person(): Client
    {
        $client = Client::factory()->create();
        $this->clients[] = $client->id;

        return $client;
    }

    /**
     * An application of the person on the order with the status given. Hired makes a Complete record, anything else an In
     * Progress one, as the real workflows do. The fee is paid in full (a payment), so there is Kept Amount to attribute.
     */
    public function application(JobOrder $order, Client $client, WorkerCaseStatus $status, string $fee = '15000.00', ?ClientService $record = null): JobOrderWorker
    {
        $this->jobSearch();

        $record ??= ClientService::factory()->forService($this->jobSearch())->create([
            'client_id' => $client->id,
            'status' => $status === WorkerCaseStatus::Hired ? ClientServiceStatus::Complete : ClientServiceStatus::InProgress,
            'fee' => $fee,
        ]);

        if ($record->payments()->doesntExist()) {
            Payment::factory()->create(['client_service_id' => $record->id, 'amount' => $fee]);
        }

        return JobOrderWorker::factory()->create([
            'job_order_id' => $order->id,
            'client_id' => $client->id,
            'client_service_id' => $record->id,
            'case_status' => $status,
        ]);
    }

    /**
     * How many In Progress Job Search records the person has.
     */
    public function inProgressRecords(Client $client): int
    {
        return ClientService::query()
            ->where('client_id', $client->id)
            ->where('service_id', $this->jobSearch()->id)
            ->where('status', ClientServiceStatus::InProgress->value)
            ->count();
    }

    /**
     * Delete what this test made, children first, by id only.
     */
    public function cleanUp(): void
    {
        $records = DB::table('client_services')->whereIn('client_id', $this->clients)->pluck('id')->all();

        DB::table('job_order_workers')
            ->where(fn ($query) => $query->whereIn('job_order_id', $this->orders)->orWhereIn('client_id', $this->clients))
            ->delete();
        DB::table('documents')->whereIn('client_service_id', $records)->delete();
        DB::table('payments')->whereIn('client_service_id', $records)->delete();
        DB::table('client_service_remarks')->whereIn('client_service_id', $records)->delete();
        DB::table('client_services')->whereIn('id', $records)->delete();
        DB::table('clients')->whereIn('id', $this->clients)->delete();
        DB::table('users')->whereIn('id', $this->users)->delete();
        DB::table('job_orders')->whereIn('id', $this->orders)->delete();

        if ($this->createdService !== null) {
            DB::table('services')->where('id', $this->createdService)->delete();
        }

        $this->orders = $this->clients = $this->users = [];
        $this->createdService = null;
    }
}
