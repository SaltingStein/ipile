<?php

namespace App\Services\Sms;

/**
 * Normalises phone numbers to the international digits-only form SMSCs expect (e.g. 2348031234567).
 */
final class PhoneNumber
{
    /**
     * Accepts "+234 803 123 4567", "2348031234567" or a local "08031234567" (using the default country
     * code). Returns null when the result is not a plausible E.164 number (8–15 digits).
     */
    public static function normalize(string $number, string $defaultCountryCode = '234'): ?string
    {
        $hasPlus = str_starts_with(trim($number), '+');
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if (! $hasPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (! $hasPlus && str_starts_with($digits, '0')) {
            $digits = preg_replace('/\D+/', '', $defaultCountryCode).substr($digits, 1);
        }

        $length = strlen($digits);

        return $length >= 8 && $length <= 15 && ! str_starts_with($digits, '0') ? $digits : null;
    }
}
