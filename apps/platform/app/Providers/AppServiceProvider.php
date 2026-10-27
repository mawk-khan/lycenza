<?php

namespace App\Providers;

use App\Domain\AcademicStructure\Application\Calendar\AcademicYearActivationBlocker;
use App\Domain\AcademicStructure\Application\Calendar\AcademicYearDateDependency;
use App\Domain\Examinations\Application\ExaminationYearDateDependency;
use App\Domain\Examinations\Application\Marks\StudentMarkPlacementCancellationBlocker;
use App\Domain\Fees\Application\FeeAssessmentPlacementCancellationBlocker;
use App\Domain\Fees\Application\FeeYearDateDependency;
use App\Domain\Finance\Application\Periods\FinancialPeriodCloseParticipant;
use App\Domain\Finance\Application\Retention\FinanceRetentionParticipant;
use App\Domain\Guardians\Application\GuardianAccountOwnership;
use App\Domain\Hostel\Application\HostelTransferBlocker;
use App\Domain\HR\Application\EmploymentEndParticipant;
use App\Domain\Identity\Application\GuardianAccountOwnershipReader;
use App\Domain\Leave\Application\AttendancePresenceConflictReader;
use App\Domain\LMS\Application\AssignmentYearDateDependency;
use App\Domain\Payments\Application\ChargePeriodStateParticipant;
use App\Domain\Payments\Application\Retention\ChargeRetentionParticipant;
use App\Domain\Payroll\Application\Retention\PayrollLedgerRetentionParticipant;
use App\Domain\StaffAttendance\Application\StaffAttendancePresenceReader;
use App\Domain\Students\Application\ElectivePlacementCancellationBlocker;
use App\Domain\Students\Application\ElectiveTransferBlocker;
use App\Domain\Students\Application\PlacementCancellationBlocker;
use App\Domain\Students\Application\PlacementYearDateDependency;
use App\Domain\Students\Application\RolloverExecutionActivationBlocker;
use App\Domain\Students\Application\StudentTransferBlocker;
use App\Domain\TeachingAssignments\Application\EmploymentEndedTeachingOwnership;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentYearDateDependency;
use App\Domain\Transport\Application\TransportTransferBlocker;
use App\Models\School;
use App\Models\User;
use App\Support\Ai\AiContextTokenService;
use App\Support\Api\ApiScope;
use App\Support\ApiClients\PartnerCredentialAuthenticator;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Configuration\EnvironmentPolicy;
use App\Support\Configuration\ProductionConfigurationException;
use App\Support\Configuration\ProductionConfigurationGuard;
use App\Support\Configuration\UnsupportedEnvironmentException;
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
        // PPR.1 (ADR 0074 section 26.2): only the four supported environments
        // boot. A typo (`stage`, `preprod`, `prod2`) or an empty APP_ENV must
        // never run as some weaker third state -- finding F-2 was exactly that.
        // First, before anything else reads the environment; debug is forced
        // off so the refusal can never render a debug page.
        try {
            EnvironmentPolicy::assertSupported();
        } catch (UnsupportedEnvironmentException $e) {
            $this->app->make('config')->set('app.debug', false);

            throw $e;
        }

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
        // PPR.1 (ADR 0074 section 26): it runs in every HARDENED environment
        // -- staging as well as production -- with only the listed
        // EnvironmentPolicy::STAGING_DIFFERENCES.
        $environment = (string) $this->app->environment();
        if (EnvironmentPolicy::isHardened($environment)) {
            try {
                (new ProductionConfigurationGuard($this->app->make('config'), $environment))->assertSafe();
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

        // E21.3A2 (ADR 0064 §17): the modules that decide when Finance
        // evidence they reference may expire (Payroll: never, under D9).
        $this->app->tag([ChargeRetentionParticipant::class, PayrollLedgerRetentionParticipant::class], FinanceRetentionParticipant::TAG);

        // S7 (ADR 0063 §47): the authority an employment granted ends with it.
        // Registered here, not in HR, so HR never references Teaching
        // Assignments (which depends on HR).
        $this->app->tag([EmploymentEndedTeachingOwnership::class], EmploymentEndParticipant::TAG);

        // ORG.7F-3 (ADR 0076 §47): the active source-Campus relationships that
        // block a Campus-scoped Student transfer. Registered here, not in
        // Students, so Students never references Hostel or Transport (which
        // depend on it).
        $this->app->tag([ElectiveTransferBlocker::class, HostelTransferBlocker::class, TransportTransferBlocker::class], StudentTransferBlocker::TAG);

        // SIS 4.2: records that already depend on a placement which has not
        // started yet, so it cannot be cancelled before its start until they
        // are resolved in their own module.
        $this->app->tag([
            ElectivePlacementCancellationBlocker::class, FeeAssessmentPlacementCancellationBlocker::class, StudentMarkPlacementCancellationBlocker::class,
        ], PlacementCancellationBlocker::TAG);

        // SIS 5.1 (MC-3, ADR 0077): the records that must stay inside a draft
        // Academic Year's dates while they are corrected. Registered here, not
        // in AcademicStructure, which every one of these modules depends on.
        $this->app->tag([
            PlacementYearDateDependency::class, TeachingAssignmentYearDateDependency::class, ExaminationYearDateDependency::class,
            AssignmentYearDateDependency::class, FeeYearDateDependency::class,
        ], AcademicYearDateDependency::TAG);

        // SIS 5.2: a rollover executing into a draft year blocks its activation
        // (Students answers; AcademicStructure asks).
        $this->app->tag([RolloverExecutionActivationBlocker::class], AcademicYearActivationBlocker::TAG);

        // HRX.3 (ADR 0065 §24.5): Leave's approval port, implemented by Staff
        // Attendance. Bound here, not in Leave, so Leave never references the
        // module that depends on it.
        $this->app->bind(AttendancePresenceConflictReader::class, StaffAttendancePresenceReader::class);
        // ORG.8A-R1 (ADR 0076 §59): Guardians' port, answered by Identity's account links (no Guardians -> Identity dependency).
        $this->app->bind(GuardianAccountOwnership::class, GuardianAccountOwnershipReader::class);

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

        // ORG.7A (ADR 0076 §11.7, §33): ENTRY to a Campus-adopted surface --
        // the capability held School-wide or in at least one active Campus.
        // Never record authority: the action must then apply the actor's
        // CampusScope (AuthorizesCampusScope) before touching a record.
        Gate::define('campus-capability', function (User $user, string $capability, ?School $school = null) {
            return $school !== null && ! app(CampusScopeResolver::class)->campusScope($user, $capability, $school)->isNone();
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
