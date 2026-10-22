<?php

namespace App\Services\Sms;

use App\Models\SmsMessage;
use PhpSmpp\SMPP;

/**
 * Applies SMPP delivery receipts to the SMS log.
 */
class DeliveryReceiptRecorder
{
    /**
     * Record a receipt for the SMSC message id. Returns false when no logged message matches.
     *
     * Some SMSCs return the id in hex from submit_sm but in decimal in the receipt (or the reverse), so both
     * forms are tried.
     */
    public function record(string $providerMessageId, int $state): bool
    {
        $message = SmsMessage::query()
            ->where('provider', SmsSender::PROVIDER)
            ->whereIn('provider_message_id', $this->candidateIds($providerMessageId))
            ->latest('id')
            ->first();

        if ($message === null) {
            return false;
        }

        if ($state === SMPP::STATE_DELIVERED) {
            $message->update(['status' => SmsMessage::STATUS_DELIVERED, 'delivered_at' => now(), 'error' => null]);
        } elseif (in_array($state, [SMPP::STATE_UNDELIVERABLE, SMPP::STATE_EXPIRED, SMPP::STATE_REJECTED], true)) {
            $message->update(['status' => SmsMessage::STATUS_UNDELIVERED, 'error' => $this->stateName($state)]);
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function candidateIds(string $id): array
    {
        $candidates = [$id, ltrim($id, '0') ?: '0'];

        if (ctype_digit($id)) {
            $candidates[] = strtolower(dechex((int) $id));
            $candidates[] = strtoupper(dechex((int) $id));
        }

        if (ctype_xdigit($id) && strlen($id) <= 15) {
            $candidates[] = (string) hexdec($id);
        }

        return array_values(array_unique($candidates));
    }

    private function stateName(int $state): string
    {
        return match ($state) {
            SMPP::STATE_EXPIRED => 'Expired before delivery.',
            SMPP::STATE_REJECTED => 'Rejected by the network.',
            default => 'Undeliverable.',
        };
    }
}
