<?php

namespace App\Providers;

use App\Listeners\RecordQueueHeartbeat;
use App\Models\School;
use App\Models\User;
use App\Support\Ai\AiContextTokenService;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Observability\ErrorReporter;
use App\Support\Observability\LogErrorReporter;
use App\Support\Observability\LogMetricsRecorder;
use App\Support\Observability\MetricsRecorder;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\TestDatabaseGuard;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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

        // scoped(): fresh per HTTP request AND per queue job (Laravel's
        // container flushes scoped bindings after each). This is a
        // defense-in-depth safety net -- the explicit job middleware
        // (App\Support\Tenancy\SetTenantContextForJob) is the guarantee
        // this checkpoint actually tests, not this binding alone. See
        // docs/architecture/adr/0022-tenant-context-propagation.md.
        $this->app->scoped(TenantContext::class);

        $this->app->singleton(AiContextTokenService::class, fn () => new AiContextTokenService(
            (string) config('services.ai_gateway.context_signing_key'),
        ));

        // Phase 0C.4 sections 38/45: provider-independent abstractions.
        // A future real adapter (a real metrics backend, a real error-
        // tracking vendor) rebinds these two lines only -- no call site
        // anywhere else changes.
        $this->app->bind(MetricsRecorder::class, LogMetricsRecorder::class);
        $this->app->bind(ErrorReporter::class, LogErrorReporter::class);
    }

    public function boot(): void
    {
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

        // Phase 0C.4 section 19: queue processing heartbeat, hooked
        // into Laravel's OWN queue lifecycle events -- no per-job code
        // change required anywhere. JobProcessed/JobFailed are plain
        // Laravel events (not interface-discovered like
        // ShouldBeOutboxed above), so explicit registration here is
        // correct and does not risk the double-registration problem
        // noted above.
        Event::listen(JobProcessed::class, [RecordQueueHeartbeat::class, 'handleProcessed']);
        Event::listen(JobFailed::class, [RecordQueueHeartbeat::class, 'handleFailed']);
    }
}
