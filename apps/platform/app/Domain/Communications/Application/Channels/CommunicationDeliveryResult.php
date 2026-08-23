<?php

namespace App\Domain\Communications\Application\Channels;

final class CommunicationDeliveryResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerReference = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
    ) {}

    public static function delivered(?string $providerReference = null): self
    {
        return new self(true, providerReference: $providerReference);
    }

    public static function failed(string $failureCode, ?string $failureMessage = null): self
    {
        return new self(false, failureCode: $failureCode, failureMessage: $failureMessage);
    }
}
