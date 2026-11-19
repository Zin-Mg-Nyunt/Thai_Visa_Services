<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The admin Settings hub (/admin/settings): the admin's own account, the two website screens, and two read-only facts (the Google
 * sign-in state and the currency). See App\Http\Controllers\Admin\SettingsController.
 */
test('guests are redirected to the login page', function () {
    $this->get(route('admin.settings'))->assertRedirect(route('login'));
});

test('an admin sees the account, the website links, the Google state and the currency', function () {
    $admin = User::factory()->create(['name' => 'Aung Min', 'email' => 'aung@example.test']);

    $this->actingAs($admin)->get(route('admin.settings'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/settings/Index')
        ->where('account.name', 'Aung Min')->where('account.email', 'aung@example.test')
        ->where('account.profile_url', route('profile.edit'))->where('account.security_url', route('security.edit'))
        ->where('appearance_url', route('admin.settings.appearance.edit'))
        ->where('content_url', route('admin.settings.content.edit'))
        ->where('google_linked', false)->where('currency', 'THB'));
});

test('the Google sign-in state is read only: it says whether the account is linked and never sends the Google id', function () {
    $admin = User::factory()->create();
    DB::table('users')->where('id', $admin->id)->update(['google_id' => 'google-id-1234567890']);

    $response = $this->actingAs($admin->fresh())->get(route('admin.settings'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page->where('google_linked', true));
    expect(json_encode($response->viewData('page')['props']))->not->toContain('google-id-1234567890')->not->toContain('google_id');
});

test('every link on the page opens a working screen', function () {
    $admin = User::factory()->create();

    foreach (['profile.edit', 'admin.settings.appearance.edit', 'admin.settings.content.edit'] as $name) {
        $this->actingAs($admin)->get(route($name))->assertSuccessful();
    }

    // The Security page keeps its own protection: it asks for the password first, as it did before this page linked to it.
    $this->actingAs($admin)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
});

test('the website links are not offered to an admin who may not manage the website', function () {
    Gate::define('manage-site-settings', fn () => false);

    $this->actingAs(User::factory()->create())->get(route('admin.settings'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('appearance_url', null)->where('content_url', null)
        ->where('account.profile_url', route('profile.edit')));
});

test('the page draws every row from those props, and no Coming soon is left for Settings', function () {
    $page = file_get_contents(resource_path('js/pages/admin/settings/Index.vue'));
    $routes = file_get_contents(base_path('routes/web.php'));

    expect($page)->toContain(':href="account.profile_url"')->toContain(':href="account.security_url"')
        ->toContain(':href="appearance_url"')->toContain(':href="content_url"')
        ->toContain('v-if="appearance_url || content_url"')->toContain('data-test="google-state"')->toContain('data-test="currency"')
        ->not->toContain('comingSoon')
        ->and($routes)->not->toContain("'admin/ComingSoon', ['section' => 'settings']");
});

test('the sidebar still has the single Settings item and no extra link', function () {
    $nav = file_get_contents(resource_path('js/composables/useAdminNavItems.ts'));

    expect($nav)->toContain('settings()')->not->toContain('appearance')->not->toContain('content');
});

test('a user without the superadmin role is refused the Settings page', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['role' => 'staff']);

    $this->actingAs($user->fresh())->get(route('admin.settings'))->assertForbidden();
});
