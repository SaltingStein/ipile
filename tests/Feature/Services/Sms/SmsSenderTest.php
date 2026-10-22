<?php

namespace Tests\Feature\Services\Sms;

use App\Jobs\SendSmsJob;
use App\Models\Integration;
use App\Models\SmsMessage;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Services\Sms\DeliveryReceiptRecorder;
use App\Services\Sms\SmsException;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsTransport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Mockery\MockInterface;
use PhpSmpp\SMPP;
use Tests\TestCase;

/**
 * Covers SmsSender, SendSmsJob, SmsChannel, DeliveryReceiptRecorder and SmppTester. The SMPP network layer
 * (SmsTransport) is mocked because tests cannot reach a real SMSC.
 */
class SmsSenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_queueing_logs_the_message_with_a_normalised_number_and_dispatches_the_job(): void
    {
        $this->enableSmpp();
        Queue::fake([SendSmsJob::class]);

        $message = app(SmsSender::class)->queue('0803 123 4567', 'Your code is 123456');

        $this->assertDatabaseHas('sms_messages', [
            'id' => $message->id,
            'to' => '2348031234567',
            'sender' => 'Ipile',
            'status' => SmsMessage::STATUS_QUEUED,
        ]);
        Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job) => $job->message->is($message));
    }

    public function test_queueing_is_refused_while_smpp_is_disabled(): void
    {
        $this->expectException(SmsException::class);
        $this->expectExceptionMessage('SMS is not enabled.');

        app(SmsSender::class)->queue('08031234567', 'Hello');
    }

    public function test_invalid_numbers_are_refused(): void
    {
        $this->enableSmpp();

        $this->expectException(InvalidArgumentException::class);

        app(SmsSender::class)->queue('12', 'Hello');
    }

    public function test_job_sends_through_the_transport_and_records_the_message_id(): void
    {
        $this->enableSmpp();
        $message = SmsMessage::factory()->create(['to' => '2348031234567', 'body' => 'Hello']);
        $this->mock(SmsTransport::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(fn (array $config, string $to, string $text) => $config['host'] === 'smpp.example.net' && $to === '2348031234567' && $text === 'Hello')
                ->andReturn('msg-123');
        });

        dispatch_sync(new SendSmsJob($message));

        $message->refresh();
        $this->assertSame(SmsMessage::STATUS_SENT, $message->status);
        $this->assertSame('msg-123', $message->provider_message_id);
        $this->assertNotNull($message->sent_at);
    }

    public function test_job_records_the_error_and_rethrows_so_the_queue_retries(): void
    {
        $this->enableSmpp();
        $message = SmsMessage::factory()->create();
        $this->mock(SmsTransport::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->andThrow(new SmsException('Could not connect to the SMSC at smpp.example.net: timeout'));
        });

        try {
            app()->call([new SendSmsJob($message), 'handle']);
            $this->fail('The job should rethrow the transport error.');
        } catch (SmsException) {
        }

        $message->refresh();
        $this->assertSame(SmsMessage::STATUS_QUEUED, $message->status);
        $this->assertSame('Could not connect to the SMSC at smpp.example.net: timeout', $message->error);
    }

    public function test_job_marks_the_message_failed_once_retries_are_exhausted(): void
    {
        $message = SmsMessage::factory()->create();

        (new SendSmsJob($message))->failed(new SmsException('Bind failed'));

        $this->assertSame(SmsMessage::STATUS_FAILED, $message->fresh()->status);
        $this->assertSame('Bind failed', $message->fresh()->error);
    }

    public function test_job_never_sends_a_message_twice(): void
    {
        $this->enableSmpp();
        $message = SmsMessage::factory()->sent()->create();
        $this->mock(SmsTransport::class, fn (MockInterface $mock) => $mock->shouldNotReceive('send'));

        dispatch_sync(new SendSmsJob($message));

        $this->assertSame(SmsMessage::STATUS_SENT, $message->fresh()->status);
    }

    public function test_notification_channel_queues_an_sms_to_the_users_phone(): void
    {
        $this->enableSmpp();
        Queue::fake([SendSmsJob::class]);
        $user = User::factory()->create(['phone_number' => '08031234567']);

        $user->notify(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [SmsChannel::class];
            }

            public function toSms(object $notifiable): string
            {
                return 'Your order shipped.';
            }
        });

        $this->assertDatabaseHas('sms_messages', ['user_id' => $user->id, 'to' => '2348031234567', 'body' => 'Your order shipped.']);
        Queue::assertPushed(SendSmsJob::class);
    }

    public function test_on_demand_notifications_can_text_any_number(): void
    {
        $this->enableSmpp();
        Queue::fake([SendSmsJob::class]);

        NotificationFacade::route(SmsChannel::class, '+2348099999999')->notify(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [SmsChannel::class];
            }

            public function toSms(object $notifiable): string
            {
                return 'Hi';
            }
        });

        $this->assertDatabaseHas('sms_messages', ['user_id' => null, 'to' => '2348099999999']);
    }

    public function test_delivery_receipt_marks_the_message_delivered(): void
    {
        $message = SmsMessage::factory()->sent('msg-123')->create();

        $matched = app(DeliveryReceiptRecorder::class)->record('msg-123', SMPP::STATE_DELIVERED);

        $this->assertTrue($matched);
        $this->assertSame(SmsMessage::STATUS_DELIVERED, $message->fresh()->status);
        $this->assertNotNull($message->fresh()->delivered_at);
    }

    public function test_receipt_in_decimal_matches_a_hex_message_id(): void
    {
        $message = SmsMessage::factory()->sent('1a2b3c')->create();

        app(DeliveryReceiptRecorder::class)->record((string) hexdec('1a2b3c'), SMPP::STATE_UNDELIVERABLE);

        $this->assertSame(SmsMessage::STATUS_UNDELIVERED, $message->fresh()->status);
        $this->assertSame('Undeliverable.', $message->fresh()->error);
    }

    public function test_unknown_receipt_is_reported_as_unmatched(): void
    {
        $this->assertFalse(app(DeliveryReceiptRecorder::class)->record('not-ours', SMPP::STATE_DELIVERED));
    }

    public function test_connection_test_binds_without_sending(): void
    {
        $this->enableSmpp();
        $this->mock(SmsTransport::class, function (MockInterface $mock): void {
            $mock->shouldReceive('checkConnection')->once();
            $mock->shouldNotReceive('send');
        });

        $this->actingAs($this->integrationsManager())
            ->post(route('admin.integrations.test', 'smpp'))
            ->assertSessionHas('status', 'Connected and bound to smpp.example.net as ipile.');
    }

    public function test_connection_test_reports_a_bind_failure(): void
    {
        $this->enableSmpp();
        $this->mock(SmsTransport::class, function (MockInterface $mock): void {
            $mock->shouldReceive('checkConnection')->andThrow(new SmsException('Could not connect to the SMSC at smpp.example.net: Bind Failed'));
        });

        $this->actingAs($this->integrationsManager())
            ->post(route('admin.integrations.test', 'smpp'))
            ->assertSessionHasErrors(['smpp.test' => 'Could not connect to the SMSC at smpp.example.net: Bind Failed']);
    }

    private function enableSmpp(): void
    {
        $integration = new Integration(['provider' => 'smpp', 'type' => 'sms', 'is_enabled' => true]);
        $integration->config = [
            'host' => 'smpp.example.net',
            'port' => 2775,
            'system_id' => 'ipile',
            'password' => 'secret',
            'system_type' => '',
            'source_address' => 'Ipile',
            'bind_mode' => 'transmitter',
        ];
        $integration->save();
    }

    private function integrationsManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_integrations');
    }
}
