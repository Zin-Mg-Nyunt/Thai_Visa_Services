<?php

namespace App\Actions\JobSearch;

use App\Concerns\HandlesMoney;
use App\Enums\ClientServiceStatus;
use App\Enums\WorkerCaseStatus;
use App\Models\ClientService;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which Job Order, if any, the Kept Amount of each Job Search record is attributed to, and so what each order's Money is and
 * how much Kept Amount is Unassigned. Nothing here is stored: it is worked out on every request from the records, the
 * applications and the payments, so it is live. Closing an order freezes nothing, and a payment made after the close counts.
 *
 * Kept Amount is the real money kept by a record: payments received minus the fee-change refund and the cancel refund
 * (ClientService::kept(), added up in whole satang in the database like ServiceStatistics, which is exact on MySQL and SQLite).
 *
 * A record's Kept Amount belongs to the order of ONE application:
 * - the record is In Progress and the application is Applied or Interview, or
 * - the record is Complete and the application is Hired.
 * In every other case (every application Rejected or Cancelled, a whole-service Cancelled record) it belongs to no order: it
 * is Unassigned. It is still the record's own money and still part of every total; Unassigned is only a classification, never
 * a payment, refund or other record, and an amount is never both attributed and Unassigned. Should a record ever hold more
 * than one qualifying application (normal workflows cannot do that), only its newest one counts, so the money is never counted
 * twice. Deleted people are not filtered out: their history stays (Person deletion alone moves nothing).
 */
class JobOrderMoney
{
    use HandlesMoney;

    /**
     * The Money of one order, and whether part of it comes from people who were deleted.
     *
     * One statement, so the amount and the deleted-people flag always come from the same snapshot (a single SELECT reads one
     * consistent snapshot on InnoDB). Only the records that have an application on this order are aggregated: a record with no
     * application here can never be attributed here. For those records every application is still looked at, on any order, so
     * "the newest qualifying application owns the money" is decided exactly as before.
     *
     * @return array{amount: numeric-string, includes_deleted: bool}
     */
    public function forOrder(int $jobOrderId): array
    {
        $onThisOrder = DB::table('job_order_workers')->where('job_order_id', $jobOrderId)->select('client_service_id');

        $row = $this->attributed($onThisOrder)
            ->join('clients', 'clients.id', '=', 'a.client_id')
            ->where('a.job_order_id', $jobOrderId)
            ->selectRaw('SUM(CASE WHEN clients.deleted_at IS NOT NULL THEN 1 ELSE 0 END) as deleted_rows')
            ->first();

        return [
            'amount' => $this->fromSatang($row?->satang),
            'includes_deleted' => (int) ($row->deleted_rows ?? 0) > 0,
        ];
    }

    /**
     * The Kept Amount of every Job Search record that no order owns, and the Job Search total it comes from. By definition
     * the Money of all orders (open and closed) plus the Unassigned amount is the Job Search Kept Amount.
     *
     * @return array{kept: numeric-string, attributed: numeric-string, unassigned: numeric-string}
     */
    public function totals(): array
    {
        // The Job Search total and the attributed amount are read in one transaction, so both come from the same snapshot.
        // Read apart, a payment or a move committed between the two reads made the attributed amount larger than the total
        // and the Unassigned amount negative (found by the MySQL concurrency test).
        return DB::transaction(fn () => $this->snapshotTotals());
    }

    /**
     * @return array{kept: numeric-string, attributed: numeric-string, unassigned: numeric-string}
     */
    private function snapshotTotals(): array
    {
        $jobSearch = Service::query()->where('key', Service::JOB_SEARCH_KEY)->value('id');

        $kept = $jobSearch === null ? 0 : ClientService::query()
            ->toBase()
            ->leftJoinSub($this->paid(), 'paid', 'paid.client_service_id', '=', 'client_services.id')
            ->where('client_services.service_id', $jobSearch)
            ->selectRaw('SUM(COALESCE(paid.satang, 0) - ROUND(client_services.refund_amount * 100) - ROUND(client_services.cancel_refund_amount * 100)) as satang')
            ->value('satang');

        $attributed = $this->attributed()->value('satang');

        $keptMoney = $this->fromSatang($kept);
        $attributedMoney = $this->fromSatang($attributed);

        return [
            'kept' => $keptMoney,
            'attributed' => $attributedMoney,
            'unassigned' => bcsub($keptMoney, $attributedMoney, 2),
        ];
    }

    /**
     * The order each of these Job Search records is attributed to right now. A record that no order owns is missing.
     *
     * @param  array<int, int>  $clientServiceIds
     * @return array<int, int> client service id => job order id
     */
    public function ordersOf(array $clientServiceIds): array
    {
        if ($clientServiceIds === []) {
            return [];
        }

        // Only these records are grouped: the newest qualifying application of each is found among its own applications.
        return DB::table('job_order_workers as a')
            ->joinSub($this->qualifying($clientServiceIds), 'q', 'q.application_id', '=', 'a.id')
            ->whereIn('a.client_service_id', $clientServiceIds)
            ->pluck('a.job_order_id', 'a.client_service_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The attributed applications joined to their record and payments, one row per attributed record, as a base query on which
     * satang can be summed (alias "a" is job_order_workers). With $records only those Job Search records are aggregated (in the
     * qualifying applications and in the payments); null aggregates them all.
     *
     * @param  Builder|array<int, int>|null  $records  a subquery that selects client service ids, or the ids themselves
     */
    private function attributed(Builder|array|null $records = null): Builder
    {
        return DB::table('job_order_workers as a')
            ->joinSub($this->qualifying($records), 'q', 'q.application_id', '=', 'a.id')
            ->join('client_services as cs', 'cs.id', '=', 'a.client_service_id')
            ->leftJoinSub($this->paid($records), 'paid', 'paid.client_service_id', '=', 'cs.id')
            ->selectRaw('SUM(COALESCE(paid.satang, 0) - ROUND(cs.refund_amount * 100) - ROUND(cs.cancel_refund_amount * 100)) as satang');
    }

    /**
     * For each record, the one application that owns its Kept Amount (the newest if, abnormally, there are several). Limiting
     * it to some records is safe because the grouping is per record: a record's answer depends only on its own applications,
     * and all of them are kept, whichever order they are on.
     *
     * @param  Builder|array<int, int>|null  $records
     */
    private function qualifying(Builder|array|null $records = null): Builder
    {
        return DB::table('job_order_workers as w')
            ->join('client_services as c', 'c.id', '=', 'w.client_service_id')
            ->when($records !== null, fn ($query) => $query->whereIn('w.client_service_id', $records))
            ->where(function ($query): void {
                $query->where(fn ($open) => $open
                    ->where('c.status', ClientServiceStatus::InProgress->value)
                    ->whereIn('w.case_status', [WorkerCaseStatus::Applied->value, WorkerCaseStatus::Interview->value]))
                    ->orWhere(fn ($hired) => $hired
                        ->where('c.status', ClientServiceStatus::Complete->value)
                        ->where('w.case_status', WorkerCaseStatus::Hired->value));
            })
            ->groupBy('w.client_service_id')
            ->selectRaw('MAX(w.id) as application_id');
    }

    /**
     * The payments of each record in whole satang. With $records only those records' payments are added up, never the whole
     * payments table; the sum for each record is the same either way.
     *
     * @param  Builder|array<int, int>|null  $records
     */
    private function paid(Builder|array|null $records = null): Builder
    {
        return Payment::query()
            ->toBase()
            ->when($records !== null, fn ($query) => $query->whereIn('client_service_id', $records))
            ->groupBy('client_service_id')
            ->selectRaw('client_service_id, SUM(ROUND(amount * 100)) as satang');
    }
}
