<?php

namespace App\Services\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Sends one email through the ZeptoMail REST API (`POST https://{host}/v1.1/email`).
 *
 * The sender is always the From address saved in Admin → Integrations, because ZeptoMail only
 * accepts addresses on verified domains. Reply-To from the message is kept.
 */
class ZeptoMailClient
{
    private const TIMEOUT_SECONDS = 15;

    /**
     * @param  array{api_token: string, api_host: string, from_address: string, from_name: string}  $config
     * @return string|null ZeptoMail's request id
     *
     * @throws ZeptoMailException
     */
    public function send(array $config, Email $email): ?string
    {
        try {
            $response = Http::withHeaders(['Authorization' => 'Zoho-enczapikey '.$config['api_token']])
                ->acceptJson()
                ->asJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post("https://{$config['api_host']}/v1.1/email", $this->payload($config, $email));
        } catch (ConnectionException) {
            throw new ZeptoMailException('Could not reach ZeptoMail at '.$config['api_host'].'.');
        }

        if ($response->failed()) {
            $reason = $response->json('data.message') ?? $response->json('error.message') ?? $response->json('message') ?? 'HTTP '.$response->status();
            $code = $response->json('data.error_code') ?? $response->json('error.code');

            throw new ZeptoMailException(trim('ZeptoMail rejected the email: '.$reason.($code ? " ({$code})" : '')));
        }

        return $response->json('request_id');
    }

    /**
     * @param  array{from_address: string, from_name: string}  $config
     * @return array<string, mixed>
     */
    private function payload(array $config, Email $email): array
    {
        $payload = [
            'from' => ['address' => $config['from_address'], 'name' => $config['from_name']],
            'to' => $this->recipients($email->getTo()),
            'subject' => (string) $email->getSubject(),
        ];

        if ($email->getCc() !== []) {
            $payload['cc'] = $this->recipients($email->getCc());
        }

        if ($email->getBcc() !== []) {
            $payload['bcc'] = $this->recipients($email->getBcc());
        }

        if ($email->getReplyTo() !== []) {
            $payload['reply_to'] = array_map(fn (Address $address) => array_filter([
                'address' => $address->getAddress(),
                'name' => $address->getName(),
            ]), $email->getReplyTo());
        }

        if (($html = $this->bodyAsString($email->getHtmlBody())) !== null) {
            $payload['htmlbody'] = $html;
        }

        if (($text = $this->bodyAsString($email->getTextBody())) !== null) {
            $payload['textbody'] = $text;
        }

        foreach ($email->getAttachments() as $attachment) {
            $part = [
                'content' => base64_encode($attachment->getBody()),
                'mime_type' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
                'name' => $attachment->getFilename() ?? 'attachment',
            ];

            if ($this->isInline($attachment)) {
                $payload['inline_images'][] = [...$part, 'cid' => $attachment->getContentId()];
            } else {
                $payload['attachments'][] = $part;
            }
        }

        return $payload;
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<array{email_address: array{address: string, name?: string}}>
     */
    private function recipients(array $addresses): array
    {
        return array_map(fn (Address $address) => [
            'email_address' => array_filter(['address' => $address->getAddress(), 'name' => $address->getName()]),
        ], $addresses);
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
