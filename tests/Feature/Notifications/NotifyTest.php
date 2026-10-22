<?php

namespace Tests\Feature\Notifications;

use App\Models\Integration;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\EventNotification;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\Notify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class NotifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_the_event_to_a_user_on_the_default_channels(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        Notify::event('payment.succeeded')->to($user)->with(['amount' => 'NGN 5,000.00', 'reference' => 'PAY-1'])->send();

        Notification::assertSentTo($user, EventNotification::class, fn (EventNotification $notification, array $channels) => $notification->event === 'payment.succeeded'
            && $notification->data['reference'] === 'PAY-1'
            && $channels === ['mail']);
    }

    public function test_sends_to_bare_email_and_phone_routes(): void
    {
        Notification::fake();

        Notify::event('auth.welcome')->toEmail('ada@example.com')->toPhone('08031234567')->send();

        Notification::assertSentOnDemand(EventNotification::class, fn (EventNotification $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes === [
            'mail' => 'ada@example.com',
            SmsChannel::class => '08031234567',
        ]);
    }

    public function test_unknown_event_fails_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Notify::event('payment.succeded');
    }

    public function test_sms_is_used_only_when_switched_on_and_the_sms_integration_is_enabled(): void
    {
        $event = app(NotificationCatalog::class)->find('payment.succeeded');
        $settings = app(NotificationSettings::class);
        $settings->update($event, ['channels' => ['mail', 'sms'], 'mail_subject' => 'Paid', 'mail_body' => 'Paid', 'sms_body' => 'Paid']);
        $notification = new EventNotification('payment.succeeded');

        $this->assertSame(['mail'], $notification->via(new AnonymousNotifiable));

        $this->enableSmpp();

        $this->assertSame(['mail', SmsChannel::class], $notification->via(new AnonymousNotifiable));
    }

    public function test_required_events_always_send_email(): void
    {
        $event = app(NotificationCatalog::class)->find('team.invitation');
        app(NotificationSettings::class)->update($event, ['channels' => [], 'mail_subject' => 'Join', 'mail_body' => 'Join']);

        $this->assertSame(['mail'], (new EventNotification('team.invitation'))->via(new AnonymousNotifiable));
    }

    public function test_switched_off_events_are_not_delivered(): void
    {
        $event = app(NotificationCatalog::class)->find('payment.failed');
        app(NotificationSettings::class)->update($event, ['channels' => [], 'mail_subject' => 'x', 'mail_body' => 'x', 'sms_body' => 'x']);

        $this->assertSame([], (new EventNotification('payment.failed'))->via(new AnonymousNotifiable));
    }

    public function test_email_uses_the_saved_template_with_placeholders_filled_and_escaped(): void
    {
        $event = app(NotificationCatalog::class)->find('payment.succeeded');
        app(NotificationSettings::class)->update($event, [
            'channels' => ['mail'],
            'mail_subject' => '{name}, we got {amount}',
            'mail_body' => "Thanks for paying {amount}.\n\nRef: {reference}",
            'sms_body' => 'Paid',
        ]);
        $user = User::factory()->make(['name' => '<b>Ada</b>']);

        $mail = (new EventNotification('payment.succeeded', ['amount' => 'NGN 5,000.00', 'reference' => 'PAY-1']))->toMail($user);
        $html = (string) $mail->render();

        $this->assertSame('<b>Ada</b>, we got NGN 5,000.00', $mail->subject);
        $this->assertSame(['Thanks for paying NGN 5,000.00.', 'Ref: PAY-1'], $mail->introLines);
        $this->assertStringContainsString('&lt;b&gt;Ada&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Ada</b>', $html);
    }

    public function test_email_button_opens_the_link_placeholder(): void
    {
        $mail = (new EventNotification('auth.welcome', ['link' => 'https://example.com/dashboard']))->toMail(new AnonymousNotifiable);

        $this->assertSame('Open your dashboard', $mail->actionText);
        $this->assertSame('https://example.com/dashboard', $mail->actionUrl);
    }

    public function test_sms_text_uses_the_saved_template(): void
    {
        $sms = (new EventNotification('payment.failed', ['amount' => 'NGN 100.00', 'reference' => 'PAY-9']))->toSms(new AnonymousNotifiable);

        $this->assertSame('Ìpìlẹ̀: payment of NGN 100.00 failed. Ref PAY-9.', $sms);
    }

    public function test_a_delivery_failure_is_reported_instead_of_thrown(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Mail server down'));
        $reported = [];
        $this->app->make('Illuminate\Contracts\Debug\ExceptionHandler')->reportable(function (RuntimeException $exception) use (&$reported) {
            $reported[] = $exception->getMessage();

            return false;
        });

        Notify::event('auth.welcome')->to(User::factory()->create())->send();

        $this->assertSame(['Mail server down'], $reported);
    }

    private function enableSmpp(): void
    {
        $integration = new Integration(['provider' => 'smpp', 'type' => 'sms', 'is_enabled' => true]);
        $integration->config = [
            'host' => 'smpp.example.net', 'port' => 2775, 'system_id' => 'ipile', 'password' => 'secret',
            'system_type' => '', 'source_address' => 'Ipile', 'bind_mode' => 'transmitter',
        ];
        $integration->save();
    }
}
