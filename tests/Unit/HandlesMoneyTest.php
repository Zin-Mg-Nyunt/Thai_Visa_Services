<?php

use App\Concerns\HandlesMoney;

test('turns a validated amount into an exact two-decimal string', function (mixed $value, string $expected) {
    $converter = new class
    {
        use HandlesMoney;

        public function convert(mixed $value): string
        {
            return $this->money($value);
        }
    };

    expect($converter->convert($value))->toBe($expected);
})->with([
    'whole number' => ['5000', '5000.00'],
    'one decimal' => ['5000.5', '5000.50'],
    'no leading digit' => ['.5', '0.50'],
    'leading zeros' => ['0005.10', '5.10'],
    'integer' => [8000, '8000.00'],
    'empty is zero' => [null, '0.00'],
    'largest fee, no float rounding' => ['9999999999.99', '9999999999.99'],
]);
