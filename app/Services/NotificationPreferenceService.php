<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\NotificationPreference;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The single source of truth for "should this recipient get this event
 * on this channel" — read by every notification class's via() (through
 * the HasChannelPreferences trait) and by NotificationPreferenceController's
 * own GET, so the API always reflects exactly what dispatch will do.
 *
 * Typed against `Authenticatable` rather than `User` specifically since
 * Phase 10 lets a `Customer` use the exact same service (and the exact
 * same controller — see NotificationPreferenceController) as staff:
 * `ownerColumn()` is the one place that decides whether a row is
 * addressed by `user_id` or `customer_id`.
 *
 * `database` isn't represented here at all: it's the one channel every
 * notification always uses, matching Phase 8's original behavior, so
 * there's nothing to opt into or out of for it. mail/sms/push all
 * default OFF — a preference row has to exist and be enabled=true
 * before any of those actually fires, so turning this on for an
 * existing restaurant/customer never surprises anyone with a real
 * email/text/push they never asked for.
 *
 * Staff and customers see different event lists — a customer has no
 * reason to configure `order.rider_assigned`/`payment.received`, which
 * are never sent to one — see `eventsFor()`.
 */
class NotificationPreferenceService
{
    public const STAFF_EVENTS = ['order.placed', 'order.status_changed', 'order.rider_assigned', 'payment.received'];

    public const CUSTOMER_EVENTS = ['order.placed', 'order.status_changed'];

    public const CHANNELS = ['mail', 'sms', 'push'];

    /** @return array<int, string> */
    public static function eventsFor(Authenticatable $notifiable): array
    {
        return $notifiable instanceof Customer ? self::CUSTOMER_EVENTS : self::STAFF_EVENTS;
    }

    private static function ownerColumn(Authenticatable $notifiable): string
    {
        return $notifiable instanceof Customer ? 'customer_id' : 'user_id';
    }

    public function isEnabled(Authenticatable $notifiable, string $eventKey, string $channel): bool
    {
        return (bool) NotificationPreference::where(self::ownerColumn($notifiable), $notifiable->getAuthIdentifier())
            ->where('event_key', $eventKey)
            ->where('channel', $channel)
            ->value('enabled');
    }

    /** @return array<string, array<string, bool>> event_key => [channel => enabled] */
    public function effectivePreferences(Authenticatable $notifiable): array
    {
        $rows = NotificationPreference::where(self::ownerColumn($notifiable), $notifiable->getAuthIdentifier())
            ->get()
            ->keyBy(fn (NotificationPreference $row) => "{$row->event_key}:{$row->channel}");

        $matrix = [];
        foreach (self::eventsFor($notifiable) as $event) {
            foreach (self::CHANNELS as $channel) {
                $matrix[$event][$channel] = (bool) ($rows->get("{$event}:{$channel}")?->enabled ?? false);
            }
        }

        return $matrix;
    }

    /** @param array<int, array{event_key: string, channel: string, enabled: bool}> $updates */
    public function setMany(Authenticatable $notifiable, array $updates): void
    {
        $column = self::ownerColumn($notifiable);
        $allowedEvents = self::eventsFor($notifiable);

        foreach ($updates as $update) {
            if (! in_array($update['event_key'], $allowedEvents, true)) {
                continue; // the controller already validates this; defensive here too
            }

            NotificationPreference::updateOrCreate(
                [$column => $notifiable->getAuthIdentifier(), 'event_key' => $update['event_key'], 'channel' => $update['channel']],
                ['enabled' => (bool) $update['enabled']],
            );
        }
    }
}
