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
     * @return array{amount: numeric-string, includes_deleted: bool}
     */
    public function forOrder(int $jobOrderId): array
    {
        // One transaction, so both reads see the same snapshot (InnoDB's default REPEATABLE READ fixes it at the first read).
        return DB::transaction(fn () => $this->orderMoney($jobOrderId));
    }

    /**
     * @return array{amount: numeric-string, includes_deleted: bool}
     */
    private function orderMoney(int $jobOrderId): array
    {
        $amount = $this->attributed()->where('a.job_order_id', $jobOrderId)->value('satang');

        // count(), not exists(): the base query is an aggregate, which always yields one row, even when nothing matched.
        $deleted = $this->attributed()
            ->join('clients', 'clients.id', '=', 'a.client_id')
            ->whereNotNull('clients.deleted_at')
            ->where('a.job_order_id', $jobOrderId)
            ->count() > 0;

        return ['amount' => $this->fromSatang($amount), 'includes_deleted' => $deleted];
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

        return DB::table('job_order_workers as a')
            ->joinSub($this->qualifying(), 'q', 'q.application_id', '=', 'a.id')
            ->whereIn('a.client_service_id', $clientServiceIds)
            ->pluck('a.job_order_id', 'a.client_service_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The attributed applications joined to their record and payments, one row per attributed record, as a base query on which
     * satang can be summed (alias "a" is job_order_workers).
     */
    private function attributed(): Builder
    {
        return DB::table('job_order_workers as a')
            ->joinSub($this->qualifying(), 'q', 'q.application_id', '=', 'a.id')
            ->join('client_services as cs', 'cs.id', '=', 'a.client_service_id')
            ->leftJoinSub($this->paid(), 'paid', 'paid.client_service_id', '=', 'cs.id')
            ->selectRaw('SUM(COALESCE(paid.satang, 0) - ROUND(cs.refund_amount * 100) - ROUND(cs.cancel_refund_amount * 100)) as satang');
    }

    /**
     * For each record, the one application that owns its Kept Amount (the newest if, abnormally, there are several).
     */
    private function qualifying(): Builder
    {
        return DB::table('job_order_workers as w')
            ->join('client_services as c', 'c.id', '=', 'w.client_service_id')
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

    private function paid(): Builder
    {
        return Payment::query()
            ->toBase()
            ->groupBy('client_service_id')
            ->selectRaw('client_service_id, SUM(ROUND(amount * 100)) as satang');
    }
}
