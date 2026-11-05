<?php

use App\Enums\ClientServiceStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Service;
use Illuminate\Database\QueryException;

test('a client can take a main service without a sub-service', function () {
    $service = Service::factory()->create();

    $clientService = ClientService::factory()->forService($service)->create();

    expect($clientService->sub_service_id)->toBeNull()
        ->and($clientService->service->is($service))->toBeTrue()
        ->and($clientService->subService)->toBeNull()
        ->and($clientService->status)->toBe(ClientServiceStatus::InProgress)
        ->and($clientService->fee)->toBeString()
        ->and($clientService->refund_amount)->toBe('0.00');
});

test('a client can take a main service with one of its sub-services', function () {
    $service = Service::factory()->create();
    $sub = Service::factory()->subServiceOf($service)->create();

    $clientService = ClientService::factory()->forService($service, $sub)->create();

    expect($clientService->subService->is($sub))->toBeTrue()
        ->and($clientService->service->is($service))->toBeTrue();
});

test('the database rejects a sub-service that belongs to another service', function () {
    $service = Service::factory()->create();
    $other = Service::factory()->create();
    $otherSub = Service::factory()->subServiceOf($other)->create();

    ClientService::factory()->forService($service, $otherSub)->create();
})->throws(QueryException::class);

test('the database rejects a main service used as the sub-service', function () {
    $service = Service::factory()->create();
    $other = Service::factory()->create();

    ClientService::factory()->forService($service, $other)->create();
})->throws(QueryException::class);

test('a service in use cannot be hard deleted', function () {
    $clientService = ClientService::factory()->create();

    $clientService->service()->withTrashed()->first()->forceDelete();
})->throws(QueryException::class);

test('a used sub-service cannot be hard deleted or moved to another parent', function () {
    $service = Service::factory()->create();
    $other = Service::factory()->create();
    $sub = Service::factory()->subServiceOf($service)->create();
    ClientService::factory()->forService($service, $sub)->create();

    expect(fn () => $sub->forceDelete())->toThrow(QueryException::class)
        ->and(fn () => $sub->update(['parent_id' => $other->id]))->toThrow(QueryException::class);
});

test('the history stays visible after the client or the service is soft deleted', function () {
    $service = Service::factory()->create();
    $sub = Service::factory()->subServiceOf($service)->create();
    $clientService = ClientService::factory()->forService($service, $sub)->create();

    $clientService->client->delete();
    $sub->delete();
    $service->delete();

    $fresh = ClientService::find($clientService->id);

    expect($fresh->client->trashed())->toBeTrue()
        ->and($fresh->service->trashed())->toBeTrue()
        ->and($fresh->subService->trashed())->toBeTrue();
});

test('a client has many client services', function () {
    $client = Client::factory()->create();
    ClientService::factory()->count(2)->create(['client_id' => $client->id]);

    expect($client->clientServices)->toHaveCount(2);
});

test('the cancelled state sets the status and the cancel time', function () {
    $clientService = ClientService::factory()->cancelled()->create(['refund_amount' => 500]);

    expect($clientService->status)->toBe(ClientServiceStatus::Cancel)
        ->and($clientService->cancelled_at)->not->toBeNull()
        ->and($clientService->refund_amount)->toBe('500.00');
});
