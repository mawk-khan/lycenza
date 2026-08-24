<?php

use App\Domain\AcademicStructure\Http\Controllers\AcademicDepartmentController;
use App\Domain\AcademicStructure\Http\Controllers\AcademicTermController;
use App\Domain\AcademicStructure\Http\Controllers\AcademicYearController;
use App\Domain\AcademicStructure\Http\Controllers\GradeLevelController;
use App\Domain\AcademicStructure\Http\Controllers\RoomController;
use App\Domain\AcademicStructure\Http\Controllers\SectionController;
use App\Domain\AcademicStructure\Http\Controllers\SubjectController;
use App\Domain\AcademicStructure\Http\Controllers\SubjectOfferingController;
use App\Domain\Guardians\Http\Controllers\GuardianContactController;
use App\Domain\Guardians\Http\Controllers\GuardianController;
use App\Domain\Guardians\Http\Controllers\StudentGuardianRelationshipController;
use App\Domain\Students\Http\Controllers\StudentController;
use App\Domain\Students\Http\Controllers\StudentEnrollmentController;
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

            // --- Phase 1A.5: Student & Guardian Identity administrative
            // HTTP surface (docs/modules/STUDENT-GUARDIAN-IDENTITY.md,
            // "Administrative HTTP boundary"). Read actions authorize
            // via the AuthorizesCapability trait inline (matching
            // AcademicYearController's index/show); mutation actions
            // authorize via the `capability:` route middleware
            // (matching CampusController's store/update) -- the same
            // split every controller above already uses. Index/store
            // nest under their owning parent (a GuardianContact always
            // belongs to one Guardian, a StudentGuardianRelationship's
            // creation always names a Student); singular show/update/
            // delete-shaped actions resolve by their own id via a flat,
            // top-level path -- exactly RoomController's/
            // AcademicTermController's established "nested for
            // index/store, flat for singular actions" split.

            Route::get('/students', [StudentController::class, 'index'])
                ->name('schools.students.index');
            Route::post('/students', [StudentController::class, 'store'])
                ->middleware(['capability:students.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.students.store');
            Route::get('/students/{student}', [StudentController::class, 'show'])
                ->name('schools.students.show');
            Route::patch('/students/{student}', [StudentController::class, 'update'])
                ->middleware(['capability:students.manage', 'throttle:school-api-mutations'])
                ->name('schools.students.update');
            Route::post('/students/{student}/status', [StudentController::class, 'changeStatus'])
                ->middleware(['capability:students.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.students.status');

            Route::get('/students/{student}/guardians', [StudentGuardianRelationshipController::class, 'index'])
                ->name('schools.students.guardians.index');
            // Both students.manage AND guardians.manage -- the
            // operation mutates both domain identities' relationship at
            // once (Phase 1A.4's accepted capability design,
            // docs/modules/STUDENT-GUARDIAN-IDENTITY.md
            // "Authorization"). Two stacked `capability:` middleware
            // entries, not a new middleware -- EnsureCapability already
            // supports being applied more than once per route with
            // different arguments.
            Route::post('/students/{student}/guardians', [StudentGuardianRelationshipController::class, 'store'])
                ->middleware(['capability:students.manage', 'capability:guardians.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.students.guardians.store');
            Route::patch('/student-guardian-relationships/{relationship}', [StudentGuardianRelationshipController::class, 'update'])
                ->middleware(['capability:students.manage', 'capability:guardians.manage', 'throttle:school-api-mutations'])
                ->name('schools.student-guardian-relationships.update');
            Route::post('/student-guardian-relationships/{relationship}/primary', [StudentGuardianRelationshipController::class, 'setPrimary'])
                ->middleware(['capability:students.manage', 'capability:guardians.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.student-guardian-relationships.primary');
            // No `idempotent` -- deleting an already-deleted relationship
            // 404s harmlessly on retry, the same natural idempotency
            // WebhookSubscriptionController::destroy() relies on above.
            Route::delete('/student-guardian-relationships/{relationship}', [StudentGuardianRelationshipController::class, 'destroy'])
                ->middleware(['capability:students.manage', 'capability:guardians.manage', 'throttle:school-api-mutations'])
                ->name('schools.student-guardian-relationships.destroy');

            Route::get('/guardians', [GuardianController::class, 'index'])
                ->name('schools.guardians.index');
            Route::post('/guardians', [GuardianController::class, 'store'])
                ->middleware(['capability:guardians.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.guardians.store');
            // Exact-match candidate lookup (Phase 1A.3's
            // GuardianContactService::findCandidatesBySchool()) -- a
            // POST action, not a resource, hence the flat
            // `guardian-candidates` path rather than nesting under
            // `/guardians`. guardians.view only (read-only candidate
            // detection, never a mutation).
            Route::post('/guardian-candidates', [GuardianController::class, 'candidates'])
                ->name('schools.guardian-candidates');
            Route::get('/guardians/{guardian}', [GuardianController::class, 'show'])
                ->name('schools.guardians.show');
            Route::patch('/guardians/{guardian}', [GuardianController::class, 'update'])
                ->middleware(['capability:guardians.manage', 'throttle:school-api-mutations'])
                ->name('schools.guardians.update');
            Route::post('/guardians/{guardian}/status', [GuardianController::class, 'changeStatus'])
                ->middleware(['capability:guardians.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.guardians.status');

            Route::post('/guardians/{guardian}/contacts', [GuardianContactController::class, 'store'])
                ->middleware(['capability:guardians.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.guardians.contacts.store');
            Route::post('/guardian-contacts/{contact}/primary', [GuardianContactController::class, 'setPrimary'])
                ->middleware(['capability:guardians.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.guardian-contacts.primary');
            Route::post('/guardian-contacts/{contact}/deactivate', [GuardianContactController::class, 'deactivate'])
                ->middleware(['capability:guardians.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.guardian-contacts.deactivate');

            // --- Phase 1B.5: Student Enrollment administrative HTTP
            // surface (docs/modules/STUDENT-ENROLLMENT.md, "Administrative
            // HTTP boundary"). This exposes the already-built Enrollment
            // domain (Phase 1B.1-1B.4A) -- it does not redesign it. Read
            // actions authorize via AuthorizesCapability inline (matching
            // AcademicYearController's index/show); mutation actions ALSO
            // carry the `capability:` route middleware (defense in depth,
            // matching every mutation route above). `enrollments.view`/
            // `enrollments.manage` are independent capabilities (Phase
            // 1B.4) -- manage never implies view here. Nested for
            // per-Student reads/create (an Enrollment's history/current
            // placement/creation naturally belong to one Student), flat
            // for the administrative directory/detail/lifecycle actions --
            // the same "nested for index/store, flat for singular actions"
            // split StudentGuardianRelationshipController/RoomController/
            // AcademicTermController already established above.

            Route::get('/enrollments', [StudentEnrollmentController::class, 'index'])
                ->name('schools.enrollments.index');
            Route::get('/enrollments/{enrollment}', [StudentEnrollmentController::class, 'show'])
                ->name('schools.enrollments.show');
            Route::post('/enrollments/{enrollment}/complete', [StudentEnrollmentController::class, 'complete'])
                ->middleware(['capability:enrollments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.enrollments.complete');
            Route::post('/enrollments/{enrollment}/withdraw', [StudentEnrollmentController::class, 'withdraw'])
                ->middleware(['capability:enrollments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.enrollments.withdraw');
            Route::post('/enrollments/{enrollment}/cancel', [StudentEnrollmentController::class, 'cancel'])
                ->middleware(['capability:enrollments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.enrollments.cancel');
            Route::post('/enrollments/{enrollment}/transfer', [StudentEnrollmentController::class, 'transfer'])
                ->middleware(['capability:enrollments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.enrollments.transfer');

            Route::get('/students/{student}/enrollments', [StudentEnrollmentController::class, 'historyForStudent'])
                ->name('schools.students.enrollments.index');
            Route::get('/students/{student}/enrollments/current', [StudentEnrollmentController::class, 'currentForStudent'])
                ->name('schools.students.enrollments.current');
            Route::post('/students/{student}/enrollments', [StudentEnrollmentController::class, 'store'])
                ->middleware(['capability:enrollments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.students.enrollments.store');
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
