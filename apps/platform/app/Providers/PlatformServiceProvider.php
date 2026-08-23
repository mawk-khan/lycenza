<?php

namespace App\Providers;

use App\Domain\Communications\Application\Channels\CommunicationChannelRegistry;
use App\Domain\Communications\Application\Channels\InAppChannelDriver;
use App\Support\Events\Consumers\NotifyActorOfSettingChangeConsumer;
use App\Support\Events\Consumers\WebhookFanoutConsumer;
use App\Support\Events\EventConsumerRegistry;
use App\Support\FeatureFlags\FeatureFlagResolver;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Notifications\Providers\FakePushProvider;
use App\Support\Notifications\Providers\FakeSmsProvider;
use App\Support\Notifications\Providers\FakeWhatsAppProvider;
use App\Support\Notifications\Providers\InAppProvider;
use App\Support\Notifications\Providers\LogEmailProvider;
use App\Support\Settings\SettingRegistry;
use App\Support\Webhooks\SsrfSafeUrlValidator;
use App\Support\Webhooks\WebhookSigner;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 0C's reliability/integration substrate wiring -- kept separate
 * from AppServiceProvider so this checkpoint's additions are easy to
 * locate as a unit. Registers: the event consumer registry, the
 * notification provider registry, and the (stateless, so plain
 * singletons) settings/webhook support services.
 */
class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingRegistry::class);
        $this->app->singleton(SsrfSafeUrlValidator::class);
        $this->app->singleton(WebhookSigner::class);
        $this->app->singleton(FeatureFlagResolver::class);

        $this->app->singleton(NotificationDispatcher::class, function () {
            $dispatcher = new NotificationDispatcher;
            $dispatcher->registerProvider(new InAppProvider);
            $dispatcher->registerProvider(new LogEmailProvider);
            $dispatcher->registerProvider(new FakeSmsProvider);
            $dispatcher->registerProvider(new FakeWhatsAppProvider);
            $dispatcher->registerProvider(new FakePushProvider);

            return $dispatcher;
        });

        $this->app->singleton(CommunicationChannelRegistry::class, function () {
            $registry = new CommunicationChannelRegistry;
            $registry->register(new InAppChannelDriver);

            return $registry;
        });

        $this->app->singleton(EventConsumerRegistry::class, function ($app) {
            $registry = new EventConsumerRegistry;
            $registry->register($app->make(NotifyActorOfSettingChangeConsumer::class));
            $registry->register($app->make(WebhookFanoutConsumer::class));

            return $registry;
        });
    }
}
