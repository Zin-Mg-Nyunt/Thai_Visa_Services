<?php

use App\Http\Requests\StoreClientRequest;
use App\Models\Client;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => app()->setLocale('en'));

/**
 * Run the request's authorization and validation and return the validation errors.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, array<int, string>>
 */
function storeClientErrors(array $data): array
{
    $request = StoreClientRequest::create('/admin/clients', 'POST', $data);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->setUserResolver(fn () => User::factory()->create());

    try {
        $request->validateResolved();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

/**
 * @return array<string, string>
 */
function validClientInput(): array
{
    return ['name' => 'Aung Min', 'phone' => '081 234 5678', 'birthday' => '1995-03-12', 'gender' => 'male'];
}

test('a complete client is accepted', function () {
    expect(storeClientErrors(validClientInput()))->toBe([]);
});

test('a phone number that exists in another format is rejected as a duplicate', function () {
    Client::factory()->create(['phone' => '+66812345678']);

    $errors = storeClientErrors(['phone' => '0066-81-234-5678'] + validClientInput());

    expect($errors['phone'])->toBe(['A client with this phone number already exists.']);
});

test('a phone number that only a deleted client has is accepted', function () {
    Client::factory()->create(['phone' => '+66812345678'])->delete();

    expect(storeClientErrors(validClientInput()))->toBe([]);
});

test('the duplicate phone message is shown in Burmese when the locale is Burmese', function () {
    app()->setLocale('my');
    Client::factory()->create(['phone' => '+66812345678']);

    $errors = storeClientErrors(validClientInput());

    expect($errors['phone'])->toBe(['ဤဖုန်းနံပါတ်ဖြင့် ဖောက်သည် ရှိပြီးသား ဖြစ်ပါသည်။']);
});

test('a phone number that cannot be put into international format is rejected', function (string $phone) {
    $errors = storeClientErrors(['phone' => $phone] + validClientInput());

    expect($errors['phone'])->toBe(['The phone number must be in international format, for example +66 81 234 5678.']);
})->with([
    'no country code or leading zero' => '812345678',
    'letters mixed with digits' => '12ab34',
    'too short' => '+6681',
]);

test('required fields are rejected when missing', function (string $field) {
    $errors = storeClientErrors(array_diff_key(validClientInput(), [$field => true]));

    expect($errors)->toHaveKey($field);
})->with(['name', 'phone', 'birthday', 'gender']);

test('a birthday in the future and an unknown gender are rejected', function () {
    $errors = storeClientErrors(['birthday' => now()->addDay()->toDateString(), 'gender' => 'robot'] + validClientInput());

    expect($errors)->toHaveKeys(['birthday', 'gender']);
});

test('birthday and gender errors are shown in Burmese when the locale is Burmese', function () {
    app()->setLocale('my');

    $errors = storeClientErrors(['birthday' => now()->addDay()->toDateString(), 'gender' => 'robot'] + validClientInput());

    expect($errors['birthday'])->toBe(['မွေးနေ့ သည် ယနေ့ထက် စောသော ရက်စွဲ ဖြစ်ရပါမည်။'])
        ->and($errors['gender'])->toBe(['ရွေးချယ်ထားသော ကျား/မ သည် မမှန်ကန်ပါ။']);
});
