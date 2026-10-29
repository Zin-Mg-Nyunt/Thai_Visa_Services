<?php

use App\Models\Client;
use App\Models\User;

test('a superadmin may list and create clients', function (string $ability) {
    expect(User::factory()->create()->can($ability, Client::class))->toBeTrue();
})->with(['viewAny', 'create']);

test('a superadmin may view, update and delete a client', function (string $ability) {
    expect(User::factory()->create()->can($ability, Client::factory()->create()))->toBeTrue();
})->with(['view', 'update', 'delete']);
