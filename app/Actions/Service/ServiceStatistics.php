<?php

namespace App\Actions\Service;

use App\Concerns\HandlesMoney;
use App\Enums\ClientServiceStatus;
use App\Models\ClientService;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Database\Query\Builder;

/**
 * The numbers shown on the service pages. They are worked out from the client services and their payments,
 * never stored. Money is the amount the agency kept (received minus the refund and the cancel refund), the same
 * as ClientService::kept() added up.
 *
 * Everything is added up in the database: payments are summed per client service first, so joining them never
 * repeats a client service, and money is summed in whole satang (SUM(ROUND(amount * 100))), which is exact on
 * MySQL and on SQLite. No payment row is loaded into PHP.
 *
 * Every client service counts, also those of deleted clients and of deleted sub-services; client services
 * themselves are never deleted. A cancelled service counts in the clients and the money, not in the statuses.
 *
 * @phpstan-type Stats array{total_clients: int, in_progress: int, complete: int, total_received: numeric-string}
 */
class ServiceStatistics
{
    use HandlesMoney;

    /**
     * The totals of every main service, keyed by service id. A main service counts all of its client services,
     * with or without a sub-service. A service nobody has taken is missing; the page shows zeros for it.
     *
     * @return array<int, Stats>
     */
    public function forMainServices(): array
    {
        $stats = [];

        foreach ($this->totals()->addSelect('client_services.service_id as group_id')->groupBy('client_services.service_id')->get() as $row) {
            $stats[(int) $row->group_id] = $this->stats($row);
        }

        return $stats;
    }

    /**
     * @return Stats
     */
    public function forService(Service $service): array
    {
        $row = $this->totals()
            ->where($service->isSubService() ? 'client_services.sub_service_id' : 'client_services.service_id', $service->getKey())
            ->first();

        return $this->stats($row);
    }

    /**
     * One row per sub-service of a main service, then a "main service only" row (sub_service is null). Together
     * the rows hold exactly the client services of the main service, so the money adds up to its total. A
     * deleted sub-service is listed only while it still has client services.
     *
     * @return array<int, array{sub_service: array{id: int, title: string|null, title_my: string|null}|null, stats: Stats}>
     */
    public function breakdown(Service $mainService): array
    {
        $bySubService = $this->totals()
            ->addSelect('client_services.sub_service_id as group_id')
            ->where('client_services.service_id', $mainService->getKey())
            ->groupBy('client_services.sub_service_id')
            ->get()
            ->keyBy(fn (object $row) => (int) ($row->group_id ?? 0));

        $breakdown = [];

        $subServices = Service::query()->withTrashed()->where('parent_id', $mainService->getKey())->orderBy('id')->get();

        foreach ($subServices as $sub) {
            if ($sub->trashed() && ! $bySubService->has($sub->id)) {
                continue;
            }

            $breakdown[] = [
                'sub_service' => ['id' => $sub->id, 'title' => $sub->title, 'title_my' => $sub->title_my],
                'stats' => $this->stats($bySubService->get($sub->id)),
            ];
        }

        $breakdown[] = ['sub_service' => null, 'stats' => $this->stats($bySubService->get(0))];

        return $breakdown;
    }

    /**
     * The totals of the client services the caller filters and groups: clients, statuses and the money kept, in satang.
     */
    private function totals(): Builder
    {
        $paid = Payment::query()
            ->toBase()
            ->groupBy('client_service_id')
            ->selectRaw('client_service_id, SUM(ROUND(amount * 100)) as satang');

        return ClientService::query()
            ->toBase()
            ->leftJoinSub($paid, 'paid', 'paid.client_service_id', '=', 'client_services.id')
            ->selectRaw('COUNT(DISTINCT client_services.client_id) as total_clients')
            ->selectRaw('SUM(CASE WHEN client_services.status = ? THEN 1 ELSE 0 END) as in_progress', [ClientServiceStatus::InProgress->value])
            ->selectRaw('SUM(CASE WHEN client_services.status = ? THEN 1 ELSE 0 END) as complete', [ClientServiceStatus::Complete->value])
            ->selectRaw('SUM(COALESCE(paid.satang, 0) - ROUND(client_services.refund_amount * 100) - ROUND(client_services.cancel_refund_amount * 100)) as kept_satang');
    }

    /**
     * One result row as the page's numbers. No row (nothing taken yet) is all zeros.
     *
     * @return Stats
     */
    private function stats(?object $row): array
    {
        return [
            'total_clients' => (int) ($row->total_clients ?? 0),
            'in_progress' => (int) ($row->in_progress ?? 0),
            'complete' => (int) ($row->complete ?? 0),
            'total_received' => $this->fromSatang($row->kept_satang ?? null),
        ];
    }
}
