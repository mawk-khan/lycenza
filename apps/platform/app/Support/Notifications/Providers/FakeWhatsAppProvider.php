<?php

namespace App\Support\Notifications\Providers;

use App\Models\Notification;
use App\Support\Notifications\NotificationProvider;
use App\Support\Notifications\NotificationSendResult;
use Illuminate\Support\Facades\Log;

/**
 * Testing-only WhatsApp provider (section 19) -- no real network call.
 * See LogEmailProvider's docblock.
 */
class FakeWhatsAppProvider implements NotificationProvider
{
    public function channel(): string
    {
        return 'whatsapp';
    }

    public function send(Notification $notification): NotificationSendResult
    {
        Log::info('notification.whatsapp.sent', [
            'notification_id' => $notification->id,
            'school_id' => $notification->school_id,
            'template_key' => $notification->template_key,
        ]);

        return NotificationSendResult::ok();
    }
}
