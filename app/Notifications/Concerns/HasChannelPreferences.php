<?php

namespace App\Notifications\Concerns;

use App\Models\Customer;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Channels\SmsChannel;
use App\Services\NotificationPreferenceService;

/**
 * `database` is unconditional — every notification class using this
 * trait keeps the in-app inbox as its baseline, exactly like Phase 8
 * shipped it. mail/sms/push are opt-in per (recipient, event, channel):
 * NotificationPreferenceService defaults every one of them to OFF, so
 * adding this trait to a notification class never starts emailing,
 * texting, or push-notifying anyone who hasn't explicitly turned that
 * exact combination on.
 *
 * Both `User` (staff) and `Customer` (Phase 10) are supported —
 * NotificationPreferenceService itself decides which owner column and
 * which event list applies to which. Any other notifiable (none
 * currently exist) only ever gets `database`, since preferences and
 * device tokens are both keyed to one of these two models.
 */
trait HasChannelPreferences
{
    protected function channelsFor(object $notifiable, string $eventKey): array
    {
        $channels = ['database'];

        if (! $notifiable instanceof User && ! $notifiable instanceof Customer) {
            return $channels;
        }

        $preferences = app(NotificationPreferenceService::class);

        if ($preferences->isEnabled($notifiable, $eventKey, 'mail')) {
            $channels[] = 'mail';
        }
        if ($preferences->isEnabled($notifiable, $eventKey, 'sms')) {
            $channels[] = SmsChannel::class;
        }
        if ($preferences->isEnabled($notifiable, $eventKey, 'push')) {
            $channels[] = PushChannel::class;
        }

        return $channels;
    }
}
