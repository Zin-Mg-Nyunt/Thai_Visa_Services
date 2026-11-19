<?php

namespace Tests\Support;

use App\Enums\ClientServiceStatus;
use App\Enums\WorkerCaseStatus;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * A seeded, synthetic Job Search data set for the Job Order Money equivalence tests and the MySQL benchmark. The same seed always
 * builds the same rows, so a failure can be reproduced.
 *
 * It deliberately mixes every state the money rules care about, including states the normal workflows cannot produce:
 * - engagements In Progress, Complete and whole-service Cancelled, with fee-change refunds and cancel refunds;
 * - several applications per engagement, on the same order and on different orders, in every case status;
 * - engagements with more than one qualifying application (an anomaly: only the newest may own the money);
 * - people who were soft-deleted, and engagements with no payment;
 * - other services with payments of their own, which must never leak into Job Search money.
 *
 * Rows are written with bulk inserts (no model events), so a large data set is quick to build. Nothing here is a workflow.
 */
final class JobOrderMoneyWorld
{
    /** @var array<int, int> */
    public array $orderIds = [];

    /** @var array<int, int> */
    public array $jobSearchRecordIds = [];

    /** @var array<int, int> */
    public array $otherRecordIds = [];

    /** @var array<int, int> */
    public array $clientIds = [];

    /** @var array<int, int> services this world created (none when they already existed) */
    private array $serviceIds = [];

    public function __construct(private int $seed) {}

    /**
     * @param  int  $people  how many people get a Job Search engagement
     * @param  int  $orders  how many job orders their applications are spread over
     */
    public function build(int $people, int $orders, int $paymentsPerRecord = 2, int $otherServicePeople = 0): self
    {
        mt_srand($this->seed);

        fake()->seed($this->seed);

        $jobSearch = Service::query()->where('key', Service::JOB_SEARCH_KEY)->first();

        if ($jobSearch === null) {
            $jobSearch = Service::factory()->jobSearch()->create();
            $this->serviceIds[] = $jobSearch->id;
        }

        $other = Service::factory()->create();
        $this->serviceIds[] = $other->id;
        $now = now()->toDateTimeString();
        $today = now()->toDateString();

        $this->orderIds = $this->insertOrders($orders, $now);

        $clientIds = $this->clientIds = $this->insertClients($people + $otherServicePeople, $now);

        $records = [];
        $workers = [];
        $payments = [];

        foreach (array_slice($clientIds, 0, $people) as $clientId) {
            $status = $this->pick([ClientServiceStatus::InProgress, ClientServiceStatus::InProgress, ClientServiceStatus::Complete, ClientServiceStatus::Cancel]);
            $records[] = [
                'client_id' => $clientId,
                'service_id' => $jobSearch->id,
                'status' => $status->value,
                'fee' => '20000.00',
                'refund_amount' => $this->pick(['0.00', '0.00', '0.00', '500.00', '1234.56']),
                'cancel_refund_amount' => $status === ClientServiceStatus::Cancel ? $this->pick(['0.00', '1000.00', '333.33']) : '0.00',
                'cancelled_at' => $status === ClientServiceStatus::Cancel ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_slice($clientIds, $people) as $clientId) {
            $records[] = [
                'client_id' => $clientId, 'service_id' => $other->id, 'status' => ClientServiceStatus::InProgress->value,
                'fee' => '9000.00', 'refund_amount' => '0.00', 'cancel_refund_amount' => '0.00', 'cancelled_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('client_services')->insert($chunk);
        }

        $all = DB::table('client_services')->whereIn('client_id', $clientIds)->orderBy('id')->get(['id', 'client_id', 'service_id', 'status']);

        foreach ($all as $record) {
            if ((int) $record->service_id === $other->id) {
                $this->otherRecordIds[] = (int) $record->id;
            } else {
                $this->jobSearchRecordIds[] = (int) $record->id;

                foreach ($this->applications($record->status) as $caseStatus) {
                    $workers[] = [
                        'job_order_id' => $this->pick($this->orderIds),
                        'client_id' => $record->client_id,
                        'client_service_id' => $record->id,
                        'case_status' => $caseStatus->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            $count = mt_rand(0, $paymentsPerRecord);

            for ($i = 0; $i < $count; $i++) {
                $payments[] = [
                    'client_service_id' => $record->id,
                    'amount' => sprintf('%d.%02d', mt_rand(1, 9000), mt_rand(0, 99)),
                    'paid_at' => $today,
                    'method' => 'cash',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Applications are inserted in a shuffled order, so the newest application of a record can be on any order.
        $this->shuffle($workers);

        foreach (array_chunk($workers, 500) as $chunk) {
            DB::table('job_order_workers')->insert($chunk);
        }

        foreach (array_chunk($payments, 500) as $chunk) {
            DB::table('payments')->insert($chunk);
        }

        // About one person in eight is soft-deleted afterwards: their history stays, as Person deletion leaves it.
        $deleted = array_values(array_filter($clientIds, fn () => mt_rand(1, 8) === 1));

        foreach (array_chunk($deleted, 500) as $chunk) {
            DB::table('clients')->whereIn('id', $chunk)->update(['deleted_at' => $now]);
        }

        return $this;
    }

    /**
     * The applications of one engagement, mostly what the workflows produce and sometimes an anomaly.
     *
     * @return array<int, WorkerCaseStatus>
     */
    private function applications(string $status): array
    {
        $history = [];

        for ($i = mt_rand(0, 2); $i > 0; $i--) {
            $history[] = $this->pick([WorkerCaseStatus::Rejected, WorkerCaseStatus::Cancelled]);
        }

        $current = match ($status) {
            ClientServiceStatus::InProgress->value => $this->pick([WorkerCaseStatus::Applied, WorkerCaseStatus::Interview, WorkerCaseStatus::Rejected, null]),
            ClientServiceStatus::Complete->value => WorkerCaseStatus::Hired,
            default => WorkerCaseStatus::Cancelled,
        };

        if ($current !== null) {
            $history[] = $current;
        }

        // Anomalies (one engagement in ten): a second qualifying application, or a status that does not match the engagement.
        if (mt_rand(1, 10) === 1) {
            $history[] = $this->pick([WorkerCaseStatus::Applied, WorkerCaseStatus::Interview, WorkerCaseStatus::Hired]);
        }

        return $history;
    }

    /**
     * Delete exactly the rows this world created, children first, and nothing else (no truncate). For tests that commit their
     * data, such as the MySQL benchmark.
     */
    public function cleanUp(): void
    {
        $records = [...$this->jobSearchRecordIds, ...$this->otherRecordIds];

        foreach (array_chunk($records, 1000) as $chunk) {
            DB::table('job_order_workers')->whereIn('client_service_id', $chunk)->delete();
            DB::table('payments')->whereIn('client_service_id', $chunk)->delete();
            DB::table('client_services')->whereIn('id', $chunk)->delete();
        }

        foreach (array_chunk($this->orderIds, 1000) as $chunk) {
            DB::table('job_order_workers')->whereIn('job_order_id', $chunk)->delete();
            DB::table('job_orders')->whereIn('id', $chunk)->delete();
        }

        foreach (array_chunk($this->clientIds, 1000) as $chunk) {
            DB::table('clients')->whereIn('id', $chunk)->delete();
        }

        DB::table('services')->whereIn('id', $this->serviceIds)->delete();
    }

    /**
     * Open and closed job orders with realistic company names (Faker is seeded by build()).
     *
     * @return array<int, int>
     */
    private function insertOrders(int $count, string $now): array
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $closed = mt_rand(1, 3) === 1;
            $rows[] = [
                'company_name' => fake()->company(),
                'manpower' => 10, 'male_count' => 5, 'female_count' => 5,
                'status' => $closed ? $this->pick(['done', 'cancelled']) : 'open',
                'closed_at' => $closed ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $first = (int) (DB::table('job_orders')->max('id') ?? 0);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('job_orders')->insert($chunk);
        }

        return DB::table('job_orders')->where('id', '>', $first)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array<int, int>
     */
    private function insertClients(int $count, string $now): array
    {
        $rows = [];
        $base = mt_rand(10_000_000, 99_999_999);

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'name' => 'Synthetic worker '.$i,
                'phone' => '+669'.str_pad((string) (($base + $i) % 100_000_000), 8, '0', STR_PAD_LEFT),
                'birthday' => '1990-01-01',
                'gender' => $this->pick(['male', 'female']),
                'unique_token' => bin2hex(random_bytes(24)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $first = (int) (DB::table('clients')->max('id') ?? 0);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('clients')->insert($chunk);
        }

        return DB::table('clients')->where('id', '>', $first)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    /**
     * A seeded Fisher-Yates shuffle (PHP's shuffle() does not follow mt_srand on every version).
     *
     * @param  array<int, mixed>  $items
     */
    private function shuffle(array &$items): void
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
    }

    /**
     * The id of a record that is not a Job Search one, or null.
     */
    public function anyOtherRecord(): ?int
    {
        return $this->otherRecordIds[0] ?? null;
    }

    /**
     * Records of this world, for ordersOf() calls.
     *
     * @return array<int, int>
     */
    public function recordSample(int $size): array
    {
        return array_slice($this->jobSearchRecordIds, 0, $size);
    }
}
