<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Notifications\EventNotification;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_sees_every_event_with_its_current_settings(): void
    {
        $this->actingAs($this->manager())->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Notifications')
                ->has('events', count(app(NotificationCatalog::class)->all()))
                ->where('events.0.key', 'auth.welcome')
                ->where('events.0.available_channels', ['mail', 'sms'])
                ->where('events.0.channels', ['mail'])
                ->where('events.0.customised', false)
                ->where('smsEnabled', false)
                ->where('smsSetupUrl', route('admin.integrations.show', 'smpp')));
    }

    public function test_manager_saves_channels_and_wording(): void
    {
        $this->actingAs($this->manager())->put(route('admin.notifications.update', 'payment.succeeded'), [
            'channels' => ['mail', 'sms'],
            'mail_subject' => 'Thanks for {amount}',
            'mail_body' => 'Ref {reference}',
            'sms_body' => 'Paid {amount}',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Payment received notification saved.');

        $config = app(NotificationSettings::class)->config(app(NotificationCatalog::class)->find('payment.succeeded'));
        $this->assertSame(['mail', 'sms'], $config['channels']);
        $this->assertSame('Thanks for {amount}', $config['mail_subject']);
        $this->assertTrue($config['customised']);
    }

    public function test_unknown_placeholders_and_channels_are_rejected(): void
    {
        $this->actingAs($this->manager())->put(route('admin.notifications.update', 'team.invitation'), [
            'channels' => ['mail', 'sms'],
            'mail_subject' => 'Join {tem}',
            'mail_body' => 'Hi',
        ])->assertSessionHasErrors(['channels.1']);

        $this->actingAs($this->manager())->put(route('admin.notifications.update', 'team.invitation'), [
            'channels' => ['mail'],
            'mail_subject' => 'Join {tem}',
            'mail_body' => 'Hi',
        ])->assertSessionHasErrors(['mail_subject' => 'Unknown placeholder: {tem}. Use only the placeholders listed for this event.']);
    }

    public function test_reset_restores_the_defaults(): void
    {
        $event = app(NotificationCatalog::class)->find('payment.failed');
        app(NotificationSettings::class)->update($event, ['channels' => [], 'mail_subject' => 'x', 'mail_body' => 'x', 'sms_body' => 'x']);

        $this->actingAs($this->manager())->delete(route('admin.notifications.destroy', 'payment.failed'))->assertSessionHasNoErrors();

        $config = app(NotificationSettings::class)->config($event);
        $this->assertSame(['mail'], $config['channels']);
        $this->assertSame($event->mailSubject, $config['mail_subject']);
        $this->assertFalse($config['customised']);
    }

    public function test_send_test_delivers_the_event_to_the_admin_with_example_values(): void
    {
        Notification::fake();
        $manager = $this->manager();

        $this->actingAs($manager)->post(route('admin.notifications.test', 'payment.succeeded'))->assertSessionHasNoErrors();

        Notification::assertSentTo($manager, EventNotification::class, fn (EventNotification $notification) => $notification->event === 'payment.succeeded'
            && $notification->data['amount'] === 'NGN 5,000.00');
    }

    public function test_unknown_event_returns_404(): void
    {
        $this->actingAs($this->manager())->put(route('admin.notifications.update', 'nope'), [])->assertNotFound();
    }

    public function test_members_cannot_manage_notifications(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $member = User::factory()->create();

        $this->actingAs($member)->get(route('admin.notifications.index'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.notifications.test', 'auth.welcome'))->assertForbidden();
    }

    private function manager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_settings');
    }
}
