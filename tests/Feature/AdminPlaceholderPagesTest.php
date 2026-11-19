<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The admin Settings page. For now it only links to the Website appearance screen; the full Settings hub is a later step.
 */
test('guests are redirected to the login page', function () {
    $this->get(route('admin.settings'))->assertRedirect(route('login'));
});

test('an admin sees the Settings page with a link to Website appearance', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/Index')
            ->where('appearance_url', route('admin.settings.appearance.edit')));
});

test('the page draws the link from that address and the address opens the appearance screen', function () {
    $admin = User::factory()->create();
    $page = file_get_contents(resource_path('js/pages/admin/settings/Index.vue'));

    expect($page)->toContain('v-if="appearance_url"')->toContain(':href="appearance_url"')->toContain('data-test="appearance-link"');

    $this->actingAs($admin)->get(route('admin.settings.appearance.edit'))->assertOk();
});

test('the admin navigation already leads to the Settings page, and the link is not added to the sidebar', function () {
    $nav = file_get_contents(resource_path('js/composables/useAdminNavItems.ts'));

    expect($nav)->toContain('settings()')->not->toContain('appearance');
});

test('a user without the superadmin role is refused the Settings page and gets no link', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);

    $this->actingAs($user->fresh())->get(route('admin.settings'))->assertForbidden();
});
