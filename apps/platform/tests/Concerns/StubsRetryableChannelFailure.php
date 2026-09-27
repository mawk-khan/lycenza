<?php

namespace Tests\Concerns;

use App\Domain\Communications\Application\Channels\CommunicationChannelDriver;
use App\Domain\Communications\Application\Channels\CommunicationChannelRegistry;
use App\Domain\Communications\Application\Channels\CommunicationDeliveryResult;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;

/**
 * ProcessCommunicationDeliveryJob's own bounded retry is channel-agnostic.
 * Since Phase 0O.9A the real EMAIL driver never reports a retryable
 * failure (it hands off to the email layer, which owns email retries --
 * rule 59), so the job's retry path is exercised with a stub driver that
 * replaces the email channel's for one test.
 */
trait StubsRetryableChannelFailure
{
    protected function stubRetryableEmailChannelFailure(): void
    {
        app(CommunicationChannelRegistry::class)->register(new class implements CommunicationChannelDriver
        {
            public function channel(): CommunicationChannel
            {
                return CommunicationChannel::Email;
            }

            public function send(CommunicationDelivery $delivery): CommunicationDeliveryResult
            {
                return CommunicationDeliveryResult::failed('email_transport_unavailable', 'simulated transient failure', retryable: true);
            }
        });
    }
}
