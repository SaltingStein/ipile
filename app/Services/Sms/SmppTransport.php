<?php

namespace App\Services\Sms;

use PhpSmpp\Client;
use PhpSmpp\Encoder\GsmEncoder;
use PhpSmpp\Pdu\Part\Address;
use PhpSmpp\SMPP;
use Throwable;

/**
 * Sends SMS over SMPP v3.4 using glushkovds/php-smpp (needs the `sockets` extension; the library does not
 * officially support Windows, so send real SMS from a Linux server).
 *
 * Each send opens a session, binds, submits and unbinds; the queued job handles retries. Delivery receipts
 * are requested and collected by `php artisan sms:receipts`.
 */
class SmppTransport implements SmsTransport
{
    private const CONNECT_TIMEOUT_MS = 10000;

    /**
     * GSM 03.38 characters the library encodes correctly. Anything else is sent as UCS-2, so ₦, emoji and
     * Nigerian-language diacritics (ọ, ẹ, ṣ) arrive intact. (The library's own check only detects Cyrillic.)
     * Very long messages containing emoji may split an emoji across message parts; keep such texts short.
     */
    private const GSM_CHARACTERS = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà^{}\\[~]|€";

    public function send(array $config, string $to, string $text): string
    {
        $client = $this->connect($config);

        try {
            [$message, $dataCoding] = $this->encode($text);

            $messageId = $client->sendSMS(
                $this->senderAddress((string) $config['source_address']),
                new Address($to, SMPP::TON_INTERNATIONAL, SMPP::NPI_E164),
                $message,
                null,
                $dataCoding,
            );
        } catch (Throwable $exception) {
            throw new SmsException('The SMSC refused the message: '.$exception->getMessage(), 0, $exception);
        } finally {
            $this->disconnect($client);
        }

        if (! is_string($messageId) || $messageId === '') {
            throw new SmsException('The SMSC did not return a message id.');
        }

        return $messageId;
    }

    public function checkConnection(array $config): void
    {
        $this->disconnect($this->connect($config));
    }

    /**
     * Open a socket and bind with the dashboard credentials.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws SmsException
     */
    public function connect(array $config, ?string $bindMode = null): Client
    {
        // The library keeps bind parameters in static properties; set them for this session.
        Client::$system_type = (string) ($config['system_type'] ?? '');

        $client = new Client([$config['host'].':'.(int) ($config['port'] ?? 2775)]);
        // The library's debug output can include the bind PDU (with the password), so never log it.
        $client->setDebugHandler(static fn () => null);

        try {
            $client->getTransport()->setSendTimeout(self::CONNECT_TIMEOUT_MS);
            $client->getTransport()->open();

            match ($bindMode ?? $config['bind_mode'] ?? Client::BIND_MODE_TRANSMITTER) {
                Client::BIND_MODE_TRANSCEIVER => $client->bindTransceiver($config['system_id'], $config['password']),
                Client::BIND_MODE_RECEIVER => $client->bindReceiver($config['system_id'], $config['password']),
                default => $client->bindTransmitter($config['system_id'], $config['password']),
            };
        } catch (Throwable $exception) {
            $this->disconnect($client);

            throw new SmsException('Could not connect to the SMSC at '.$config['host'].': '.$exception->getMessage(), 0, $exception);
        }

        return $client;
    }

    public function disconnect(Client $client): void
    {
        try {
            $client->close();
        } catch (Throwable) {
            // The session is being discarded; a failed unbind changes nothing.
        }
    }

    /**
     * @return array{0: string, 1: int} The encoded message and its SMPP data coding.
     */
    public static function encode(string $text): array
    {
        if (self::isGsm($text)) {
            return [GsmEncoder::utf8_to_gsm0338($text), SMPP::DATA_CODING_DEFAULT];
        }

        // Handsets and SMSCs treat data coding 8 as UTF-16BE, so emoji travel as surrogate pairs.
        return [mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'), SMPP::DATA_CODING_UCS2];
    }

    public static function isGsm(string $text): bool
    {
        foreach (mb_str_split($text) as $character) {
            if (! str_contains(self::GSM_CHARACTERS, $character)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Alphanumeric sender IDs (e.g. "Ipile") versus numeric short codes and long numbers.
     */
    private function senderAddress(string $sender): Address
    {
        if (! ctype_digit($sender)) {
            return new Address($sender, SMPP::TON_ALPHANUMERIC);
        }

        return strlen($sender) <= 8
            ? new Address($sender, SMPP::TON_NATIONAL)
            : new Address($sender, SMPP::TON_INTERNATIONAL, SMPP::NPI_E164);
    }
}
