<?php

namespace App\Support\Notifications\Providers;

use App\Models\Notification;
use App\Support\Notifications\NotificationProvider;
use App\Support\Notifications\NotificationSendResult;

/**
 * The Notification row itself IS the in-app notification -- "sending"
 * this channel has no external effect at all.
 */
class InAppProvider implements NotificationProvider
{
    public function channel(): string
    {
        return 'in_app';
    }

    public function send(Notification $notification): NotificationSendResult
    {
        return NotificationSendResult::ok();
    }
}
