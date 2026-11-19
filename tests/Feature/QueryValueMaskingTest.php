<?php

use App\Models\Client;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 5f.2 finding 5: a failed query's message must not contain the values it was given (a name, a phone number, a birthday).
 */
test('every database connection masks query values in error messages', function () {
    foreach (config('database.connections') as $name => $connection) {
        expect($connection['mask_bindings_in_exception_messages'] ?? false)->toBeTrue($name);
    }
});

test('a failed query keeps its SQL but not the values it was bound to', function () {
    $secret = '+66899887766-SECRET';

    try {
        DB::select('select * from no_such_table where phone = ?', [$secret]);
        $this->fail('The query should have failed.');
    } catch (QueryException $e) {
        expect($e->getMessage())
            ->toContain('no_such_table')
            ->not->toContain($secret)
            ->and($e->getBindings())->toBe([$secret]);
    }
});

test('a failed insert through a model does not leak the person data into the message', function () {
    try {
        Client::query()->insert(['name' => 'Somchai Secretname', 'phone' => '+66811112222', 'no_such_column' => 'x']);
        $this->fail('The insert should have failed.');
    } catch (QueryException $e) {
        expect($e->getMessage())->not->toContain('Somchai Secretname')->not->toContain('+66811112222');
    }
});
