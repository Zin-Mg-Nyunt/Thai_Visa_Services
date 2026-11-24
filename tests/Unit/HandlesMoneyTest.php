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

test('an amount that is not a number raises a ValueError instead of being counted', function (mixed $value) {
    $converter = new class
    {
        use HandlesMoney;

        public function convert(mixed $value): string
        {
            return $this->money($value);
        }
    };

    expect(fn () => $converter->convert($value))->toThrow(ValueError::class);
})->with(['empty text' => [''], 'words' => ['abc'], 'exponent' => ['1e3']]);

test('whole satang become baht with two decimals, from an int, a float, a string or nothing', function (int|float|string|null $satang, string $expected) {
    $converter = new class
    {
        use HandlesMoney;

        public function convert(int|float|string|null $satang): string
        {
            return $this->fromSatang($satang);
        }
    };

    expect($converter->convert($satang))->toBe($expected);
})->with([
    'int' => [350000, '3500.00'],
    'float, as SQLite sums' => [350000.0, '3500.00'],
    'string, as MySQL sums' => ['400000', '4000.00'],
    'decimal string' => ['400050.0000', '4000.50'],
    'nothing' => [null, '0.00'],
]);

test('a sum that is not a number raises a ValueError', function () {
    $converter = new class
    {
        use HandlesMoney;

        public function convert(string $satang): string
        {
            return $this->fromSatang($satang);
        }
    };

    expect(fn () => $converter->convert('abc'))->toThrow(ValueError::class);
});
