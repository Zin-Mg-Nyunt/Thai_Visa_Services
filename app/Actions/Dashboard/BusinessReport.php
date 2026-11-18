<?php

namespace App\Actions\Dashboard;

use App\Actions\JobSearch\JobOrderMoney;
use App\Concerns\HandlesMoney;
use App\Enums\ClientServiceStatus;
use App\Enums\RemarkKind;
use App\Enums\ServiceAvailability;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\ClientServiceRemark;
use App\Models\Payment;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The numbers of the Business dashboard, worked out from client services, payments and refund remarks and never stored.
 * Money is exact: the database adds whole satang (SUM(ROUND(amount * 100))), which is exact on MySQL and on SQLite,
 * and PHP turns satang back into baht with bcmath. Money is never a float.
 *
 * Rules:
 * - Money received counts on the day it was paid (the payment date).
 * - A refund from a fee change counts on the day of that change: each automatic refund remark (kind "refund") holds the
 *   refund after the change, so the change from the previous one is what moved that day. Raising the fee back lowers the
 *   refund and adds the money back on that day. Admins' notes and cancel remarks are never read as refunds.
 * - A cancel refund counts on the day the service was cancelled.
 * - So a past month never changes because of something that happened later.
 * - Received is net: payments minus refunds. There is no profit figure; costs are not tracked in this version.
 * - Outstanding is the balance still owed today, so it does not depend on the date range.
 * - Deleted services and deleted clients stay in the money received. A deleted client's unpaid balance is not outstanding:
 *   they leave the lists, so what they still owed is no longer shown (the same as the Clients list).
 */
class BusinessReport
{
    use HandlesMoney;

    /**
     * How many months the "received by month" chart shows, ending with the month of the range end.
     */
    public const CHART_MONTHS = 12;

    /**
     * @return array{
     *     received: numeric-string,
     *     refunded: numeric-string,
     *     outstanding: numeric-string,
     *     unassigned_kept: numeric-string,
     *     active_clients: int,
     *     months: array<int, array{month: string, received: numeric-string, height_percent: int}>,
     *     by_service: array<int, array{service: array{id: int, title: string|null, title_my: string|null, is_inactive: bool}, received: numeric-string, share_percent: int, sub_services: array<int, array{sub_service: array{id: int, title: string|null, title_my: string|null}|null, received: numeric-string}>}>
     * }
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $timezone = config('app.display_timezone');
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $chartStart = $to->startOfMonth()->subMonths(self::CHART_MONTHS - 1);

        // Only the days the range or the chart needs are read, as dates in the agency's time zone.
        $firstDay = min($fromDate, $chartStart->toDateString());
        $lastDay = max($toDate, $to->endOfMonth()->toDateString());
        $dayAfterLast = CarbonImmutable::parse($lastDay, $timezone)->addDay();

        /** @var array<string, numeric-string> $months */
        $months = [];
        for ($month = $chartStart; $month <= $to->startOfMonth(); $month = $month->addMonth()) {
            $months[$month->format('Y-m')] = '0.00';
        }

        $received = '0.00';
        $refunded = '0.00';
        /** @var array<int, array<int, numeric-string>> $buckets [service_id][sub_service_id or 0] => money */
        $buckets = [];

        // Adds money on a day (or takes it off, when negative): to the range totals and to the chart month.
        $book = function (string $day, int $serviceId, ?int $subServiceId, string $amount) use (&$received, &$buckets, &$months, $fromDate, $toDate): void {
            if ($day >= $fromDate && $day <= $toDate) {
                $received = bcadd($received, $amount, 2);
                $bucket = $subServiceId ?? 0;
                $buckets[$serviceId][$bucket] = bcadd($buckets[$serviceId][$bucket] ?? '0.00', $amount, 2);
            }

            $month = substr($day, 0, 7);

            if (isset($months[$month])) {
                $months[$month] = bcadd($months[$month], $amount, 2);
            }
        };

        $refund = function (string $day, int $serviceId, ?int $subServiceId, string $amount) use ($book, &$refunded, $fromDate, $toDate): void {
            $book($day, $serviceId, $subServiceId, bcsub('0', $amount, 2));

            if ($day >= $fromDate && $day <= $toDate) {
                $refunded = bcadd($refunded, $amount, 2);
            }
        };

        foreach ($this->paymentsByDay($firstDay, $dayAfterLast->toDateString()) as $row) {
            $book(
                substr((string) $row->day, 0, 10),
                (int) $row->service_id,
                $row->sub_service_id === null ? null : (int) $row->sub_service_id,
                $this->fromSatang($row->satang),
            );
        }

        foreach ($this->feeRefundMovements($dayAfterLast) as $movement) {
            $refund($movement['day'], $movement['service_id'], $movement['sub_service_id'], $movement['amount']);
        }

        $cancelled = ClientService::query()
            ->whereNotNull('cancelled_at')
            ->where('cancel_refund_amount', '>', 0)
            ->where('cancelled_at', '>=', CarbonImmutable::parse($firstDay, $timezone)->utc())
            ->where('cancelled_at', '<', $dayAfterLast->utc())
            ->get(['id', 'service_id', 'sub_service_id', 'cancel_refund_amount', 'cancelled_at']);

        foreach ($cancelled as $clientService) {
            $refund(
                $clientService->cancelled_at->timezone($timezone)->toDateString(),
                $clientService->service_id,
                $clientService->sub_service_id,
                $clientService->cancel_refund_amount,
            );
        }

        return [
            'received' => $received,
            'refunded' => $refunded,
            'outstanding' => $this->outstanding(),
            // Kept Amount of Job Search records that no job order owns right now. It is part of the money received, never added
            // to it again: Job Search kept = the Money of all orders + this. Like outstanding, it is today's figure, not the range's.
            'unassigned_kept' => app(JobOrderMoney::class)->totals()['unassigned'],
            'active_clients' => Client::query()
                ->whereHas('clientServices', fn ($query) => $query->where('status', ClientServiceStatus::InProgress))
                ->count(),
            'months' => $this->monthRows($months),
            'by_service' => $this->serviceRows($buckets),
        ];
    }

    /**
     * Payments added up in the database per day and per service, in whole satang. Only the days from $firstDay up to
     * (not including) $dayAfter are read, so older payments never leave the database.
     *
     * @return iterable<int, object{day: string, service_id: int, sub_service_id: int|null, satang: int|float|string}>
     */
    private function paymentsByDay(string $firstDay, string $dayAfter): iterable
    {
        return Payment::query()
            ->toBase()
            ->join('client_services', 'client_services.id', '=', 'payments.client_service_id')
            ->where('payments.paid_at', '>=', $firstDay)
            ->where('payments.paid_at', '<', $dayAfter)
            ->groupBy('payments.paid_at', 'client_services.service_id', 'client_services.sub_service_id')
            ->selectRaw('payments.paid_at as day, client_services.service_id, client_services.sub_service_id, SUM(ROUND(payments.amount * 100)) as satang')
            ->get();
    }

    /**
     * How the refund from fee changes moved, day by day. Only automatic refund remarks are read (kind "refund", written by
     * the app in the same transaction as the refund itself); admins' notes and cancel remarks are left out. Each refund
     * remark holds the refund after its change, so the movement is the change from the previous refund remark of the same
     * service. Remarks are taken in id order, the order they were saved, even when two share the same second.
     *
     * A refund the remarks do not explain can only come from editing the database by hand. It still counts, so the figures
     * always agree with the client page: on the cancel day, or else on the last payment day.
     *
     * @return array<int, array{day: string, service_id: int, sub_service_id: int|null, amount: numeric-string}>
     */
    private function feeRefundMovements(CarbonImmutable $dayAfterLast): array
    {
        $timezone = config('app.display_timezone');
        $movements = [];
        /** @var array<int, numeric-string> $explained the refund the remarks reach, per client service */
        $explained = [];

        $remarks = ClientServiceRemark::query()
            ->join('client_services', 'client_services.id', '=', 'client_service_remarks.client_service_id')
            ->where('client_service_remarks.kind', RemarkKind::Refund->value)
            ->orderBy('client_service_remarks.client_service_id')
            ->orderBy('client_service_remarks.id')
            ->get([
                'client_service_remarks.id',
                'client_service_remarks.client_service_id',
                'client_service_remarks.details',
                'client_service_remarks.created_at',
                'client_services.service_id',
                'client_services.sub_service_id',
            ]);

        foreach ($remarks as $remark) {
            $before = $explained[$remark->client_service_id] ?? '0.00';
            $after = bcadd((string) ($remark->details['refund'] ?? '0'), '0', 2);
            $explained[$remark->client_service_id] = $after;

            if ($remark->created_at >= $dayAfterLast || bccomp($after, $before, 2) === 0) {
                continue;
            }

            $subServiceId = $remark->getAttribute('sub_service_id');

            $movements[] = [
                'day' => $remark->created_at->timezone($timezone)->toDateString(),
                'service_id' => (int) $remark->getAttribute('service_id'),
                'sub_service_id' => $subServiceId === null ? null : (int) $subServiceId,
                'amount' => bcsub($after, $before, 2),
            ];
        }

        $unexplained = ClientService::query()
            ->where(fn ($query) => $query->where('refund_amount', '!=', 0)->orWhereIn('id', array_keys($explained)))
            ->get(['id', 'service_id', 'sub_service_id', 'refund_amount', 'cancelled_at'])
            ->filter(fn (ClientService $clientService) => bccomp($clientService->refund_amount, $explained[$clientService->id] ?? '0.00', 2) !== 0);

        if ($unexplained->isEmpty()) {
            return $movements;
        }

        $lastPaid = Payment::query()
            ->toBase()
            ->whereIn('client_service_id', $unexplained->modelKeys())
            ->groupBy('client_service_id')
            ->selectRaw('client_service_id, MAX(paid_at) as last_paid')
            ->pluck('last_paid', 'client_service_id');

        foreach ($unexplained as $clientService) {
            $day = $clientService->cancelled_at?->timezone($timezone)->toDateString()
                ?? substr((string) ($lastPaid[$clientService->id] ?? ''), 0, 10);

            if ($day === '') {
                continue;
            }

            $movements[] = [
                'day' => $day,
                'service_id' => $clientService->service_id,
                'sub_service_id' => $clientService->sub_service_id,
                'amount' => bcsub($clientService->refund_amount, $explained[$clientService->id] ?? '0.00', 2),
            ];
        }

        return $movements;
    }

    /**
     * The balance still owed today on every service that is not cancelled and belongs to a client who is not deleted, added
     * up in the database in whole satang. Each service owes its fee minus its payments, never less than nothing (the same
     * rule as ClientService::remaining()). A deleted client's balance is left out; their received money is not.
     *
     * @return numeric-string
     */
    private function outstanding(): string
    {
        $paid = Payment::query()
            ->toBase()
            ->groupBy('client_service_id')
            ->selectRaw('client_service_id, SUM(ROUND(amount * 100)) as satang');

        $balances = ClientService::query()
            ->toBase()
            ->join('clients', 'clients.id', '=', 'client_services.client_id')
            ->leftJoinSub($paid, 'paid', 'paid.client_service_id', '=', 'client_services.id')
            ->whereNull('clients.deleted_at')
            ->where('client_services.status', '!=', ClientServiceStatus::Cancel->value)
            ->selectRaw('ROUND(client_services.fee * 100) - COALESCE(paid.satang, 0) as owed');

        return $this->fromSatang(DB::query()->fromSub($balances, 'balances')->where('owed', '>', 0)->sum('owed'));
    }

    /**
     * A bar for each chart month. The height follows the size of the amount, so a month below zero still gets a bar;
     * the page colours it as negative.
     *
     * @param  array<string, numeric-string>  $months
     * @return array<int, array{month: string, received: numeric-string, height_percent: int}>
     */
    private function monthRows(array $months): array
    {
        $highest = '0.00';
        foreach ($months as $amount) {
            $size = ltrim($amount, '-');
            $highest = bccomp($size, $highest, 2) === 1 ? $size : $highest;
        }

        $rows = [];

        foreach ($months as $month => $amount) {
            $rows[] = [
                'month' => (string) $month,
                'received' => $amount,
                'height_percent' => $this->percentOf(ltrim($amount, '-'), $highest),
            ];
        }

        return $rows;
    }

    /**
     * A whole-number share of the highest value, for drawing bars. Money maths stays in bcmath; the page never divides money.
     *
     * @param  numeric-string  $amount
     * @param  numeric-string  $highest
     */
    private function percentOf(string $amount, string $highest): int
    {
        if (bccomp($highest, '0', 2) !== 1 || bccomp($amount, '0', 2) !== 1) {
            return 0;
        }

        return (int) bcdiv(bcmul($amount, '100', 4), $highest, 0);
    }

    /**
     * One row per main service with its sub-service rows (and a main-service-only row). The sub-service rows add up
     * to the service row. A service nobody paid for in the range is left out.
     *
     * @param  array<int, array<int, numeric-string>>  $buckets
     * @return array<int, array{service: array{id: int, title: string|null, title_my: string|null, is_inactive: bool}, received: numeric-string, share_percent: int, sub_services: array<int, array{sub_service: array{id: int, title: string|null, title_my: string|null}|null, received: numeric-string}>}>
     */
    private function serviceRows(array $buckets): array
    {
        $ids = array_merge(array_keys($buckets), ...array_map('array_keys', $buckets));
        $services = Service::query()->withTrashed()->whereIn('id', array_unique(array_filter($ids)))->get()->keyBy('id');

        $rows = [];

        foreach ($buckets as $serviceId => $subs) {
            $total = '0.00';
            foreach ($subs as $amount) {
                $total = bcadd($total, $amount, 2);
            }

            $service = $services->get($serviceId);

            if ($service === null || bccomp($total, '0', 2) === 0 && count(array_filter($subs, fn ($amount) => bccomp($amount, '0', 2) !== 0)) === 0) {
                continue;
            }

            $subRows = [];
            $mainOnly = $subs[0] ?? null;
            unset($subs[0]);
            ksort($subs);

            if ($mainOnly !== null) {
                $subs[0] = $mainOnly;
            }

            foreach ($subs as $subId => $amount) {
                $sub = $subId === 0 ? null : $services->get($subId);

                if (bccomp($amount, '0', 2) === 0) {
                    continue;
                }

                $subRows[] = [
                    'sub_service' => $sub === null ? null : ['id' => $sub->id, 'title' => $sub->title, 'title_my' => $sub->title_my],
                    'received' => $amount,
                ];
            }

            $rows[] = [
                'service' => [
                    'id' => $service->id,
                    'title' => $service->title,
                    'title_my' => $service->title_my,
                    'is_inactive' => $service->trashed() || $service->status === ServiceAvailability::Inactive,
                ],
                'received' => $total,
                'share_percent' => 0,
                'sub_services' => $subRows,
            ];
        }

        usort($rows, fn (array $a, array $b) => bccomp($b['received'], $a['received'], 2));

        $highest = $rows[0]['received'] ?? '0.00';

        foreach ($rows as $index => $row) {
            $rows[$index]['share_percent'] = $this->percentOf($row['received'], $highest);
        }

        return $rows;
    }
}
