<?php

namespace App\Support\Notifications;

final class NotificationSendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $error = null,
    ) {}

    public static function ok(): self
    {
        return new self(true);
    }

    public static function failed(string $error): self
    {
        return new self(false, $error);
    }
}
