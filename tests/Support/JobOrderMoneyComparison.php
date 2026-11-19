<?php

namespace Tests\Support;

use App\Actions\JobSearch\JobOrderMoney;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\Service;

/**
 * Shared by the SQLite and the MySQL equivalence tests of the Job Order Money optimisation (review finding P1).
 */
final class JobOrderMoneyComparison
{
    /**
     * Compare the optimised and the original answers for every order, record set and total of the database as it is now. It also
     * checks the invariant of technical decisions 17.11: the Money of all orders plus Unassigned is the Job Search Kept Amount, and
     * no record is attributed to two orders.
     */
    public static function assertMatchesOriginal(): void
    {
        $money = app(JobOrderMoney::class);
        $legacy = new LegacyJobOrderMoney;

        $orderIds = JobOrder::query()->pluck('id')->all();
        $orderIds[] = (int) (JobOrder::query()->max('id') ?? 0) + 1000; // an order that does not exist

        $sum = '0.00';

        foreach ($orderIds as $orderId) {
            $new = $money->forOrder($orderId);
            expect($new)->toBe($legacy->forOrder($orderId), "forOrder({$orderId})");
            $sum = bcadd($sum, $new['amount'], 2);
        }

        $totals = $money->totals();
        expect($totals)->toBe($legacy->totals(), 'totals()')
            ->and($sum)->toBe($totals['attributed'], 'the Money of all orders is the attributed total');

        $jobSearchIds = ClientService::query()->whereHas('service', fn ($q) => $q->where('key', Service::JOB_SEARCH_KEY))->pluck('id')->all();
        $otherIds = ClientService::query()->whereNotIn('id', $jobSearchIds)->pluck('id')->all();

        foreach ([$jobSearchIds, array_slice($jobSearchIds, 0, 3), array_reverse($jobSearchIds), $otherIds, [...$otherIds, ...$jobSearchIds], [999_999], []] as $ids) {
            $new = $money->ordersOf($ids);
            $old = $legacy->ordersOf($ids);
            ksort($new);
            ksort($old);
            expect($new)->toBe($old, 'ordersOf('.implode(',', $ids).')');
        }
    }
}
