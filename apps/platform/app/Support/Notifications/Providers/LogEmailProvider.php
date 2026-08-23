<?php

namespace App\Support\Notifications\Providers;

use App\Models\Notification;
use App\Support\Notifications\NotificationProvider;
use App\Support\Notifications\NotificationSendResult;
use Illuminate\Support\Facades\Log;

/**
 * Testing-only email provider (section 19): proves the provider
 * abstraction without making any external network call. Never wire a
 * real SMTP/API provider in without explicit authorization -- see the
 * Phase 0C stop gates.
 */
class LogEmailProvider implements NotificationProvider
{
    public function channel(): string
    {
        return 'email';
    }

    public function send(Notification $notification): NotificationSendResult
    {
        Log::info('notification.email.sent', [
            'notification_id' => $notification->id,
            'school_id' => $notification->school_id,
            'recipient_user_id' => $notification->recipient_user_id,
            'template_key' => $notification->template_key,
        ]);

        return NotificationSendResult::ok();
    }
}
