<?php

namespace App\Support\Notifications\Providers;

use App\Models\Notification;
use App\Support\Notifications\NotificationProvider;
use App\Support\Notifications\NotificationSendResult;
use Illuminate\Support\Facades\Log;

/**
 * Testing-only SMS provider (section 19) -- no real network call. See
 * LogEmailProvider's docblock.
 */
class FakeSmsProvider implements NotificationProvider
{
    public function channel(): string
    {
        return 'sms';
    }

    public function send(Notification $notification): NotificationSendResult
    {
        Log::info('notification.sms.sent', [
            'notification_id' => $notification->id,
            'school_id' => $notification->school_id,
            'template_key' => $notification->template_key,
        ]);

        return NotificationSendResult::ok();
    }
}
