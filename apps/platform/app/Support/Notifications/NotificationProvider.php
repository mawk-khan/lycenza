<?php

namespace App\Support\Notifications;

use App\Models\Notification;

/**
 * Channel-agnostic provider contract. No provider-specific field lives
 * on the core Notification model (section 17) -- a provider gets
 * whatever it needs from the Notification row's own generic columns
 * (channel, template_key, payload) at send time.
 */
interface NotificationProvider
{
    public function channel(): string;

    public function send(Notification $notification): NotificationSendResult;
}
