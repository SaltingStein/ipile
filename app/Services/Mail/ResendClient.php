<?php

namespace App\Services\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Sends one email through the Resend REST API (`POST https://api.resend.com/emails`).
 *
 * The sender is always the From address saved in Admin → Integrations, because Resend only accepts
 * addresses on verified domains. Reply-To from the message is kept.
 */
class ResendClient
{
    public const ENDPOINT = 'https://api.resend.com/emails';

    private const TIMEOUT_SECONDS = 15;

    /**
     * @param  array{api_key: string, from_address: string, from_name: ?string}  $config
     * @return string|null Resend's email id
     *
     * @throws ResendException
     */
    public function send(array $config, Email $email): ?string
    {
        try {
            $response = Http::withToken($config['api_key'])
                ->acceptJson()
                ->asJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::ENDPOINT, $this->payload($config, $email));
        } catch (ConnectionException) {
            throw new ResendException('Could not reach Resend.');
        }

        if ($response->failed()) {
            $reason = $response->json('message') ?? 'HTTP '.$response->status();
            $name = $response->json('name');

            throw new ResendException(trim('Resend rejected the email: '.$reason.($name ? " ({$name})" : '')));
        }

        return $response->json('id');
    }

    /**
     * @param  array{from_address: string, from_name: ?string}  $config
     * @return array<string, mixed>
     */
    private function payload(array $config, Email $email): array
    {
        $payload = [
            'from' => $this->format(new Address($config['from_address'], (string) ($config['from_name'] ?? ''))),
            'to' => array_map($this->format(...), $email->getTo()),
            'subject' => (string) $email->getSubject(),
        ];

        foreach (['cc' => $email->getCc(), 'bcc' => $email->getBcc(), 'reply_to' => $email->getReplyTo()] as $key => $addresses) {
            if ($addresses !== []) {
                $payload[$key] = array_map($this->format(...), $addresses);
            }
        }

        if (($html = $this->bodyAsString($email->getHtmlBody())) !== null) {
            $payload['html'] = $html;
        }

        if (($text = $this->bodyAsString($email->getTextBody())) !== null) {
            $payload['text'] = $text;
        }

        foreach ($email->getAttachments() as $attachment) {
            $payload['attachments'][] = array_filter([
                'filename' => $attachment->getFilename() ?? 'attachment',
                'content' => base64_encode($attachment->getBody()),
                'content_type' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
                'content_id' => $this->isInline($attachment) ? $attachment->getContentId() : null,
            ]);
        }

        return $payload;
    }

    /**
     * `Name <address>`, with the name quoted when it contains characters that would break the header.
     */
    private function format(Address $address): string
    {
        $name = $address->getName();

        if ($name === '') {
            return $address->getAddress();
        }

        if (preg_match('/[,;:<>@()\[\]"\\\\]/', $name) === 1) {
            $name = '"'.addcslashes($name, '"\\').'"';
        }

        return "{$name} <{$address->getAddress()}>";
    }

    /**
     * @param  resource|string|null  $body
     */
    private function bodyAsString(mixed $body): ?string
    {
        if (is_resource($body)) {
            return stream_get_contents($body) ?: null;
        }

        return $body === null || $body === '' ? null : $body;
    }

    private function isInline(DataPart $part): bool
    {
        return $part->getPreparedHeaders()->getHeaderBody('Content-Disposition') === 'inline';
    }
}
