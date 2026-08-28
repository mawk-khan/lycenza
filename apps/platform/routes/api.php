<?php

use App\Domain\AcademicStructure\Http\Controllers\AcademicDepartmentController;
use App\Domain\AcademicStructure\Http\Controllers\AcademicTermController;
use App\Domain\AcademicStructure\Http\Controllers\AcademicYearController;
use App\Domain\AcademicStructure\Http\Controllers\GradeLevelController;
use App\Domain\AcademicStructure\Http\Controllers\RoomController;
use App\Domain\AcademicStructure\Http\Controllers\SectionController;
use App\Domain\AcademicStructure\Http\Controllers\SubjectController;
use App\Domain\AcademicStructure\Http\Controllers\SubjectOfferingController;
use App\Domain\Admissions\Http\Controllers\AdmissionApplicationController;
use App\Domain\Admissions\Http\Controllers\ApplicantController;
use App\Domain\Documents\Http\Controllers\DocumentController;
use App\Domain\Fees\Http\Controllers\ChargeController;
use App\Domain\Finance\Http\Controllers\JournalEntryController;
use App\Domain\Finance\Http\Controllers\LedgerAccountController;
use App\Domain\Guardians\Http\Controllers\GuardianContactController;
use App\Domain\Guardians\Http\Controllers\GuardianController;
use App\Domain\Guardians\Http\Controllers\StudentGuardianRelationshipController;
use App\Domain\Hostel\Http\Controllers\HostelBedController;
use App\Domain\Hostel\Http\Controllers\HostelController;
use App\Domain\Hostel\Http\Controllers\HostelResidencyAssignmentController;
use App\Domain\Hostel\Http\Controllers\HostelRoomController;
use App\Domain\HR\Http\Controllers\EmployeeActivityController;
use App\Domain\HR\Http\Controllers\EmployeeDirectoryController;
use App\Domain\HR\Http\Controllers\EmployeeProfileController;
use App\Domain\HR\Http\Controllers\EmployeeSensitiveDocumentController;
use App\Domain\Library\Http\Controllers\LibraryCopyController;
use App\Domain\Library\Http\Controllers\LibraryLoanController;
use App\Domain\Library\Http\Controllers\LibraryTitleController;
use App\Domain\Payments\Http\Controllers\PaymentController;
use App\Domain\Students\Http\Controllers\EnrollmentRolloverController;
use App\Domain\Students\Http\Controllers\EnrollmentRolloverItemController;
use App\Domain\Students\Http\Controllers\EnrollmentRolloverMappingController;
use App\Domain\Students\Http\Controllers\StudentController;
use App\Domain\Students\Http\Controllers\StudentEnrollmentController;
use App\Domain\Students\Http\Controllers\StudentSubjectEnrollmentController;
use App\Domain\Transport\Http\Controllers\TransportRouteAssignmentController;
use App\Domain\Transport\Http\Controllers\TransportRouteController;
use App\Domain\Transport\Http\Controllers\TransportStopController;
use App\Domain\Transport\Http\Controllers\TransportStudentAssignmentController;
use App\Domain\Transport\Http\Controllers\TransportVehicleController;
use App\Domain\Visitor\Http\Controllers\VisitorController;
use App\Domain\Visitor\Http\Controllers\VisitorVisitController;
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

            // --- Phase 1C.1: Student Subject Enrollment administrative
            // HTTP surface (docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md).
            // Reuses academics.subjects.view/.manage rather than a new
            // capability pair -- this is a direct extension of "manage
            // Subjects and Subject Offerings", not a new concern. The
            // roster GET is read-only (no idempotent middleware needed);
            // every mutation is consequential + plausibly retryable
            // (mirrors StudentEnrollmentController's mutation routes
            // exactly), hence `idempotent` on all four.
            Route::get('/subject-offerings/{subjectOffering}/roster', [StudentSubjectEnrollmentController::class, 'roster'])
                ->name('schools.subject-offerings.roster');
            Route::post('/students/{student}/subject-enrollments', [StudentSubjectEnrollmentController::class, 'store'])
                ->middleware(['capability:academics.subjects.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.students.subject-enrollments.store');
            Route::post('/subject-enrollments/{enrollment}/withdraw', [StudentSubjectEnrollmentController::class, 'withdraw'])
                ->middleware(['capability:academics.subjects.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.subject-enrollments.withdraw');
            Route::post('/subject-enrollments/{enrollment}/cancel', [StudentSubjectEnrollmentController::class, 'cancel'])
                ->middleware(['capability:academics.subjects.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.subject-enrollments.cancel');
            Route::post('/subject-enrollments/{enrollment}/transfer', [StudentSubjectEnrollmentController::class, 'transfer'])
                ->middleware(['capability:academics.subjects.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.subject-enrollments.transfer');

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
            //
            // Phase 8A.15: `throttle:hr-api-reads` (School+actor-keyed,
            // 120/min) and `private-no-store` (Cache-Control: private,
            // no-store -- this data must never become shared-cacheable)
            // added to all four; the sensitive-document endpoint uses
            // the stricter `throttle:hr-api-sensitive-reads` (20/min)
            // instead. See docs/modules/HR.md 8A.15 as-built.
            Route::get('/employees', [EmployeeDirectoryController::class, 'index'])
                ->middleware(['throttle:hr-api-reads', 'private-no-store'])
                ->name('schools.employees.index');
            Route::get('/employees/{employee}', [EmployeeProfileController::class, 'show'])
                ->middleware(['throttle:hr-api-reads', 'private-no-store'])
                ->name('schools.employees.show');
            Route::get('/employees/{employee}/activity', [EmployeeActivityController::class, 'index'])
                ->middleware(['throttle:hr-api-reads', 'private-no-store'])
                ->name('schools.employees.activity.index');
            Route::get('/employees/{employee}/sensitive-documents', [EmployeeSensitiveDocumentController::class, 'index'])
                ->middleware(['throttle:hr-api-sensitive-reads', 'private-no-store'])
                ->name('schools.employees.sensitive-documents.index');

            // --- Phase 1B.7E: Enrollment Rollover administrative HTTP
            // surface (docs/modules/STUDENT-ENROLLMENT.md, "Rollover
            // Authorization & Administrative HTTP/API"). Exposes the
            // already-built rollover domain (Phase 1B.7A-1B.7D) -- it
            // does not redesign it. Read actions authorize
            // `enrollments.view` AND `enrollments.rollovers.view`
            // inline (matching StudentEnrollmentController's identical
            // pattern); every mutation action carries BOTH
            // `capability:` route middleware entries (`enrollments.manage`
            // AND `enrollments.rollovers.manage`, dual authorization --
            // rollover's materially higher blast radius earns its own
            // capability, required IN ADDITION TO the base Enrollment
            // one, never instead of it). Mappings/Items are NESTED
            // under the Plan specifically so nested-ownership can be
            // verified against the ROUTE's Plan, not merely the current
            // School.
            Route::get('/enrollment-rollovers', [EnrollmentRolloverController::class, 'index'])
                ->name('schools.enrollment-rollovers.index');
            Route::post('/enrollment-rollovers', [EnrollmentRolloverController::class, 'store'])
                ->middleware(['capability:enrollments.manage', 'capability:enrollments.rollovers.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.enrollment-rollovers.store');
            Route::get('/enrollment-rollovers/{rollover}', [EnrollmentRolloverController::class, 'show'])
                ->name('schools.enrollment-rollovers.show');

            Route::get('/enrollment-rollovers/{rollover}/items', [EnrollmentRolloverItemController::class, 'index'])
                ->name('schools.enrollment-rollovers.items.index');
            Route::patch('/enrollment-rollovers/{rollover}/items/{item}', [EnrollmentRolloverItemController::class, 'update'])
                ->middleware(['capability:enrollments.manage', 'capability:enrollments.rollovers.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.enrollment-rollovers.items.update');

            Route::post('/enrollment-rollovers/{rollover}/mappings', [EnrollmentRolloverMappingController::class, 'store'])
                ->middleware(['capability:enrollments.manage', 'capability:enrollments.rollovers.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.enrollment-rollovers.mappings.store');
            Route::patch('/enrollment-rollovers/{rollover}/mappings/{mapping}', [EnrollmentRolloverMappingController::class, 'update'])
                ->middleware(['capability:enrollments.manage', 'capability:enrollments.rollovers.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.enrollment-rollovers.mappings.update');

            // Explicit lifecycle actions only -- never a generic PATCH
            // accepting `status`/`configuration_version`/execution
            // timestamps from the caller (this checkpoint's brief,
            // section 13).
            Route::post('/enrollment-rollovers/{rollover}/validate', [EnrollmentRolloverController::class, 'validate'])
                ->middleware(['capability:enrollments.manage', 'capability:enrollments.rollovers.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.enrollment-rollovers.validate');
            Route::post('/enrollment-rollovers/{rollover}/start', [EnrollmentRolloverController::class, 'start'])
                ->middleware(['capability:enrollments.manage', 'capability:enrollments.rollovers.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.enrollment-rollovers.start');
            Route::post('/enrollment-rollovers/{rollover}/resume', [EnrollmentRolloverController::class, 'resume'])
                ->middleware(['capability:enrollments.manage', 'capability:enrollments.rollovers.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.enrollment-rollovers.resume');

            // --- Phase 0E.5: Documents HTTP/API transport (ADR 0012,
            // docs/modules/DOCUMENTS.md). Deliberately NO `capability:`
            // route middleware anywhere in this block, for the exact
            // same reason as the HR read block immediately above:
            // DocumentService/DocumentReadService/DocumentListingService
            // already perform their own owner-domain capability check
            // against the real authenticated actor before any query or
            // storage I/O -- a route-level check here would either
            // duplicate or risk drifting from it. Only the Employee
            // owner type is exposed; there is no Student/Guardian
            // route (0E.2/0E.3/0E.4's own activation boundary,
            // unchanged here). `{employee}`/`{document}` are raw
            // route-parameter strings, resolved by the services
            // themselves under tenant scope -- never implicit Eloquent
            // route-model binding (same reasoning as
            // EmployeeProfileController/EmployeeSensitiveDocumentController
            // above). `documents/sensitive` (a static third segment)
            // can never collide with `employees/{employee}/documents`
            // (two segments) or with the unrelated, differently-named
            // `employees/{employee}/sensitive-documents` HR route
            // above -- distinct segment counts/literals, no route
            // precedence ambiguity.
            Route::post('/employees/{employee}/documents', [DocumentController::class, 'storeForEmployee'])
                ->middleware(['throttle:documents-writes', 'private-no-store'])
                ->name('schools.employees.documents.store');
            Route::get('/employees/{employee}/documents', [DocumentController::class, 'indexForEmployee'])
                ->middleware(['throttle:documents-reads', 'private-no-store'])
                ->name('schools.employees.documents.index');
            Route::get('/employees/{employee}/documents/sensitive', [DocumentController::class, 'sensitiveIndexForEmployee'])
                ->middleware(['throttle:documents-sensitive-reads', 'private-no-store'])
                ->name('schools.employees.documents.sensitive');

            // Direct by-id Document routes. `documents-sensitive-reads`
            // (the stricter bound) is applied to the metadata route
            // uniformly, regardless of a given Document's actual
            // classification tier -- the route cannot know the tier
            // before DocumentReadService resolves it, and varying the
            // limiter by hidden classification would itself be a side
            // channel (docs/modules/DOCUMENTS.md "Rate limiting").
            // Content streaming gets its own dedicated, equally strict
            // `documents-content` limiter -- transferring actual file
            // bytes is more expensive than any metadata-only read.
            Route::get('/documents/{document}', [DocumentController::class, 'show'])
                ->middleware(['throttle:documents-sensitive-reads', 'private-no-store'])
                ->name('schools.documents.show');
            Route::get('/documents/{document}/content', [DocumentController::class, 'content'])
                ->middleware(['throttle:documents-content', 'private-no-store'])
                ->name('schools.documents.content');
            Route::post('/documents/{document}/archive', [DocumentController::class, 'archive'])
                ->middleware(['throttle:documents-writes', 'private-no-store'])
                ->name('schools.documents.archive');

            // --- Phase 1D.5: Admissions administrative HTTP surface
            // (docs/admissions/PHASE-1D-5-ADMINISTRATIVE-API.md). Exposes
            // the already-built Admissions domain (Phase 1D.1-1D.4) --
            // it does not redesign it. Read actions authorize via
            // AuthorizesCapability inline (matching every controller
            // above); every mutation ALSO carries the `capability:`
            // route middleware (defense in depth). `admissions.manage`
            // gates every write, including conversion -- no separate
            // `admissions.convert`/`.accept`/etc. capability, mirroring
            // `enrollments.manage` covering its whole lifecycle as one
            // capability. Every consequential create/state-transition
            // endpoint carries `idempotent`, matching
            // StudentEnrollmentController/AcademicYearController's
            // identical treatment of their own lifecycle actions.
            // Nested for the Applicant's application history (an
            // Applicant's own reapplication history naturally belongs
            // to one Applicant, the same "nested for per-parent reads"
            // shape `/students/{student}/enrollments` already
            // established); flat for the Application directory/detail/
            // lifecycle/conversion actions.

            Route::get('/applicants', [ApplicantController::class, 'index'])
                ->name('schools.applicants.index');
            Route::post('/applicants', [ApplicantController::class, 'store'])
                ->middleware(['capability:admissions.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.applicants.store');
            Route::get('/applicants/{applicant}', [ApplicantController::class, 'show'])
                ->name('schools.applicants.show');
            Route::get('/applicants/{applicant}/applications', [ApplicantController::class, 'applications'])
                ->name('schools.applicants.applications.index');

            Route::get('/admission-applications', [AdmissionApplicationController::class, 'index'])
                ->name('schools.admission-applications.index');
            Route::post('/admission-applications', [AdmissionApplicationController::class, 'store'])
                ->middleware(['capability:admissions.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.admission-applications.store');
            Route::get('/admission-applications/{admissionApplication}', [AdmissionApplicationController::class, 'show'])
                ->name('schools.admission-applications.show');

            Route::post('/admission-applications/{admissionApplication}/submit', [AdmissionApplicationController::class, 'submit'])
                ->middleware(['capability:admissions.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.admission-applications.submit');
            Route::post('/admission-applications/{admissionApplication}/accept', [AdmissionApplicationController::class, 'accept'])
                ->middleware(['capability:admissions.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.admission-applications.accept');
            Route::post('/admission-applications/{admissionApplication}/reject', [AdmissionApplicationController::class, 'reject'])
                ->middleware(['capability:admissions.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.admission-applications.reject');
            Route::post('/admission-applications/{admissionApplication}/withdraw', [AdmissionApplicationController::class, 'withdraw'])
                ->middleware(['capability:admissions.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.admission-applications.withdraw');

            // Highest-stakes Admissions mutation (creates Student +
            // Enrollment + optional Guardian/relationship in one call)
            // -- `idempotent` here is genuine defense-in-depth ON TOP
            // OF AdmissionConversionService's own domain-level
            // idempotency (locked-row + AdmissionApplicationAlreadyConvertedException),
            // matching AcademicYearController::activate()'s identical
            // "both layers" pattern.
            Route::post('/admission-applications/{admissionApplication}/convert', [AdmissionApplicationController::class, 'convert'])
                ->middleware(['capability:admissions.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.admission-applications.convert');

            // --- Phase 10A: Library catalogue (Title/Copy) and
            // circulation (Loan). `idempotent` is applied only to
            // checkout (the one consequential mutation a network retry
            // could plausibly duplicate as a SECOND loan attempt on the
            // same copy -- see docs/modules/LIBRARY.md "Idempotency
            // decision") -- Title/Copy creation rely on their own
            // unique-code constraint for retry-safety, matching
            // Subject/Campus's identical precedent; check-in relies on
            // its own conditional-update rejection
            // (LoanAlreadyReturnedException) for the same reason
            // AcademicYear's second-decision case does not need
            // `idempotent` either.
            Route::get('/library-titles', [LibraryTitleController::class, 'index'])
                ->name('schools.library-titles.index');
            Route::post('/library-titles', [LibraryTitleController::class, 'store'])
                ->middleware(['capability:library.catalogue.manage', 'throttle:school-api-mutations'])
                ->name('schools.library-titles.store');
            Route::get('/library-titles/{libraryTitle}', [LibraryTitleController::class, 'show'])
                ->name('schools.library-titles.show');
            Route::patch('/library-titles/{libraryTitle}', [LibraryTitleController::class, 'update'])
                ->middleware(['capability:library.catalogue.manage', 'throttle:school-api-mutations'])
                ->name('schools.library-titles.update');

            Route::get('/library-titles/{libraryTitle}/copies', [LibraryCopyController::class, 'index'])
                ->name('schools.library-titles.copies.index');
            Route::post('/library-titles/{libraryTitle}/copies', [LibraryCopyController::class, 'store'])
                ->middleware(['capability:library.catalogue.manage', 'throttle:school-api-mutations'])
                ->name('schools.library-titles.copies.store');
            Route::patch('/library-copies/{libraryCopy}', [LibraryCopyController::class, 'update'])
                ->middleware(['capability:library.catalogue.manage', 'throttle:school-api-mutations'])
                ->name('schools.library-copies.update');

            Route::get('/library-loans', [LibraryLoanController::class, 'index'])
                ->name('schools.library-loans.index');
            Route::post('/library-loans', [LibraryLoanController::class, 'store'])
                ->middleware(['capability:library.circulation.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.library-loans.store');
            Route::get('/library-loans/{libraryLoan}', [LibraryLoanController::class, 'show'])
                ->name('schools.library-loans.show');
            Route::post('/library-loans/{libraryLoan}/check-in', [LibraryLoanController::class, 'checkIn'])
                ->middleware(['capability:library.circulation.manage', 'throttle:school-api-mutations'])
                ->name('schools.library-loans.check-in');

            // --- Phase 10B: Transport (Routes/Stops, Vehicles, Route
            // operational Vehicle/Driver assignment, Student Transport
            // assignment). `idempotent` is applied only to the two
            // "assign" mutations (Route operational assignment,
            // Student assignment) -- a network retry of either could
            // otherwise either silently duplicate an operational
            // reassignment (auto-replace: ending the just-created
            // record and creating a second one) or return a confusing
            // rejection for what the client experienced as a lost
            // response to an already-successful assignment -- the
            // exact reasoning LibraryLoanController::store() already
            // established for checkout. Route/Stop/Vehicle creation
            // and both end() actions rely on their own unique-
            // constraint/conditional-update retry-safety, matching
            // Library's Title/Copy creation and checkIn() precedent.
            Route::get('/transport-routes', [TransportRouteController::class, 'index'])
                ->name('schools.transport-routes.index');
            Route::post('/transport-routes', [TransportRouteController::class, 'store'])
                ->middleware(['capability:transport.routes.manage', 'throttle:school-api-mutations'])
                ->name('schools.transport-routes.store');
            Route::get('/transport-routes/{transportRoute}', [TransportRouteController::class, 'show'])
                ->name('schools.transport-routes.show');
            Route::patch('/transport-routes/{transportRoute}', [TransportRouteController::class, 'update'])
                ->middleware(['capability:transport.routes.manage', 'throttle:school-api-mutations'])
                ->name('schools.transport-routes.update');

            Route::get('/transport-routes/{transportRoute}/stops', [TransportStopController::class, 'index'])
                ->name('schools.transport-routes.stops.index');
            Route::post('/transport-routes/{transportRoute}/stops', [TransportStopController::class, 'store'])
                ->middleware(['capability:transport.routes.manage', 'throttle:school-api-mutations'])
                ->name('schools.transport-routes.stops.store');
            Route::patch('/transport-stops/{transportStop}', [TransportStopController::class, 'update'])
                ->middleware(['capability:transport.routes.manage', 'throttle:school-api-mutations'])
                ->name('schools.transport-stops.update');

            Route::get('/transport-vehicles', [TransportVehicleController::class, 'index'])
                ->name('schools.transport-vehicles.index');
            Route::post('/transport-vehicles', [TransportVehicleController::class, 'store'])
                ->middleware(['capability:transport.vehicles.manage', 'throttle:school-api-mutations'])
                ->name('schools.transport-vehicles.store');
            Route::get('/transport-vehicles/{transportVehicle}', [TransportVehicleController::class, 'show'])
                ->name('schools.transport-vehicles.show');
            Route::patch('/transport-vehicles/{transportVehicle}', [TransportVehicleController::class, 'update'])
                ->middleware(['capability:transport.vehicles.manage', 'throttle:school-api-mutations'])
                ->name('schools.transport-vehicles.update');

            Route::get('/transport-routes/{transportRoute}/assignments', [TransportRouteAssignmentController::class, 'index'])
                ->name('schools.transport-routes.assignments.index');
            Route::post('/transport-routes/{transportRoute}/assignments', [TransportRouteAssignmentController::class, 'store'])
                ->middleware(['capability:transport.vehicles.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.transport-routes.assignments.store');
            Route::post('/transport-route-assignments/{transportRouteAssignment}/end', [TransportRouteAssignmentController::class, 'end'])
                ->middleware(['capability:transport.vehicles.manage', 'throttle:school-api-mutations'])
                ->name('schools.transport-route-assignments.end');

            Route::get('/transport-student-assignments', [TransportStudentAssignmentController::class, 'index'])
                ->name('schools.transport-student-assignments.index');
            Route::post('/transport-student-assignments', [TransportStudentAssignmentController::class, 'store'])
                ->middleware(['capability:transport.assignments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.transport-student-assignments.store');
            Route::get('/transport-student-assignments/{transportStudentAssignment}', [TransportStudentAssignmentController::class, 'show'])
                ->name('schools.transport-student-assignments.show');
            Route::post('/transport-student-assignments/{transportStudentAssignment}/end', [TransportStudentAssignmentController::class, 'end'])
                ->middleware(['capability:transport.assignments.manage', 'throttle:school-api-mutations'])
                ->name('schools.transport-student-assignments.end');

            // --- Phase 10C: Visitor (directory, check-in/check-out
            // Visit lifecycle). `idempotent` is applied only to the
            // check-in mutation -- a network retry could otherwise
            // silently create a second Visit row for the same physical
            // arrival -- the exact reasoning already established for
            // Library checkout and Transport's two "assign" mutations.
            // check-out deliberately does NOT carry idempotency: its
            // own conditional `UPDATE ... WHERE status = 'checked_in'`
            // already makes a repeated/retried request safe by
            // construction (docs/modules/VISITOR.md "Check-out
            // idempotency decision").
            Route::get('/visitors', [VisitorController::class, 'index'])
                ->name('schools.visitors.index');
            Route::post('/visitors', [VisitorController::class, 'store'])
                ->middleware(['capability:visitor.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.visitors.store');
            Route::get('/visitors/{visitor}', [VisitorController::class, 'show'])
                ->name('schools.visitors.show');
            Route::patch('/visitors/{visitor}', [VisitorController::class, 'update'])
                ->middleware(['capability:visitor.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.visitors.update');

            Route::get('/visitor-visits', [VisitorVisitController::class, 'index'])
                ->name('schools.visitor-visits.index');
            Route::post('/visitor-visits', [VisitorVisitController::class, 'store'])
                ->middleware(['capability:visitor.visits.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.visitor-visits.store');
            Route::get('/visitor-visits/{visitorVisit}', [VisitorVisitController::class, 'show'])
                ->name('schools.visitor-visits.show');
            Route::post('/visitor-visits/{visitorVisit}/end', [VisitorVisitController::class, 'end'])
                ->middleware(['capability:visitor.visits.manage', 'throttle:school-api-mutations'])
                ->name('schools.visitor-visits.end');

            // Phase 0G.6: Finance/Fees/Payments HTTP transport -- thin
            // controllers over the ALREADY-authorized 0G.2-0G.5
            // Application boundary (LedgerReadService/
            // LedgerAdministrationService, ChargeReadService/
            // ChargeAdministrationService, PaymentReadService), never
            // the trusted cores (LedgerService/ChargeService) or a raw
            // Eloquent model. `capability:` middleware here mirrors
            // AcademicYearController's own established double-check
            // convention -- the underlying Application service checks
            // the identical capability again before touching any row.
            // No `idempotent` middleware on any Finance mutation route
            // (rule 30/31): 0G.2/0G.4 explicitly deferred generic
            // ledger-posting/Charge-assessment HTTP idempotency, and
            // reversal/cancellation already have their own structural
            // at-most-once business semantics (409 on a repeat). No
            // Payment mutation route exists at all -- there is no
            // `finance.payments.manage` capability (0G.5); settlement
            // remains the trusted `PaymentProviderEventService`
            // boundary, reached only by a future provider adapter, not
            // by this human administrative API.
            Route::get('/ledger-accounts', [LedgerAccountController::class, 'index'])
                ->middleware('capability:finance.ledger.view')
                ->name('schools.ledger-accounts.index');

            Route::get('/journal-entries', [JournalEntryController::class, 'index'])
                ->middleware('capability:finance.ledger.view')
                ->name('schools.journal-entries.index');
            Route::post('/journal-entries', [JournalEntryController::class, 'store'])
                ->middleware(['capability:finance.ledger.post', 'throttle:school-api-mutations'])
                ->name('schools.journal-entries.store');
            Route::get('/journal-entries/{journalEntry}', [JournalEntryController::class, 'show'])
                ->middleware('capability:finance.ledger.view')
                ->name('schools.journal-entries.show');
            Route::post('/journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])
                ->middleware(['capability:finance.ledger.reverse', 'throttle:school-api-mutations'])
                ->name('schools.journal-entries.reverse');

            Route::get('/charges', [ChargeController::class, 'index'])
                ->middleware('capability:finance.charges.view')
                ->name('schools.charges.index');
            Route::post('/charges', [ChargeController::class, 'store'])
                ->middleware(['capability:finance.charges.manage', 'throttle:school-api-mutations'])
                ->name('schools.charges.store');
            Route::get('/charges/{charge}', [ChargeController::class, 'show'])
                ->middleware('capability:finance.charges.view')
                ->name('schools.charges.show');
            Route::post('/charges/{charge}/cancel', [ChargeController::class, 'cancel'])
                ->middleware(['capability:finance.charges.manage', 'throttle:school-api-mutations'])
                ->name('schools.charges.cancel');

            Route::get('/payments', [PaymentController::class, 'index'])
                ->middleware('capability:finance.payments.view')
                ->name('schools.payments.index');
            Route::get('/payments/{payment}', [PaymentController::class, 'show'])
                ->middleware('capability:finance.payments.view')
                ->name('schools.payments.show');

            // --- Phase 10D: Hostel (Hostel/Room/Bed directory, Student
            // Hostel residency lifecycle). `idempotent` is applied only
            // to the residency-assign mutation -- a network retry could
            // otherwise silently create a second residency row for the
            // same physical move-in, the exact reasoning already
            // established for Library checkout, Transport's two
            // "assign" mutations, and Visitor check-in. `end()`
            // deliberately does NOT carry idempotency: its own
            // conditional `UPDATE ... WHERE status = 'active'` already
            // makes a repeated/retried request safe by construction.
            Route::get('/hostels', [HostelController::class, 'index'])
                ->name('schools.hostels.index');
            Route::post('/hostels', [HostelController::class, 'store'])
                ->middleware(['capability:hostel.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.hostels.store');
            Route::get('/hostels/{hostel}', [HostelController::class, 'show'])
                ->name('schools.hostels.show');
            Route::patch('/hostels/{hostel}', [HostelController::class, 'update'])
                ->middleware(['capability:hostel.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.hostels.update');

            Route::get('/hostels/{hostel}/hostel-rooms', [HostelRoomController::class, 'index'])
                ->name('schools.hostels.hostel-rooms.index');
            Route::post('/hostels/{hostel}/hostel-rooms', [HostelRoomController::class, 'store'])
                ->middleware(['capability:hostel.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.hostels.hostel-rooms.store');
            Route::patch('/hostel-rooms/{hostelRoom}', [HostelRoomController::class, 'update'])
                ->middleware(['capability:hostel.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.hostel-rooms.update');

            Route::get('/hostel-rooms/{hostelRoom}/hostel-beds', [HostelBedController::class, 'index'])
                ->name('schools.hostel-rooms.hostel-beds.index');
            Route::post('/hostel-rooms/{hostelRoom}/hostel-beds', [HostelBedController::class, 'store'])
                ->middleware(['capability:hostel.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.hostel-rooms.hostel-beds.store');
            Route::patch('/hostel-beds/{hostelBed}', [HostelBedController::class, 'update'])
                ->middleware(['capability:hostel.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.hostel-beds.update');

            Route::get('/hostel-residency-assignments', [HostelResidencyAssignmentController::class, 'index'])
                ->name('schools.hostel-residency-assignments.index');
            Route::post('/hostel-residency-assignments', [HostelResidencyAssignmentController::class, 'store'])
                ->middleware(['capability:hostel.residency.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.hostel-residency-assignments.store');
            Route::get('/hostel-residency-assignments/{hostelResidencyAssignment}', [HostelResidencyAssignmentController::class, 'show'])
                ->name('schools.hostel-residency-assignments.show');
            Route::post('/hostel-residency-assignments/{hostelResidencyAssignment}/end', [HostelResidencyAssignmentController::class, 'end'])
                ->middleware(['capability:hostel.residency.manage', 'throttle:school-api-mutations'])
                ->name('schools.hostel-residency-assignments.end');
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
