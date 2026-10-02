<?php

namespace App\Providers;

use App\Domain\Finance\Application\Periods\FinancialPeriodCloseParticipant;
use App\Domain\Payments\Application\ChargePeriodStateParticipant;
use App\Models\School;
use App\Models\User;
use App\Support\Ai\AiContextTokenService;
use App\Support\Api\ApiScope;
use App\Support\ApiClients\PartnerCredentialAuthenticator;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Configuration\ProductionConfigurationException;
use App\Support\Configuration\ProductionConfigurationGuard;
use App\Support\Observability\ErrorReporter;
use App\Support\Observability\LogErrorReporter;
use App\Support\Observability\Metrics\ArrayMetricStore;
use App\Support\Observability\Metrics\MetricStore;
use App\Support\Observability\Metrics\NullMetricStore;
use App\Support\Observability\Metrics\RedisMetricStore;
use App\Support\Observability\Metrics\StoreMetricsRecorder;
use App\Support\Observability\MetricsRecorder;
use App\Support\Privacy\ContactLookupHasher;
use App\Support\Privacy\StatutoryIdentifierLookupHasher;
use App\Support\ServiceAuth\ServiceAuthKeys;
use App\Support\Tenancy\ElevationContext;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\TestDatabaseGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 0C.3A safety closure: runs on EVERY boot (web, artisan,
        // tinker), but only actually does anything when
        // app()->environment('testing') -- see TestDatabaseGuard's
        // docblock for the exact incident this closes. Deliberately in
        // register(), the earliest point config() is available, so it
        // fires before any command (including a raw migrate:fresh)
        // could reach a destructive database call.
        (new TestDatabaseGuard)->assertSafe();

        // Phase 0O.1: production refuses to boot with an unsafe
        // configuration (debug on, no usable app key, non-Secure session
        // cookie, missing/placeholder AI signing key, the development
        // service token). Same placement and reason as the guard above:
        // before anything can serve a request or run a command. The
        // refusal is rendered with debug OFF, so a misconfigured production
        // host never serves a debug error page (source, trace, request).
        if ($this->app->isProduction()) {
            try {
                (new ProductionConfigurationGuard($this->app->make('config')))->assertSafe();
            } catch (ProductionConfigurationException $e) {
                $this->app->make('config')->set('app.debug', false);

                throw $e;
            }
        }

        // scoped(): fresh per HTTP request AND per queue job (Laravel's
        // container flushes scoped bindings after each). This is a
        // defense-in-depth safety net -- the explicit job middleware
        // (App\Support\Tenancy\SetTenantContextForJob) is the guarantee
        // this checkpoint actually tests, not this binding alone. See
        // docs/architecture/adr/0022-tenant-context-propagation.md.
        $this->app->scoped(TenantContext::class);

        // Phase 0N.3 (ADR 0044): the request-scoped platform elevation
        // reference -- scoped for the same reason as TenantContext.
        $this->app->scoped(ElevationContext::class);

        // E21.3A (ADR 0064 §5): the subledgers that carry state across a
        // financial-period close. Registered here, not in Finance, so
        // Finance never references the modules that depend on it.
        $this->app->tag([ChargePeriodStateParticipant::class], FinancialPeriodCloseParticipant::TAG);

        // Phase 0O.1: no (string) cast -- a missing key stays missing and
        // AiContextTokenService refuses to sign or verify with it.
        $this->app->singleton(AiContextTokenService::class, fn () => new AiContextTokenService(
            config('services.ai_gateway.context_signing_key'),
        ));

        // ADR 0053 (Phase 0O.7A): service keys are parsed once per process
        // (lazily, from resolved config); changing them is a restart.
        $this->app->singleton(ServiceAuthKeys::class);

        // Phase 1A.3: like AiContextTokenService above (since Phase
        // 0O.1), this does NOT cast a missing key to an empty string
        // -- ContactLookupHasher::hash() fails closed (throws
        // ContactLookupKeyNotConfiguredException) the first time it is
        // actually used with no key configured, rather than silently
        // computing a digest keyed by ''.
        $this->app->singleton(ContactLookupHasher::class, fn () => new ContactLookupHasher(
            config('privacy.contact_lookup.hmac_key'),
            (int) config('privacy.contact_lookup.hmac_key_version'),
        ));

        // Checkpoint 9.6C: same fail-closed shape as ContactLookupHasher
        // above, its own dedicated key.
        $this->app->singleton(StatutoryIdentifierLookupHasher::class, fn () => new StatutoryIdentifierLookupHasher(
            config('privacy.statutory_identifier_lookup.hmac_key'),
            (int) config('privacy.statutory_identifier_lookup.hmac_key_version'),
        ));

        // Phase 0C.4 sections 38/45: provider-independent abstractions.
        // A future real adapter (a real metrics backend, a real error-
        // tracking vendor) rebinds these two lines only -- no call site
        // anywhere else changes.
        // Phase 0O.5A (ADR 0051 §9-§10): one metrics substrate -- a
        // catalog-validated recorder over a shared store (Redis in
        // production, in-process in tests, or disabled).
        $this->app->singleton(MetricStore::class, fn () => match (config('observability.metrics.store')) {
            'array' => new ArrayMetricStore,
            'null' => new NullMetricStore,
            default => new RedisMetricStore((string) config('observability.metrics.redis_connection'), (string) config('observability.metrics.redis_key')),
        });
        $this->app->singleton(MetricsRecorder::class, StoreMetricsRecorder::class);
        $this->app->bind(ErrorReporter::class, LogErrorReporter::class);
    }

    public function boot(): void
    {
        // Phase 0O.3 (ADR 0049 section 2): on every bearer request, a human
        // API token is valid only if Sanctum's own checks pass AND it has a
        // finite expiry, only catalog scopes (never `*`), and an enabled
        // owner -- a disabled account's token is a generic 401, not an
        // authenticated request with no capabilities.
        Sanctum::authenticateAccessTokensUsing(fn (PersonalAccessToken $token, bool $isValid): bool => $isValid
            && $token->expires_at !== null
            && ApiScope::isValidHumanSet((array) $token->abilities)
            && $token->tokenable instanceof User
            && ! $token->tokenable->isDisabled());

        // Phase 0O.3 (ADR 0049 section 3): the `auth:partner` guard.
        Auth::viaRequest('partner-credential', fn (Request $request) => app(PartnerCredentialAuthenticator::class)->authenticate($request));

        // Capability-based authorization (docs/security/AUTHORIZATION.md).
        // Usage: Gate::authorize('capability', ['school.settings.manage', $school]);
        // or the AuthorizesCapability controller trait's authorizeCapability().
        Gate::define('capability', function (User $user, string $capability, ?School $school = null) {
            return app(CapabilityResolver::class)->can($user, $capability, $school);
        });

        // Transactional outbox (ADR 0025, docs/architecture/EVENTS.md):
        // App\Listeners\RecordDomainEventToOutbox is picked up by
        // Laravel's OWN automatic event discovery (enabled by default
        // via Application::configure()->withEvents(), which scans
        // app/Listeners for a handle() method's type-hint) -- it fires
        // for EVERY event implementing ShouldBeOutboxed, regardless of
        // concrete class, because Laravel's dispatcher resolves
        // interface listeners natively
        // (Illuminate\Events\Dispatcher::addInterfaceListeners).
        // Deliberately NOT also registered here via Event::listen():
        // this checkpoint found that doing both registers the listener
        // TWICE, silently doubling every domain event -- see the Phase
        // 0C Final Report's Security Review / Technical Debt notes.

        // Phase 0C.4 section 19 queue heartbeat: App\Listeners\RecordQueueHeartbeat
        // is registered by Laravel's listener DISCOVERY only (its public
        // handle* methods, type-hinted on the queue events). Phase 0O.5A
        // (ADR 0051 §4.2) removed the explicit Event::listen() that also
        // registered it, which double-recorded every heartbeat --
        // Tests\Feature\Observability\ObservabilityDefectReproductionTest.
    }
}
