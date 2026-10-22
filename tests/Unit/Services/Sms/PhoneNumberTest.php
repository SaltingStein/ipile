<?php

namespace Tests\Unit\Services\Sms;

use App\Services\Sms\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function numbers(): array
    {
        return [
            'local Nigerian mobile' => ['08031234567', '2348031234567'],
            'international with plus and spaces' => ['+234 803 123 4567', '2348031234567'],
            'international digits only' => ['2348031234567', '2348031234567'],
            'international with 00 prefix' => ['002348031234567', '2348031234567'],
            'dashes and brackets' => ['(0803) 123-4567', '2348031234567'],
            'too short' => ['12345', null],
            'too long' => ['+1234567890123456', null],
            'letters only' => ['call-me', null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_normalises_phone_numbers(string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    public function test_uses_the_given_default_country_code_for_local_numbers(): void
    {
        $this->assertSame('233241234567', PhoneNumber::normalize('0241234567', '233'));
    }
}
