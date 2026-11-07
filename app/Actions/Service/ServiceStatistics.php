<?php

namespace App\Actions\Service;

use App\Enums\ClientServiceStatus;
use App\Models\ClientService;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The numbers shown on the service pages. They are worked out from the client services and their payments,
 * never stored. Money is the amount the agency kept (received minus refunds) and is added up as exact
 * decimal strings, so it can never drift the way a float can.
 *
 * @phpstan-type Stats array{total_clients: int, in_progress: int, complete: int, total_received: numeric-string}
 */
class ServiceStatistics
{
    /**
     * The totals of every main service, keyed by service id. A main service counts all of its client services,
     * with or without a sub-service. A service nobody has taken is missing; use summarise() on an empty list.
     *
     * @return array<int, Stats>
     */
    public function forMainServices(): array
    {
        $stats = [];

        foreach ($this->clientServices()->get()->groupBy('service_id') as $serviceId => $rows) {
            $stats[(int) $serviceId] = $this->summarise($rows);
        }

        return $stats;
    }

    /**
     * @return Stats
     */
    public function forService(Service $service): array
    {
        return $this->summarise(
            $this->clientServices()
                ->where($service->isSubService() ? 'sub_service_id' : 'service_id', $service->getKey())
                ->get(),
        );
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
        $rows = $this->clientServices()->where('service_id', $mainService->getKey())->get();
        $bySubService = $rows->groupBy(fn (ClientService $row) => $row->sub_service_id ?? 0);

        $breakdown = [];

        $subServices = Service::query()->withTrashed()->where('parent_id', $mainService->getKey())->orderBy('id')->get();

        foreach ($subServices as $sub) {
            if ($sub->trashed() && ! $bySubService->has($sub->id)) {
                continue;
            }

            $breakdown[] = [
                'sub_service' => ['id' => $sub->id, 'title' => $sub->title, 'title_my' => $sub->title_my],
                'stats' => $this->summarise($bySubService->get($sub->id, collect())),
            ];
        }

        $breakdown[] = ['sub_service' => null, 'stats' => $this->summarise($bySubService->get(0, collect()))];

        return $breakdown;
    }

    /**
     * Client services with the payments they need to work out the money kept.
     *
     * @return Builder<ClientService>
     */
    private function clientServices(): Builder
    {
        return ClientService::query()
            ->select(['id', 'client_id', 'service_id', 'sub_service_id', 'status', 'refund_amount', 'cancel_refund_amount'])
            ->with('payments:id,client_service_id,amount');
    }

    /**
     * @param  Collection<int, ClientService>  $rows
     * @return Stats
     */
    private function summarise(Collection $rows): array
    {
        $kept = '0.00';

        foreach ($rows as $row) {
            $kept = bcadd($kept, $row->kept(), 2);
        }

        return [
            'total_clients' => $rows->pluck('client_id')->unique()->count(),
            'in_progress' => $rows->where('status', ClientServiceStatus::InProgress)->count(),
            'complete' => $rows->where('status', ClientServiceStatus::Complete)->count(),
            'total_received' => $kept,
        ];
    }
}
