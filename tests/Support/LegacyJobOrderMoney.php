<?php

namespace Tests\Support;

use App\Concerns\HandlesMoney;
use App\Enums\ClientServiceStatus;
use App\Enums\WorkerCaseStatus;
use App\Models\ClientService;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Job Order Money queries exactly as they were before the review optimisation (HEAD dd0dd4b), kept only as a reference for
 * tests. The optimised App\Actions\JobSearch\JobOrderMoney must give the same answer as this class for every data set: the
 * equivalence tests build many states and compare the two. Never use it in the application.
 *
 * Here every aggregate reads the whole of job_order_workers and payments, and the order or record filter is applied afterwards.
 */
final class LegacyJobOrderMoney
{
    use HandlesMoney;

    /**
     * @return array{amount: numeric-string, includes_deleted: bool}
     */
    public function forOrder(int $jobOrderId): array
    {
        return DB::transaction(function () use ($jobOrderId): array {
            $amount = $this->attributed()->where('a.job_order_id', $jobOrderId)->value('satang');

            $deleted = $this->attributed()
                ->join('clients', 'clients.id', '=', 'a.client_id')
                ->whereNotNull('clients.deleted_at')
                ->where('a.job_order_id', $jobOrderId)
                ->count() > 0;

            return ['amount' => $this->fromSatang($amount), 'includes_deleted' => $deleted];
        });
    }

    /**
     * @return array{kept: numeric-string, attributed: numeric-string, unassigned: numeric-string}
     */
    public function totals(): array
    {
        return DB::transaction(function (): array {
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
        });
    }

    /**
     * @param  array<int, int>  $clientServiceIds
     * @return array<int, int>
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

    private function attributed(): Builder
    {
        return DB::table('job_order_workers as a')
            ->joinSub($this->qualifying(), 'q', 'q.application_id', '=', 'a.id')
            ->join('client_services as cs', 'cs.id', '=', 'a.client_service_id')
            ->leftJoinSub($this->paid(), 'paid', 'paid.client_service_id', '=', 'cs.id')
            ->selectRaw('SUM(COALESCE(paid.satang, 0) - ROUND(cs.refund_amount * 100) - ROUND(cs.cancel_refund_amount * 100)) as satang');
    }

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
