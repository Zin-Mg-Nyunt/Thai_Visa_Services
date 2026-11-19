<?php

use Tests\Support\JobOrderMoneyComparison;
use Tests\Support\JobOrderMoneyWorld;

/**
 * Review finding P1 on the real database: the optimised Job Order Money queries must give exactly the answers of the original
 * queries on MySQL too (ROUND, derived tables and semi-joins are MySQL's own here, not SQLite's). Each seeded data set is
 * committed, compared, and then deleted row by row; nothing else in Thai_Visa_Services_Testing is touched.
 * Run with: php artisan test -c phpunit.mysql.xml --filter=JobOrderMoneyEquivalenceMysqlTest
 */
test('the optimised queries give the original answers on MySQL', function (int $seed) {
    $world = new JobOrderMoneyWorld($seed);

    try {
        $world->build(people: 80, orders: 8, paymentsPerRecord: 3, otherServicePeople: 20);

        JobOrderMoneyComparison::assertMatchesOriginal();
    } finally {
        $world->cleanUp();
    }
})->with(range(101, 106));
