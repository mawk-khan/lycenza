<?php

namespace App\Providers;

use App\Domain\Communications\Application\Channels\CommunicationDeliveryEmailSource;
use App\Domain\Identity\Application\GuardianInvitationEmailSource;
use App\Support\Email\EmailSources;
use App\Support\Email\Providers\FakeEmailProvider;
use App\Support\Email\RetrySchedule;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 0O.9A (ADR 0055): the platform email layer's composition root --
 * the fake provider as one per-process instance (tests script and inspect
 * it), and the product sources whose messages the email layer carries
 * (Identity's invitations, Communications' deliveries). The email layer
 * itself never reads those modules' tables.
 */
class EmailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FakeEmailProvider::class);
        $this->app->singleton(RetrySchedule::class);

        $this->app->singleton(EmailSources::class, function ($app) {
            $sources = new EmailSources;
            $sources->register($app->make(GuardianInvitationEmailSource::class));
            $sources->register($app->make(CommunicationDeliveryEmailSource::class));

            return $sources;
        });
    }
}
