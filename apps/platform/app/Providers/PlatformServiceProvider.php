<?php

namespace App\Providers;

use App\Domain\Automation\Application\AutomationTriggerConsumer;
use App\Domain\Communications\Application\Audience\CommunicationAudienceResolverRegistry;
use App\Domain\Communications\Application\Audience\GradeAudienceResolver;
use App\Domain\Communications\Application\Audience\GuardianAudienceResolver;
use App\Domain\Communications\Application\Audience\GuardiansOfStudentsAudienceResolver;
use App\Domain\Communications\Application\Audience\IndividualMembersAudienceResolver;
use App\Domain\Communications\Application\Audience\SchoolWideAudienceResolver;
use App\Domain\Communications\Application\Audience\SectionAudienceResolver;
use App\Domain\Communications\Application\Audience\StudentAudienceResolver;
use App\Domain\Communications\Application\Audience\SubjectOfferingAudienceResolver;
use App\Domain\Communications\Application\Channels\CommunicationChannelRegistry;
use App\Domain\Communications\Application\Channels\EmailChannelDriver;
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
        // Scoped, not singleton: it holds the (scoped) TenantContext, and a
        // long-running queue worker rebuilds scoped instances between jobs
        // (forgetScopedInstances). A singleton kept the FIRST job's context,
        // so withSchool() in a later job restored that stale context and
        // reset the RLS session variable for the rest of the job -- found by
        // Phase 0L.6, the first production caller in a worker
        // (FeatureFlagResolverWorkerScopeTest).
        $this->app->scoped(FeatureFlagResolver::class);

        $this->app->singleton(NotificationDispatcher::class, function () {
            $dispatcher = new NotificationDispatcher;
            $dispatcher->registerProvider(new InAppProvider);
            $dispatcher->registerProvider(new LogEmailProvider);
            $dispatcher->registerProvider(new FakeSmsProvider);
            $dispatcher->registerProvider(new FakeWhatsAppProvider);
            $dispatcher->registerProvider(new FakePushProvider);

            return $dispatcher;
        });

        $this->app->singleton(CommunicationChannelRegistry::class, function ($app) {
            $registry = new CommunicationChannelRegistry;
            $registry->register(new InAppChannelDriver);
            $registry->register($app->make(EmailChannelDriver::class));

            return $registry;
        });

        $this->app->singleton(CommunicationAudienceResolverRegistry::class, function ($app) {
            $registry = new CommunicationAudienceResolverRegistry;
            $registry->register($app->make(IndividualMembersAudienceResolver::class));
            $registry->register($app->make(SchoolWideAudienceResolver::class));
            $registry->register($app->make(StudentAudienceResolver::class));
            $registry->register($app->make(GuardianAudienceResolver::class));
            $registry->register($app->make(GuardiansOfStudentsAudienceResolver::class));
            $registry->register($app->make(GradeAudienceResolver::class));
            $registry->register($app->make(SectionAudienceResolver::class));
            $registry->register($app->make(SubjectOfferingAudienceResolver::class));

            return $registry;
        });

        $this->app->singleton(EventConsumerRegistry::class, function ($app) {
            $registry = new EventConsumerRegistry;
            $registry->register($app->make(NotifyActorOfSettingChangeConsumer::class));
            $registry->register($app->make(WebhookFanoutConsumer::class));
            // Phase 0L.6 (ADR 0043): the single Automation consumer; it
            // handles only the rule catalog's trigger event types.
            $registry->register($app->make(AutomationTriggerConsumer::class));

            return $registry;
        });
    }
}
