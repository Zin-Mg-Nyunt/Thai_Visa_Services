<?php

use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => app()->setLocale('en'));

/**
 * Run the request's authorization and validation for a client and return the validation errors.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, array<int, string>>
 */
function updateClientErrors(Client $client, array $data): array
{
    $request = UpdateClientRequest::create("/admin/clients/{$client->id}", 'PUT', $data);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->setUserResolver(fn () => User::factory()->create());

    $route = (new Route('PUT', '/admin/clients/{client}', fn () => null))->bind($request);
    $route->setParameter('client', $client);
    $request->setRouteResolver(fn () => $route);

    try {
        $request->validateResolved();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

test('a client may keep its own phone number', function () {
    $client = Client::factory()->create(['phone' => '+66812345678']);

    $errors = updateClientErrors($client, [
        'name' => 'New Name',
        'phone' => '081 234 5678',
        'birthday' => '1995-03-12',
        'gender' => 'female',
    ]);

    expect($errors)->toBe([]);
});

test('a client cannot take the phone number of another client', function () {
    Client::factory()->create(['phone' => '+66899999999']);
    $client = Client::factory()->create();

    $errors = updateClientErrors($client, [
        'name' => $client->name,
        'phone' => '089 999 9999',
        'birthday' => '1995-03-12',
        'gender' => 'male',
    ]);

    expect($errors['phone'])->toBe(['A client with this phone number already exists.']);
});

test('a client may take the phone number of a deleted client', function () {
    Client::factory()->create(['phone' => '+66899999999'])->delete();
    $client = Client::factory()->create();

    $errors = updateClientErrors($client, [
        'name' => $client->name,
        'phone' => '089 999 9999',
        'birthday' => '1995-03-12',
        'gender' => 'male',
    ]);

    expect($errors)->toBe([]);
});
