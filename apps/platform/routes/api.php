<?php

use App\Domain\AcademicStructure\Http\Controllers\AcademicDepartmentController;
use App\Domain\AcademicStructure\Http\Controllers\AcademicTermController;
use App\Domain\AcademicStructure\Http\Controllers\AcademicYearController;
use App\Domain\AcademicStructure\Http\Controllers\GradeLevelController;
use App\Domain\AcademicStructure\Http\Controllers\RoomController;
use App\Domain\AcademicStructure\Http\Controllers\SectionController;
use App\Domain\AcademicStructure\Http\Controllers\SubjectController;
use App\Domain\AcademicStructure\Http\Controllers\SubjectOfferingController;
use App\Domain\HR\Http\Controllers\EmployeeActivityController;
use App\Domain\HR\Http\Controllers\EmployeeDirectoryController;
use App\Domain\HR\Http\Controllers\EmployeeProfileController;
use App\Domain\HR\Http\Controllers\EmployeeSensitiveDocumentController;
use App\Http\Controllers\Api\Internal\AiAuditController;
use App\Http\Controllers\Api\Internal\AiToolController;
use App\Http\Controllers\Api\Internal\HealthController;
use App\Http\Controllers\Api\Internal\OperationsController;
use App\Http\Controllers\Api\V1\CampusController;
use App\Http\Controllers\Api\V1\EducationBoardController;
use App\Http\Controllers\Api\V1\Internal\IdempotencyDemoController;
use App\Http\Controllers\Api\V1\Internal\WebhookTestEventController;
use App\Http\Controllers\Api\V1\SchoolContextController;
use App\Http\Controllers\Api\V1\SchoolProfileController;
use App\Http\Controllers\Api\V1\SystemStatusController;
use App\Http\Controllers\Api\V1\WebhookDeliveryController;
use App\Http\Controllers\Api\V1\WebhookEndpointController;
use App\Http\Controllers\Api\V1\WebhookSubscriptionController;
use App\Http\Middleware\DevOnlySchoolHeaderResolver;
use App\Http\Middleware\ResolveSchoolContext;
use Illuminate\Support\Facades\Route;

// All public/partner API routes are versioned under /api/v1. See
// docs/architecture/API.md.
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/system/status', [SystemStatusController::class, 'show'])
        ->middleware('throttle:public-api')
        ->name('system.status');

    // Flutter/mobile-facing chain proof (section 40): Sanctum identity
    // -> membership -> School context -> capabilities -> response.
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/schools/{school}/context', [SchoolContextController::class, 'show'])
            ->name('schools.context');

        // Phase 0D section 11/41: platform reference catalog -- any
        // authenticated user may read it (no capability gate; it is not
        // School-owned or sensitive), used to populate a School's
        // Education Board picker.
        Route::get('/education-boards', [EducationBoardController::class, 'index'])
            ->name('education-boards.index');
    });

    // Phase 0C.2 (API idempotency foundation, section 18): a tiny
    // infrastructure-only demonstration mutation proving the
    // `idempotent` middleware against a real state-changing side
    // effect. Deliberately NOT a production route -- no real ERP
    // business module is exposed here. See
    // App\Http\Controllers\Api\V1\Internal\IdempotencyDemoController
    // and docs/architecture/RELIABILITY.md.
    if (app()->environment(['local', 'testing'])) {
        Route::middleware(['auth:sanctum', 'school-membership'])->group(function (): void {
            Route::post('/schools/{school}/idempotency-demo/increment', [IdempotencyDemoController::class, 'increment'])
                ->middleware(['capability:school.settings.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.idempotency-demo.increment');

            // Phase 0C.3 section 78/79: emits the synthetic
            // platform.webhook_test.v1 event for the webhook
            // subsystem's live proof. Never registered outside
            // local/testing. See WebhookTestEventController.
            Route::post('/schools/{school}/webhook-test-events', [WebhookTestEventController::class, 'emit'])
                ->name('schools.webhook-test-events.emit');
        });
    }

    // Phase 0C.3 (webhook & external integration delivery foundation,
    // section 51): minimal, coherent School webhook-management API.
    // `{webhookEndpoint}`/`{webhookSubscription}`/`{webhookDelivery}`
    // are resolved explicitly inside each controller action, never via
    // implicit route-model binding, so tenant scoping is guaranteed to
    // already be active (section 53) -- see each controller's docblock.
    Route::middleware(['auth:sanctum', 'school-membership'])
        ->prefix('schools/{school}')
        ->group(function (): void {
            Route::get('/webhook-endpoints', [WebhookEndpointController::class, 'index'])
                ->name('schools.webhook-endpoints.index');
            // capability BEFORE idempotent (not the in-controller
            // AuthorizesCapability trait) so a replayed request
            // re-checks authorization before the idempotency guard is
            // ever consulted -- see docs/architecture/RELIABILITY.md
            // ("Authorization and replay") and CLAUDE.md rule 32.
            Route::post('/webhook-endpoints', [WebhookEndpointController::class, 'store'])
                ->middleware(['capability:integrations.webhooks.manage', 'throttle:webhook-admin', 'idempotent'])
                ->name('schools.webhook-endpoints.store');
            Route::get('/webhook-endpoints/{webhookEndpoint}', [WebhookEndpointController::class, 'show'])
                ->name('schools.webhook-endpoints.show');
            Route::post('/webhook-endpoints/{webhookEndpoint}/rotate-secret', [WebhookEndpointController::class, 'rotateSecret'])
                ->middleware(['capability:integrations.webhooks.manage', 'throttle:webhook-admin', 'idempotent'])
                ->name('schools.webhook-endpoints.rotate-secret');
            Route::post('/webhook-endpoints/{webhookEndpoint}/disable', [WebhookEndpointController::class, 'disable'])
                ->middleware('throttle:webhook-admin')
                ->name('schools.webhook-endpoints.disable');
            Route::post('/webhook-endpoints/{webhookEndpoint}/enable', [WebhookEndpointController::class, 'enable'])
                ->middleware('throttle:webhook-admin')
                ->name('schools.webhook-endpoints.enable');

            Route::post('/webhook-endpoints/{webhookEndpoint}/subscriptions', [WebhookSubscriptionController::class, 'store'])
                ->middleware('throttle:webhook-admin')
                ->name('schools.webhook-endpoints.subscriptions.store');
            Route::delete('/webhook-endpoints/{webhookEndpoint}/subscriptions/{webhookSubscription}', [WebhookSubscriptionController::class, 'destroy'])
                ->middleware('throttle:webhook-admin')
                ->name('schools.webhook-endpoints.subscriptions.destroy');

            Route::get('/webhook-deliveries', [WebhookDeliveryController::class, 'index'])
                ->name('schools.webhook-deliveries.index');
            Route::get('/webhook-deliveries/{webhookDelivery}', [WebhookDeliveryController::class, 'show'])
                ->name('schools.webhook-deliveries.show');
            Route::post('/webhook-deliveries/{webhookDelivery}/redeliver', [WebhookDeliveryController::class, 'redeliver'])
                ->middleware(['capability:integrations.webhooks.manage', 'throttle:webhook-admin', 'idempotent'])
                ->name('schools.webhook-deliveries.redeliver');

            // --- Phase 0D: School profile & Organizational/Academic
            // Structure (section 52). `idempotent` is applied only to
            // the consequential create/state-transition endpoints
            // section 53 names explicitly -- never to GET, never
            // reflexively on every POST.

            Route::get('/', [SchoolProfileController::class, 'show'])
                ->name('schools.profile.show');
            Route::patch('/', [SchoolProfileController::class, 'update'])
                ->middleware(['capability:school.profile.manage', 'throttle:school-api-mutations'])
                ->name('schools.profile.update');

            Route::get('/campuses', [CampusController::class, 'index'])
                ->name('schools.campuses.index');
            Route::post('/campuses', [CampusController::class, 'store'])
                ->middleware(['capability:school.campuses.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.campuses.store');
            Route::get('/campuses/{campus}', [CampusController::class, 'show'])
                ->name('schools.campuses.show');
            Route::patch('/campuses/{campus}', [CampusController::class, 'update'])
                ->middleware(['capability:school.campuses.manage', 'throttle:school-api-mutations'])
                ->name('schools.campuses.update');

            Route::get('/campuses/{campus}/rooms', [RoomController::class, 'index'])
                ->name('schools.campuses.rooms.index');
            Route::post('/campuses/{campus}/rooms', [RoomController::class, 'store'])
                ->middleware(['capability:academics.structure.manage', 'throttle:school-api-mutations'])
                ->name('schools.campuses.rooms.store');
            Route::get('/rooms/{room}', [RoomController::class, 'show'])
                ->name('schools.rooms.show');
            Route::patch('/rooms/{room}', [RoomController::class, 'update'])
                ->middleware(['capability:academics.structure.manage', 'throttle:school-api-mutations'])
                ->name('schools.rooms.update');

            Route::get('/academic-years', [AcademicYearController::class, 'index'])
                ->name('schools.academic-years.index');
            Route::post('/academic-years', [AcademicYearController::class, 'store'])
                ->middleware(['capability:academics.years.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.academic-years.store');
            Route::get('/academic-years/{academicYear}', [AcademicYearController::class, 'show'])
                ->name('schools.academic-years.show');
            Route::patch('/academic-years/{academicYear}', [AcademicYearController::class, 'update'])
                ->middleware(['capability:academics.years.manage', 'throttle:school-api-mutations'])
                ->name('schools.academic-years.update');
            Route::post('/academic-years/{academicYear}/activate', [AcademicYearController::class, 'activate'])
                ->middleware(['capability:academics.years.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.academic-years.activate');
            Route::post('/academic-years/{academicYear}/close', [AcademicYearController::class, 'close'])
                ->middleware(['capability:academics.years.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.academic-years.close');

            Route::get('/academic-years/{academicYear}/terms', [AcademicTermController::class, 'index'])
                ->name('schools.academic-years.terms.index');
            Route::post('/academic-years/{academicYear}/terms', [AcademicTermController::class, 'store'])
                ->middleware(['capability:academics.years.manage', 'throttle:school-api-mutations'])
                ->name('schools.academic-years.terms.store');
            Route::get('/academic-terms/{academicTerm}', [AcademicTermController::class, 'show'])
                ->name('schools.academic-terms.show');
            Route::patch('/academic-terms/{academicTerm}', [AcademicTermController::class, 'update'])
                ->middleware(['capability:academics.years.manage', 'throttle:school-api-mutations'])
                ->name('schools.academic-terms.update');

            Route::get('/grade-levels', [GradeLevelController::class, 'index'])
                ->name('schools.grade-levels.index');
            Route::post('/grade-levels', [GradeLevelController::class, 'store'])
                ->middleware(['capability:academics.structure.manage', 'throttle:school-api-mutations'])
                ->name('schools.grade-levels.store');
            Route::get('/grade-levels/{gradeLevel}', [GradeLevelController::class, 'show'])
                ->name('schools.grade-levels.show');
            Route::patch('/grade-levels/{gradeLevel}', [GradeLevelController::class, 'update'])
                ->middleware(['capability:academics.structure.manage', 'throttle:school-api-mutations'])
                ->name('schools.grade-levels.update');

            Route::get('/academic-years/{academicYear}/sections', [SectionController::class, 'index'])
                ->name('schools.academic-years.sections.index');
            Route::post('/academic-years/{academicYear}/sections', [SectionController::class, 'store'])
                ->middleware(['capability:academics.structure.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.academic-years.sections.store');
            Route::get('/sections/{section}', [SectionController::class, 'show'])
                ->name('schools.sections.show');
            Route::patch('/sections/{section}', [SectionController::class, 'update'])
                ->middleware(['capability:academics.structure.manage', 'throttle:school-api-mutations'])
                ->name('schools.sections.update');

            Route::get('/subjects', [SubjectController::class, 'index'])
                ->name('schools.subjects.index');
            Route::post('/subjects', [SubjectController::class, 'store'])
                ->middleware(['capability:academics.subjects.manage', 'throttle:school-api-mutations'])
                ->name('schools.subjects.store');
            Route::get('/subjects/{subject}', [SubjectController::class, 'show'])
                ->name('schools.subjects.show');
            Route::patch('/subjects/{subject}', [SubjectController::class, 'update'])
                ->middleware(['capability:academics.subjects.manage', 'throttle:school-api-mutations'])
                ->name('schools.subjects.update');

            Route::get('/academic-departments', [AcademicDepartmentController::class, 'index'])
                ->name('schools.academic-departments.index');
            Route::post('/academic-departments', [AcademicDepartmentController::class, 'store'])
                ->middleware(['capability:academics.structure.manage', 'throttle:school-api-mutations'])
                ->name('schools.academic-departments.store');
            Route::get('/academic-departments/{academicDepartment}', [AcademicDepartmentController::class, 'show'])
                ->name('schools.academic-departments.show');
            Route::patch('/academic-departments/{academicDepartment}', [AcademicDepartmentController::class, 'update'])
                ->middleware(['capability:academics.structure.manage', 'throttle:school-api-mutations'])
                ->name('schools.academic-departments.update');

            Route::get('/academic-years/{academicYear}/subject-offerings', [SubjectOfferingController::class, 'index'])
                ->name('schools.academic-years.subject-offerings.index');
            Route::post('/academic-years/{academicYear}/subject-offerings', [SubjectOfferingController::class, 'store'])
                ->middleware(['capability:academics.subjects.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.academic-years.subject-offerings.store');
            Route::get('/subject-offerings/{subjectOffering}', [SubjectOfferingController::class, 'show'])
                ->name('schools.subject-offerings.show');
            Route::patch('/subject-offerings/{subjectOffering}', [SubjectOfferingController::class, 'update'])
                ->middleware(['capability:academics.subjects.manage', 'throttle:school-api-mutations'])
                ->name('schools.subject-offerings.update');

            // Phase 8A.14: read-only HR transport. Deliberately NO
            // `capability:` route middleware anywhere in this block --
            // every one of these controllers calls straight through to
            // an already-authoritative 8A.8/8A.9/8A.10/8A.11 Application
            // service (EmployeeDirectoryService/EmployeeProfileWorkspaceService/
            // EmployeeActivityTimelineService/EmployeeSensitiveDocumentReadService)
            // that performs its OWN `hr.employees.*` capability check
            // against the real authenticated actor before running any
            // query -- adding a second, route-level capability check
            // here would either exactly duplicate that check or risk
            // silently drifting from it (docs/modules/HR.md 8A.14 "no
            // divergent capability matrix"). `{employee}` is always a
            // raw route-parameter string, never implicit Eloquent
            // route-model binding -- see each controller's own docblock.
            Route::get('/employees', [EmployeeDirectoryController::class, 'index'])
                ->name('schools.employees.index');
            Route::get('/employees/{employee}', [EmployeeProfileController::class, 'show'])
                ->name('schools.employees.show');
            Route::get('/employees/{employee}/activity', [EmployeeActivityController::class, 'index'])
                ->name('schools.employees.activity.index');
            Route::get('/employees/{employee}/sensitive-documents', [EmployeeSensitiveDocumentController::class, 'index'])
                ->name('schools.employees.sensitive-documents.index');
        });
});

// Phase 0C.4 sections 5-9: liveness/readiness. Deliberately top-level,
// not under /v1 -- these are infrastructure probes that must stay
// stable regardless of future API versioning, not a versioned business
// surface. Unauthenticated (infrastructure must poll without a
// credential) and exempt from ordinary rate limiting (section 32) --
// see App\Providers\RateLimiterServiceProvider.
//
// `withoutMiddleware` removes ResolveSchoolContext/DevOnlySchoolHeaderResolver
// -- both are appended to the whole `api` middleware group
// (bootstrap/app.php) and ResolveSchoolContext unconditionally runs a
// SchoolDomain lookup against PostgreSQL on every /api/* request. Left
// in place, a PostgreSQL outage would hang liveness/readiness
// themselves (discovered via this checkpoint's own live proof: a
// stopped PostgreSQL container froze /api/health/live indefinitely
// under `php artisan serve`'s single-threaded dev server, not just
// /ready) -- exactly the failure mode liveness exists to survive.
// Readiness's OWN PostgreSQL/Redis checks remain (bounded, dedicated
// connections, OperationalStatusService) -- this only removes the
// UNRELATED, unbounded tenant-resolution query neither endpoint needs.
Route::prefix('health')
    ->withoutMiddleware([ResolveSchoolContext::class, DevOnlySchoolHeaderResolver::class])
    ->group(function (): void {
        Route::get('/live', [HealthController::class, 'live'])->name('api.health.live');
        Route::get('/ready', [HealthController::class, 'ready'])->name('api.health.ready');
    });

// Phase 0C.4 section 52: authenticated internal diagnostics -- see
// OperationsController's docblock for why this uses human
// platform-capability auth rather than internal-service auth.
Route::prefix('internal/operations')->middleware(['auth:sanctum', 'throttle:internal-diagnostics'])->group(function (): void {
    Route::get('/status', [OperationsController::class, 'status'])->name('api.internal.operations.status');
});

// AI Gateway -> Laravel "AI tool contract" surface (ADR 0013, ADR 0014,
// ADR 0023). Deliberately NOT under /api/v1 -- this is a
// service-to-service boundary, never called by a browser/mobile client
// directly. See docs/ai/AI-PLATFORM.md.
Route::prefix('internal/ai')->group(function (): void {
    Route::post('/tools/school-echo', [AiToolController::class, 'schoolEcho'])
        ->middleware(['ai-service:ai.tools.invoke', 'throttle:internal-service'])
        ->name('api.internal.ai.tools.school-echo');

    // Durable-audit write-back (Phase 0C section 58/69): a DIFFERENT,
    // narrower capability than tool invocation -- a service identity
    // entitled to invoke tools is not automatically entitled to write
    // audit entries, and vice versa. See AiAuditController.
    Route::post('/audit', [AiAuditController::class, 'store'])
        ->middleware(['ai-service:ai.audit.write', 'throttle:internal-service'])
        ->name('api.internal.ai.audit.store');
});
