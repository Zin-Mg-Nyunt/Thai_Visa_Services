<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The migration that makes the agency name one name: a Burmese-only name moves into `agency_name`, an English name is kept, and the
 * Burmese column is gone.
 */
function agencyNameMigration(): object
{
    return require database_path('migrations/2026_11_26_100000_merge_agency_name_into_one_field.php');
}

test('a Burmese-only name moves into the one name, an English name is kept, and the column is dropped', function (?string $english, ?string $burmese, ?string $expected) {
    $migration = agencyNameMigration();
    $migration->down();

    DB::table('site_settings')->insert(['id' => 1, 'agency_name' => $english, 'agency_name_my' => $burmese, 'is_published' => false]);

    $migration->up();

    expect(Schema::hasColumn('site_settings', 'agency_name_my'))->toBeFalse()
        ->and(DB::table('site_settings')->value('agency_name'))->toBe($expected);
})->with([
    'Burmese only' => [null, 'Min Min', 'Min Min'],
    'English empty text' => ['  ', 'မင်းမင်း', 'မင်းမင်း'],
    'English kept' => ['Paing Thu', 'ပိုင်သူ', 'Paing Thu'],
    'both empty' => [null, null, null],
]);
