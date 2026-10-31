<?php

use App\Enums\Gender;
use App\Models\Client;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

test('a new client gets a random tracking token of at least 32 characters', function () {
    $client = Client::factory()->create();

    expect(strlen($client->unique_token))->toBeGreaterThanOrEqual(32);
});

test('every client gets a different tracking token', function () {
    [$first, $second] = Client::factory()->count(2)->create();

    expect($first->unique_token)->not->toBe($second->unique_token);
});

test('two clients cannot share a phone number', function () {
    Client::factory()->create(['phone' => '+66812345678']);

    Client::factory()->create(['phone' => '+66812345678']);
})->throws(UniqueConstraintViolationException::class);

test('a deleted client and an active client can share a phone number', function () {
    $deleted = Client::factory()->create(['phone' => '+66812345678']);
    $deleted->delete();

    $active = Client::factory()->create(['phone' => '+66812345678']);

    expect($active->phone)->toBe($deleted->phone)
        ->and($active->id)->not->toBe($deleted->id)
        ->and(Client::count())->toBe(1)
        ->and(Client::withTrashed()->count())->toBe(2);
});

test('several deleted clients can share a phone number with one active client', function () {
    Client::factory()->create(['phone' => '+66812345678'])->delete();
    Client::factory()->create(['phone' => '+66812345678'])->delete();
    Client::factory()->create(['phone' => '+66812345678']);

    expect(Client::withTrashed()->where('phone', '+66812345678')->count())->toBe(3);
});

test('a phone number that an active client uses stays unique after another client with it is deleted', function () {
    Client::factory()->create(['phone' => '+66812345678'])->delete();
    Client::factory()->create(['phone' => '+66812345678']);

    Client::factory()->create(['phone' => '+66812345678']);
})->throws(UniqueConstraintViolationException::class);

test('a client without a phone number cannot be created', function () {
    Client::factory()->create(['phone' => null]);
})->throws(QueryException::class);

test('birthday and gender are cast to a date and an enum', function () {
    $client = Client::factory()->create(['birthday' => '1995-03-12', 'gender' => 'male']);

    $client = $client->fresh();

    expect($client->birthday->toDateString())->toBe('1995-03-12')
        ->and($client->gender)->toBe(Gender::Male);
});

test('a soft-deleted client is hidden from queries but kept in the database', function () {
    $client = Client::factory()->create();

    $client->delete();

    expect(Client::find($client->id))->toBeNull()
        ->and(Client::withTrashed()->find($client->id))->not->toBeNull();
});

test('the tracking token cannot be set by mass assignment', function () {
    $client = Client::create([
        'name' => 'Aung Min',
        'phone' => '+66812345678',
        'birthday' => '1995-03-12',
        'gender' => 'male',
        'unique_token' => 'chosen-by-the-caller',
    ]);

    expect($client->unique_token)->not->toBe('chosen-by-the-caller');
});

test('phone numbers are stored in international format', function (string $typed) {
    $client = Client::factory()->create(['phone' => $typed]);

    expect($client->fresh()->phone)->toBe('+66812345678');
})->with([
    'local number with spaces' => '081 234 5678',
    'international with dashes' => '+66 81-234-5678',
    'double zero prefix' => '0066812345678',
    'brackets and dots' => '(081) 234.5678',
]);

test('a phone number from another country keeps its country code', function () {
    $client = Client::factory()->create(['phone' => '+95 9 123 456 789']);

    expect($client->fresh()->phone)->toBe('+959123456789');
});
