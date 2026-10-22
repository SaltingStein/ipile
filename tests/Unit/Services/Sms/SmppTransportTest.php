<?php

namespace Tests\Unit\Services\Sms;

use App\Services\Sms\SmppTransport;
use PhpSmpp\SMPP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Message encoding: GSM 03.38 when every character fits, otherwise UCS-2.
 */
class SmppTransportTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function gsmTexts(): array
    {
        return [
            'plain English' => ['Your code is 123456'],
            'GSM symbols' => ['Pay £5 @ store_1 {now} [ok] ~ € ^ | \\'],
            'line breaks' => ["Line one\nLine two"],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unicodeTexts(): array
    {
        return [
            'naira sign' => ['You paid ₦5,000'],
            'Yoruba diacritics' => ['Ẹ ṣé o'],
            'Igbo diacritics' => ['Ọ dị mma'],
            'emoji' => ['Welcome 🎉'],
            'backtick (not in GSM)' => ['use `code`'],
        ];
    }

    #[DataProvider('gsmTexts')]
    public function test_gsm_text_uses_the_default_alphabet(string $text): void
    {
        [, $dataCoding] = SmppTransport::encode($text);

        $this->assertSame(SMPP::DATA_CODING_DEFAULT, $dataCoding);
    }

    #[DataProvider('unicodeTexts')]
    public function test_text_outside_gsm_is_sent_as_utf16_without_losing_characters(string $text): void
    {
        [$encoded, $dataCoding] = SmppTransport::encode($text);

        $this->assertSame(SMPP::DATA_CODING_UCS2, $dataCoding);
        $this->assertSame($text, mb_convert_encoding($encoded, 'UTF-8', 'UTF-16BE'));
    }

    public function test_gsm_specials_are_mapped_to_gsm_codes(): void
    {
        [$encoded] = SmppTransport::encode('@£€');

        $this->assertSame("\x00\x01\x1B\x65", $encoded);
    }
}
