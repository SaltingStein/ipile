<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact conversion between decimal money strings and integer minor units
 * (kobo, cents). Money is stored and compared as integers; floats are only
 * accepted at a provider boundary and normalised immediately.
 *
 * Conversion never rounds silently: a value with a real fraction of a minor
 * unit ("10.005") is rejected rather than nudged to the nearest kobo.
 */
final class Money
{
    /**
     * "5000.50" (or 5000, or a provider's 5000.5) to 500050.
     *
     * @throws InvalidArgumentException when the value is not an exact amount of minor units
     */
    public static function toMinor(string|int|float $amount): int
    {
        if (is_int($amount)) {
            return $amount * 100;
        }

        if (is_float($amount)) {
            if (! is_finite($amount)) {
                throw new InvalidArgumentException('Money amount must be a finite number.');
            }
            // Ten places absorbs binary noise (5000.55 is 5000.5500000000001819...)
            // while still exposing a genuine third decimal.
            $amount = sprintf('%.10F', $amount);
        }

        $amount = trim($amount);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $parts)) {
            throw new InvalidArgumentException("Not a money amount: [{$amount}].");
        }

        [, $sign, $whole, $fraction] = array_pad($parts, 4, '');

        if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
            throw new InvalidArgumentException("Money amount [{$amount}] has more than two decimal places.");
        }

        $minor = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $sign === '-' ? -$minor : $minor;
    }

    /**
     * 500050 to "5000.50".
     */
    public static function fromMinor(int $minor): string
    {
        $absolute = abs($minor);

        return sprintf('%s%d.%02d', $minor < 0 ? '-' : '', intdiv($absolute, 100), $absolute % 100);
    }

    /**
     * 500050 to "5,000.50", for display in messages and receipts.
     */
    public static function format(int $minor): string
    {
        $absolute = abs($minor);

        return ($minor < 0 ? '-' : '').number_format(intdiv($absolute, 100)).sprintf('.%02d', $absolute % 100);
    }
}
