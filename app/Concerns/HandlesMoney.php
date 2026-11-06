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
}
