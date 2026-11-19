<?php

use App\Models\Client;
use App\Models\JobOrder;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The worker phone lookup answers with a person's name, birthday and gender, so it is rate limited (review finding S2): 30
 * lookups a minute for each signed-in superadmin. Authorization, the POST-only route and the answer itself are unchanged.
 */
beforeEach(function () {
    app()->setLocale('en');
    Service::factory()->jobSearch()->create();
    $this->admin = User::factory()->create();
    $this->order = JobOrder::factory()->create();
    $this->person = Client::factory()->create(['phone' => '+66812345678']);
});

function lookUp(object $test, User $user, ?JobOrder $order = null): TestResponse
{
    return $test->actingAs($user)->postJson(route('admin.job-search.workers.lookup', $order ?? $test->order), ['phone' => '+66812345678']);
}

test('an admin can look up 30 numbers a minute; the 31st is refused with 429 and reveals nobody', function () {
    for ($i = 1; $i <= 30; $i++) {
        lookUp($this, $this->admin)->assertOk()->assertJsonPath('client.id', $this->person->id);
    }

    $blocked = lookUp($this, $this->admin)->assertStatus(429);

    expect($blocked->headers->get('Retry-After'))->not->toBeNull()
        ->and($blocked->getContent())->not->toContain($this->person->name)
        ->and($blocked->getContent())->not->toContain('+66812345678');
});

test('the lookup works again once the minute has passed', function () {
    for ($i = 1; $i <= 30; $i++) {
        lookUp($this, $this->admin)->assertOk();
    }

    lookUp($this, $this->admin)->assertStatus(429);

    $this->travel(61)->seconds();

    lookUp($this, $this->admin)->assertOk();
});

test('each admin has a budget of their own', function () {
    $other = User::factory()->create();

    for ($i = 1; $i <= 30; $i++) {
        lookUp($this, $this->admin)->assertOk();
    }

    lookUp($this, $this->admin)->assertStatus(429);
    lookUp($this, $other)->assertOk()->assertJsonPath('client.id', $this->person->id);
});

test('guests and non-superadmins never see a person, whether or not they hit the limit, and the lookup stays POST only', function () {
    $this->post(route('admin.job-search.workers.lookup', $this->order), ['phone' => '+66812345678'])->assertRedirect(route('login'));

    $staff = User::factory()->create();
    DB::table('users')->where('id', $staff->id)->update(['role' => 'staff']);

    // A signed-in user who is not a superadmin is refused; they are counted on their own budget, and past it get 429.
    for ($i = 1; $i <= 30; $i++) {
        lookUp($this, $staff->fresh())->assertForbidden();
    }

    $blocked = lookUp($this, $staff->fresh())->assertStatus(429);
    expect($blocked->getContent())->not->toContain($this->person->name);

    $this->actingAs($this->admin)->getJson('/admin/job-search/'.$this->order->id.'/workers/lookup')->assertStatus(405);

    // None of that used the admin's own lookups: all 30 are still there.
    for ($i = 1; $i <= 30; $i++) {
        lookUp($this, $this->admin)->assertOk();
    }
});

test('a closed order still refuses the lookup', function () {
    $closed = JobOrder::factory()->done()->create();

    lookUp($this, $this->admin, $closed)->assertForbidden();
});
