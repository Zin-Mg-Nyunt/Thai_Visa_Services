<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('the dashboard lives under the admin path and the old path is gone', function () {
    expect(route('dashboard', absolute: false))->toBe('/admin/dashboard');

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertNotFound();
});
