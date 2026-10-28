<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Route::middleware('web')->post('/locale-test', fn (Request $request) => $request->validate([
        'name' => ['required'],
    ]));
});

test('burmese is the default language', function () {
    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'my'));
});

test('the language sent by the browser is applied', function () {
    $this->withHeader('X-Locale', 'en')
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

test('an unsupported language is ignored', function () {
    $this->withHeader('X-Locale', 'th')
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'my'));
});

test('validation messages follow the selected language', function (string $locale, string $attribute) {
    $this->withHeader('X-Locale', $locale)
        ->postJson('/locale-test')
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', trans('validation.required', ['attribute' => $attribute], $locale));
})->with([
    'burmese' => ['my', 'အမည်'],
    'english' => ['en', 'name'],
]);

test('burmese validation messages differ from english', function () {
    expect(trans('validation.required', ['attribute' => 'x'], 'my'))
        ->not->toBe(trans('validation.required', ['attribute' => 'x'], 'en'));
});

test('a missing burmese message falls back to english', function () {
    expect(trans('validation.accepted_if', ['attribute' => 'a', 'other' => 'b', 'value' => 'c'], 'my'))
        ->toBe(trans('validation.accepted_if', ['attribute' => 'a', 'other' => 'b', 'value' => 'c'], 'en'));
});
