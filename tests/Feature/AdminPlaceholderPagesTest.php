<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

dataset('admin sections', [
    'clients' => ['admin.clients', 'clients'],
    'services' => ['admin.services', 'services'],
    'job search' => ['admin.job-search', 'jobSearch'],
    'settings' => ['admin.settings', 'settings'],
]);

test('guests are redirected to the login page', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('login'));
})->with('admin sections');

test('admins see the placeholder page for each section', function (string $routeName, string $section) {
    $this->actingAs(User::factory()->create())
        ->get(route($routeName))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/ComingSoon')
            ->where('section', $section));
})->with('admin sections');

test('users without the superadmin role are refused', function (string $routeName) {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);

    $this->actingAs($user->fresh())
        ->get(route($routeName))
        ->assertForbidden();
})->with('admin sections');
