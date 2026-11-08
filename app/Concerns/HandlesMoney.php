<?php

namespace App\Concerns;

/**
 * Money is never a float. An amount from a request ("5000", "5000.5", ".5") becomes an exact decimal string
 * with two decimals ("5000.00", "5000.50", "0.50"), ready for bcmath, the database and the page.
 */
trait HandlesMoney
{
    /**
     * Use it only on a validated amount (numeric, at most two decimals); an empty amount is zero.
     *
     * @return numeric-string
     */
    protected function money(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 2);
    }

    /**
     * Whole satang from a database sum back to baht. Sums are worked out in satang (SUM(ROUND(amount * 100))), which is
     * exact on MySQL and on SQLite. MySQL gives an exact decimal string; SQLite gives a float, but always a whole number
     * of satang, which a float holds exactly far beyond any real total. No part of a baht is ever a float.
     *
     * @return numeric-string
     */
    protected function fromSatang(int|float|string|null $satang): string
    {
        $whole = match (true) {
            $satang === null => '0',
            is_float($satang) => sprintf('%.0f', $satang),
            default => (string) $satang,
        };

        return bcdiv($whole, '100', 2);
    }
}
