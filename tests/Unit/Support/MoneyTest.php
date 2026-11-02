<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{string|int|float, int}>
     */
    public static function exactAmounts(): array
    {
        return [
            'whole string' => ['5000', 500000],
            'one decimal' => ['5000.5', 500050],
            'two decimals' => ['5000.50', 500050],
            'trailing zeros beyond kobo' => ['5000.5000', 500050],
            'integer major units' => [5000, 500000],
            'zero' => ['0.00', 0],
            'one kobo' => ['0.01', 1],
            'negative (refunds)' => ['-12.34', -1234],
            'surrounding whitespace' => [' 19.99 ', 1999],
            // Floats that multiply badly: 19.99 * 100 is 1998.9999999999998, 0.29 * 100 is 28.999999999999996.
            'float 19.99' => [19.99, 1999],
            'float 0.29' => [0.29, 29],
            'float 5000.55' => [5000.55, 500055],
        ];
    }

    #[DataProvider('exactAmounts')]
    public function test_converts_amounts_to_exact_minor_units(string|int|float $amount, int $expected): void
    {
        $this->assertSame($expected, Money::toMinor($amount));
    }

    /**
     * @return array<string, array{string|float}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'fraction of a kobo' => ['5000.004'],
            'float fraction of a kobo' => [10.005],
            'not a number' => ['abc'],
            'thousands separator' => ['5,000.00'],
            'exponent' => ['5e3'],
            'empty' => [''],
            'infinite' => [INF],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_amounts_it_cannot_represent_exactly(string|float $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toMinor($amount);
    }

    public function test_converts_minor_units_back_to_a_two_decimal_string(): void
    {
        $this->assertSame('5000.50', Money::fromMinor(500050));
        $this->assertSame('0.01', Money::fromMinor(1));
        $this->assertSame('0.00', Money::fromMinor(0));
        $this->assertSame('-12.34', Money::fromMinor(-1234));
    }

    public function test_round_trips_without_drift(): void
    {
        foreach ([0, 1, 99, 100, 1999, 500050, 999_999_999_99] as $minor) {
            $this->assertSame($minor, Money::toMinor(Money::fromMinor($minor)));
        }
    }

    public function test_formats_for_display_with_thousands_separators(): void
    {
        $this->assertSame('5,000.00', Money::format(500000));
        $this->assertSame('1,234,567.89', Money::format(123456789));
        $this->assertSame('-0.05', Money::format(-5));
    }
}
