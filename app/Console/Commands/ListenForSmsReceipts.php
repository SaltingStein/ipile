<?php

namespace App\Console\Commands;

use App\Services\Integrations\IntegrationManager;
use App\Services\Sms\DeliveryReceiptRecorder;
use App\Services\Sms\SmppTransport;
use App\Services\Sms\SmsException;
use App\Services\Sms\SmsSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PhpSmpp\Client;
use PhpSmpp\Pdu\DeliverReceiptSm;
use PhpSmpp\Pdu\Sm;
use Throwable;

/**
 * Long-running SMPP receiver that records delivery receipts. Run it under a process supervisor
 * (e.g. Supervisor or systemd), like a queue worker.
 */
#[Signature('sms:receipts {--once : Stop after one read cycle (useful for checks)}')]
#[Description('Listen for SMPP delivery receipts and update the SMS log')]
class ListenForSmsReceipts extends Command
{
    private const RECONNECT_DELAY_SECONDS = 15;

    public function handle(IntegrationManager $integrations, SmppTransport $transport, DeliveryReceiptRecorder $recorder): int
    {
        do {
            $config = $integrations->enabledConfig(SmsSender::PROVIDER);

            if ($config === null) {
                $this->error('Telco SMS (SMPP) is not enabled in Admin → Integrations.');

                return self::FAILURE;
            }

            try {
                $client = $transport->connect($config, $config['bind_mode'] === Client::BIND_MODE_TRANSCEIVER ? Client::BIND_MODE_TRANSCEIVER : Client::BIND_MODE_RECEIVER);
            } catch (SmsException $exception) {
                $this->error($exception->getMessage());

                if ($this->option('once')) {
                    return self::FAILURE;
                }

                sleep(self::RECONNECT_DELAY_SECONDS);

                continue;
            }

            $this->info('Listening for delivery receipts…');

            try {
                do {
                    // Returns after the read timeout (10s) when nothing arrives; then keep the session alive.
                    $client->listenSm(function (Sm $sm) use ($recorder): void {
                        if ($sm instanceof DeliverReceiptSm && $sm->msgId !== null) {
                            $matched = $recorder->record((string) $sm->msgId, (int) $sm->state);
                            $this->line(($matched ? 'Recorded' : 'Unmatched')." receipt {$sm->msgId} (state {$sm->state})");
                        }
                    });
                    $client->enquireLink();
                } while (! $this->option('once'));
            } catch (Throwable $exception) {
                $this->warn('SMPP session lost: '.$exception->getMessage().' Reconnecting…');
            } finally {
                $transport->disconnect($client);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
