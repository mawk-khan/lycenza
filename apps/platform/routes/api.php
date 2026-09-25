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
use App\Domain\Attendance\Http\Controllers\AttendanceSessionController;
use App\Domain\Canteen\Http\Controllers\CanteenBillingConfigurationController;
use App\Domain\Canteen\Http\Controllers\CanteenItemController;
use App\Domain\Canteen\Http\Controllers\CanteenOrderController;
use App\Domain\Canteen\Http\Controllers\CanteenOutletController;
use App\Domain\Canteen\Http\Controllers\CanteenRecipeController;
use App\Domain\CurriculumDelivery\Http\Controllers\CurriculumDeliveryController;
use App\Domain\Documents\Http\Controllers\DocumentController;
use App\Domain\Examinations\Http\Controllers\ExaminationController;
use App\Domain\Examinations\Http\Controllers\ExaminationPaperController;
use App\Domain\Examinations\Http\Controllers\GradeScaleController;
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
use App\Domain\HR\Http\Controllers\DepartmentController;
use App\Domain\HR\Http\Controllers\EmployeeActivityController;
use App\Domain\HR\Http\Controllers\EmployeeAddressController;
use App\Domain\HR\Http\Controllers\EmployeeAssignmentController;
use App\Domain\HR\Http\Controllers\EmployeeCategoryController;
use App\Domain\HR\Http\Controllers\EmployeeCertificationController;
use App\Domain\HR\Http\Controllers\EmployeeController;
use App\Domain\HR\Http\Controllers\EmployeeDirectoryController;
use App\Domain\HR\Http\Controllers\EmployeeDocumentController;
use App\Domain\HR\Http\Controllers\EmployeeEmergencyContactController;
use App\Domain\HR\Http\Controllers\EmployeeExperienceController;
use App\Domain\HR\Http\Controllers\EmployeeImportController;
use App\Domain\HR\Http\Controllers\EmployeeLifecycleController;
use App\Domain\HR\Http\Controllers\EmployeeNoteController;
use App\Domain\HR\Http\Controllers\EmployeePersonalDetailController;
use App\Domain\HR\Http\Controllers\EmployeeProfileController;
use App\Domain\HR\Http\Controllers\EmployeeQualificationController;
use App\Domain\HR\Http\Controllers\EmployeeSensitiveDocumentController;
use App\Domain\HR\Http\Controllers\EmploymentController;
use App\Domain\HR\Http\Controllers\PositionController;
use App\Domain\Inventory\Http\Controllers\InventoryItemController;
use App\Domain\Inventory\Http\Controllers\InventoryLocationController;
use App\Domain\Inventory\Http\Controllers\InventoryStockController;
use App\Domain\Library\Http\Controllers\LibraryCopyController;
use App\Domain\Library\Http\Controllers\LibraryLoanController;
use App\Domain\Library\Http\Controllers\LibraryTitleController;
use App\Domain\LMS\Http\Controllers\AssignmentController;
use App\Domain\LMS\Http\Controllers\LearningContentController;
use App\Domain\Payments\Http\Controllers\PaymentController;
use App\Domain\Payroll\Http\Controllers\CompensationAssignmentController;
use App\Domain\Payroll\Http\Controllers\PayrollAccountingConfigurationController;
use App\Domain\Payroll\Http\Controllers\PayrollPeriodController;
use App\Domain\Payroll\Http\Controllers\PayrollRunController;
use App\Domain\Payroll\Http\Controllers\PayrollRunPostingController;
use App\Domain\Payroll\Http\Controllers\PayslipController;
use App\Domain\Payroll\Http\Controllers\SalaryComponentController;
use App\Domain\Payroll\Http\Controllers\SalaryStructureController;
use App\Domain\Payroll\Statutory\Http\Controllers\StatutoryAccountingConfigurationController;
use App\Domain\Payroll\Statutory\Http\Controllers\StatutoryEsiCoverageController;
use App\Domain\Payroll\Statutory\Http\Controllers\StatutoryExportController;
use App\Domain\Payroll\Statutory\Http\Controllers\StatutoryIdentifierController;
use App\Domain\Payroll\Statutory\Http\Controllers\StatutoryPfStatusController;
use App\Domain\Payroll\Statutory\Http\Controllers\StatutoryRuleStatusController;
use App\Domain\Payroll\Statutory\Http\Controllers\StatutoryTaxProfileController;
use App\Domain\Students\Http\Controllers\EnrollmentRolloverController;
use App\Domain\Students\Http\Controllers\EnrollmentRolloverItemController;
use App\Domain\Students\Http\Controllers\EnrollmentRolloverMappingController;
use App\Domain\Students\Http\Controllers\EnrollmentRolloverSubjectMappingController;
use App\Domain\Students\Http\Controllers\StudentController;
use App\Domain\Students\Http\Controllers\StudentEnrollmentController;
use App\Domain\Students\Http\Controllers\StudentSubjectEnrollmentController;
use App\Domain\Syllabus\Http\Controllers\SyllabusUnitController;
use App\Domain\Timetable\Http\Controllers\TimetableEntryController;
use App\Domain\Timetable\Http\Controllers\TimetablePeriodController;
use App\Domain\Transport\Http\Controllers\TransportRouteAssignmentController;
use App\Domain\Transport\Http\Controllers\TransportRouteController;
use App\Domain\Transport\Http\Controllers\TransportStopController;
use App\Domain\Transport\Http\Controllers\TransportStudentAssignmentController;
use App\Domain\Transport\Http\Controllers\TransportVehicleController;
use App\Domain\Visitor\Http\Controllers\VisitorController;
use App\Domain\Visitor\Http\Controllers\VisitorVisitController;
use App\Http\Controllers\Api\Internal\AiAuditController;
use App\Http\Controllers\Api\Internal\AiCompletionAuthorizationController;
use App\Http\Controllers\Api\Internal\AiToolController;
use App\Http\Controllers\Api\Internal\HealthController;
use App\Http\Controllers\Api\Internal\OperationsController;
use App\Http\Controllers\Api\V1\CampusController;
use App\Http\Controllers\Api\V1\EducationBoardController;
use App\Http\Controllers\Api\V1\Internal\IdempotencyDemoController;
use App\Http\Controllers\Api\V1\Internal\WebhookTestEventController;
use App\Http\Controllers\Api\V1\Partner\PartnerProbeController;
use App\Http\Controllers\Api\V1\SchoolContextController;
use App\Http\Controllers\Api\V1\SchoolProfileController;
use App\Http\Controllers\Api\V1\SystemStatusController;
use App\Http\Controllers\Api\V1\WebhookDeliveryController;
use App\Http\Controllers\Api\V1\WebhookEndpointController;
use App\Http\Controllers\Api\V1\WebhookSubscriptionController;
use App\Http\Middleware\DevOnlySchoolHeaderResolver;
use App\Http\Middleware\ResolveSchoolContext;
use App\Support\Api\PartnerScopeRegistry;
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
            // Phase 0O.3 (ADR 0049 section 7): a person lookup, so the
            // stricter `api-sensitive-read` class (it was unthrottled).
            Route::post('/guardian-candidates', [GuardianController::class, 'candidates'])
                ->middleware('throttle:api-sensitive-read')
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

            // Phase 8A.14 as-built deliberately carried NO `capability:`
            // route middleware here, reasoning that each Application
            // service's own unconditional capability check made a route-
            // level check redundant or risk-of-drift. The Phase 8A
            // closure correction (item 9) revisits that call: every
            // OTHER module in this codebase (school settings, webhooks,
            // campuses, academic structure/years/subjects, enrollments,
            // enrollment-rollovers) uses defense-in-depth -- BOTH a
            // route-level `capability:` check AND the Application-layer
            // one -- and HR was the sole documented exception. Each of
            // these four services already enforces exactly ONE hard,
            // unconditional capability before doing anything else
            // (EmployeeDirectoryService::search() -> `hr.employees.view`;
            // EmployeeProfileWorkspaceService::build() and
            // EmployeeActivityTimelineService::forEmployee() ->
            // `hr.employees.personal.view`, with assignments/
            // qualifications/documents visibility separately tiered
            // WITHIN that floor, never below it;
            // EmployeeSensitiveDocumentReadService::forEmployee() ->
            // `hr.employees.sensitive.view`) -- so mirroring that exact
            // capability as route middleware is safe by construction: it
            // can never reject a request the Application layer would
            // have allowed, only fail the SAME check slightly earlier.
            // The Application-layer checks are kept exactly as they were
            // (never removed -- defense-in-depth, not a replacement).
            // `{employee}` remains a raw route-parameter string, never
            // implicit Eloquent route-model binding -- see each
            // controller's own docblock.
            //
            // Phase 8A.15: `throttle:hr-api-reads` (School+actor-keyed,
            // 120/min) and `private-no-store` (Cache-Control: private,
            // no-store -- this data must never become shared-cacheable)
            // added to all four; the sensitive-document endpoint uses
            // the stricter `throttle:hr-api-sensitive-reads` (20/min)
            // instead. See docs/modules/HR.md 8A.15 as-built.
            //
            // Phase 8A closure correction (item 9): `private-no-store` is
            // listed FIRST, not last -- middleware order is onion order
            // (the first entry is OUTERMOST), and `EnsurePrivateNoStoreResponse`
            // only sets its header on the response returned by `$next()`.
            // With the new `capability:` entry able to reject the request
            // and short-circuit BEFORE the controller ever runs, it must
            // be the INNER layer so `private-no-store` still wraps it and
            // still gets a response to attach its header to -- confirmed
            // empirically: `capability:` before `private-no-store` broke
            // `HrEmployeeApiCacheControlTest`'s "forbidden response still
            // carries the policy" case (the 403 arrived with Laravel's
            // ordinary default `Cache-Control`, not this middleware's).
            // The Application-layer 403 path (a service's own
            // `authorizeCapabilityFor()` throwing, reached only when the
            // route-level check passes) already worked correctly under
            // either order, since that exception originates from INSIDE
            // the controller, already nested under every route
            // middleware regardless of array position.
            Route::get('/employees', [EmployeeDirectoryController::class, 'index'])
                ->middleware(['private-no-store', 'capability:hr.employees.view', 'throttle:hr-api-reads'])
                ->name('schools.employees.index');
            Route::get('/employees/{employee}', [EmployeeProfileController::class, 'show'])
                ->middleware(['private-no-store', 'capability:hr.employees.personal.view', 'throttle:hr-api-reads'])
                ->name('schools.employees.show');
            Route::get('/employees/{employee}/activity', [EmployeeActivityController::class, 'index'])
                ->middleware(['private-no-store', 'capability:hr.employees.personal.view', 'throttle:hr-api-reads'])
                ->name('schools.employees.activity.index');
            Route::get('/employees/{employee}/sensitive-documents', [EmployeeSensitiveDocumentController::class, 'index'])
                ->middleware(['private-no-store', 'capability:hr.employees.sensitive.view', 'throttle:hr-api-sensitive-reads'])
                ->name('schools.employees.sensitive-documents.index');

            // --- Phase 8A closure correction (item 3): HR mutation
            // transport. Every route below carries `capability:` route
            // middleware mirroring the SINGLE unconditional capability
            // floor its Application-layer service already enforces
            // (item 9 -- defense-in-depth, matching Finance/Academic
            // Structure/Enrollments, never a replacement for the
            // service's own check). `store`-shaped creates and one-time
            // lifecycle-transition verbs (archive/reactivate/end/
            // separate/rehire/verify/reject/setPrimary/setManager/
            // reparent) carry `idempotent` -- a retried request must
            // replay the original result, never silently create a
            // second row or race a second transition. Plain field
            // `update` PATCHes do not (matches
            // AcademicYearController/DepartmentController-shape
            // precedent: re-submitting the same body is naturally
            // idempotent at the HTTP-semantic level already). `destroy`
            // actions do not either -- ordinary DELETE semantics already
            // tolerate a duplicate call safely. `{employee}`/nested ids
            // are always raw route-parameter strings resolved via a
            // tenant-scoped `findOrFail()`, never implicit Eloquent
            // route-model binding, matching every other HR controller.
            Route::post('/employees', [EmployeeController::class, 'store'])
                ->middleware(['capability:hr.employees.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.store');
            Route::patch('/employees/{employee}', [EmployeeController::class, 'update'])
                ->middleware(['capability:hr.employees.manage', 'throttle:school-api-mutations'])
                ->name('schools.employees.update');
            Route::post('/employees/{employee}/archive', [EmployeeController::class, 'archive'])
                ->middleware(['capability:hr.employees.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.archive');
            Route::post('/employees/{employee}/restore', [EmployeeController::class, 'restore'])
                ->middleware(['capability:hr.employees.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.restore');

            Route::put('/employees/{employee}/personal-detail', [EmployeePersonalDetailController::class, 'update'])
                ->middleware(['capability:hr.employees.personal.manage', 'throttle:school-api-mutations'])
                ->name('schools.employees.personal-detail.update');

            Route::post('/employees/{employee}/addresses', [EmployeeAddressController::class, 'store'])
                ->middleware(['capability:hr.employees.personal.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.addresses.store');
            Route::patch('/employees/{employee}/addresses/{address}', [EmployeeAddressController::class, 'update'])
                ->middleware(['capability:hr.employees.personal.manage', 'throttle:school-api-mutations'])
                ->name('schools.employees.addresses.update');
            Route::delete('/employees/{employee}/addresses/{address}', [EmployeeAddressController::class, 'destroy'])
                ->middleware(['capability:hr.employees.personal.manage', 'throttle:school-api-mutations'])
                ->name('schools.employees.addresses.destroy');

            Route::post('/employees/{employee}/emergency-contacts', [EmployeeEmergencyContactController::class, 'store'])
                ->middleware(['capability:hr.employees.personal.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.emergency-contacts.store');
            Route::patch('/employees/{employee}/emergency-contacts/{contact}', [EmployeeEmergencyContactController::class, 'update'])
                ->middleware(['capability:hr.employees.personal.manage', 'throttle:school-api-mutations'])
                ->name('schools.employees.emergency-contacts.update');
            Route::delete('/employees/{employee}/emergency-contacts/{contact}', [EmployeeEmergencyContactController::class, 'destroy'])
                ->middleware(['capability:hr.employees.personal.manage', 'throttle:school-api-mutations'])
                ->name('schools.employees.emergency-contacts.destroy');
            Route::post('/employees/{employee}/emergency-contacts/{contact}/primary', [EmployeeEmergencyContactController::class, 'setPrimary'])
                ->middleware(['capability:hr.employees.personal.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.emergency-contacts.set-primary');

            // Phase 8A closure correction (item 5): EmployeeNote mutation
            // transport, gated by the `hr.employees.notes.*` pair
            // pre-registered at 8A.10 and finally attached to a real
            // feature by this correction.
            Route::post('/employees/{employee}/notes', [EmployeeNoteController::class, 'store'])
                ->middleware(['capability:hr.employees.notes.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.notes.store');
            Route::patch('/employees/{employee}/notes/{note}', [EmployeeNoteController::class, 'update'])
                ->middleware(['capability:hr.employees.notes.manage', 'throttle:school-api-mutations'])
                ->name('schools.employees.notes.update');
            Route::delete('/employees/{employee}/notes/{note}', [EmployeeNoteController::class, 'destroy'])
                ->middleware(['capability:hr.employees.notes.manage', 'throttle:school-api-mutations'])
                ->name('schools.employees.notes.destroy');

            Route::post('/employees/{employee}/employment-records', [EmploymentController::class, 'store'])
                ->middleware(['capability:hr.employees.assignments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.employment-records.store');
            Route::patch('/employees/{employee}/employment-records/{employment}', [EmploymentController::class, 'update'])
                ->middleware(['capability:hr.employees.assignments.manage', 'throttle:school-api-mutations'])
                ->name('schools.employees.employment-records.update');
            Route::post('/employees/{employee}/employment-records/{employment}/end', [EmploymentController::class, 'end'])
                ->middleware(['capability:hr.employees.assignments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.employment-records.end');
            Route::post('/employees/{employee}/employment-records/{employment}/separate', [EmployeeLifecycleController::class, 'separate'])
                ->middleware(['capability:hr.employees.assignments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.employment-records.separate');

            Route::post('/employees/{employee}/employment-records/{employment}/assignments', [EmployeeAssignmentController::class, 'store'])
                ->middleware(['capability:hr.employees.assignments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.assignments.store');
            Route::post('/employees/{employee}/employment-records/{employment}/assignments/{assignment}/end', [EmployeeAssignmentController::class, 'end'])
                ->middleware(['capability:hr.employees.assignments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.assignments.end');
            Route::post('/employees/{employee}/employment-records/{employment}/assignments/{assignment}/primary', [EmployeeAssignmentController::class, 'setPrimary'])
                ->middleware(['capability:hr.employees.assignments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.assignments.set-primary');
            Route::post('/employees/{employee}/employment-records/{employment}/assignments/{assignment}/manager', [EmployeeAssignmentController::class, 'setManager'])
                ->middleware(['capability:hr.employees.assignments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.assignments.set-manager');

            Route::post('/employees/{employee}/rehire', [EmployeeLifecycleController::class, 'rehire'])
                ->middleware(['capability:hr.employees.assignments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.rehire');

            Route::post('/employees/{employee}/qualifications', [EmployeeQualificationController::class, 'store'])
                ->middleware(['capability:hr.employees.qualifications.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.qualifications.store');
            Route::patch('/employees/{employee}/qualifications/{qualification}', [EmployeeQualificationController::class, 'update'])
                ->middleware(['capability:hr.employees.qualifications.manage', 'throttle:school-api-mutations'])
                ->name('schools.employees.qualifications.update');
            Route::delete('/employees/{employee}/qualifications/{qualification}', [EmployeeQualificationController::class, 'destroy'])
                ->middleware(['capability:hr.employees.qualifications.manage', 'throttle:school-api-mutations'])
                ->name('schools.employees.qualifications.destroy');
            Route::post('/employees/{employee}/qualifications/{qualification}/verify', [EmployeeQualificationController::class, 'verify'])
                ->middleware(['capability:hr.employees.qualifications.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.qualifications.verify');
            Route::post('/employees/{employee}/qualifications/{qualification}/reject', [EmployeeQualificationController::class, 'reject'])
                ->middleware(['capability:hr.employees.qualifications.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.qualifications.reject');

            Route::post('/employees/{employee}/experience', [EmployeeExperienceController::class, 'store'])
                ->middleware(['capability:hr.employees.qualifications.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.experience.store');
            Route::patch('/employees/{employee}/experience/{experience}', [EmployeeExperienceController::class, 'update'])
                ->middleware(['capability:hr.employees.qualifications.manage', 'throttle:school-api-mutations'])
                ->name('schools.employees.experience.update');
            Route::delete('/employees/{employee}/experience/{experience}', [EmployeeExperienceController::class, 'destroy'])
                ->middleware(['capability:hr.employees.qualifications.manage', 'throttle:school-api-mutations'])
                ->name('schools.employees.experience.destroy');

            Route::post('/employees/{employee}/certifications', [EmployeeCertificationController::class, 'store'])
                ->middleware(['capability:hr.employees.qualifications.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.certifications.store');
            Route::patch('/employees/{employee}/certifications/{certification}', [EmployeeCertificationController::class, 'update'])
                ->middleware(['capability:hr.employees.qualifications.manage', 'throttle:school-api-mutations'])
                ->name('schools.employees.certifications.update');
            Route::delete('/employees/{employee}/certifications/{certification}', [EmployeeCertificationController::class, 'destroy'])
                ->middleware(['capability:hr.employees.qualifications.manage', 'throttle:school-api-mutations'])
                ->name('schools.employees.certifications.destroy');
            Route::post('/employees/{employee}/certifications/{certification}/verify', [EmployeeCertificationController::class, 'verify'])
                ->middleware(['capability:hr.employees.qualifications.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.certifications.verify');
            Route::post('/employees/{employee}/certifications/{certification}/reject', [EmployeeCertificationController::class, 'reject'])
                ->middleware(['capability:hr.employees.qualifications.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.certifications.reject');

            // `hr-document-records` (NOT `documents`) -- deliberately
            // distinct from the Documents module's own
            // `/employees/{employee}/documents` routes immediately below
            // in this file (0E.5, a separate generic file-metadata
            // system). No blanket `capability:` route middleware here --
            // unlike every other block above, EmployeeDocumentService's
            // required capability is tier-dependent
            // (`hr.employees.documents.manage` vs
            // `hr.employees.sensitive.manage`, decided per-call by
            // `assertClassificationCapability()`), so no single
            // unconditional floor exists that could be safely enforced
            // at the route layer without risking a false rejection for
            // an actor who legitimately holds only the sensitive-tier
            // capability. The Application-layer check remains the sole
            // authority here, exactly like the Employee Profile
            // Workspace's own per-section tiered capabilities.
            Route::post('/employees/{employee}/hr-document-records', [EmployeeDocumentController::class, 'store'])
                ->middleware(['throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.hr-document-records.store');
            Route::patch('/employees/{employee}/hr-document-records/{document}', [EmployeeDocumentController::class, 'update'])
                ->middleware(['throttle:school-api-mutations'])
                ->name('schools.employees.hr-document-records.update');
            Route::post('/employees/{employee}/hr-document-records/{document}/archive', [EmployeeDocumentController::class, 'archive'])
                ->middleware(['throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employees.hr-document-records.archive');

            Route::post('/employee-imports', [EmployeeImportController::class, 'store'])
                ->middleware(['capability:hr.employees.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employee-imports.store');

            // Department/Position/EmployeeCategory: top-level School-
            // scoped HR reference data, not Employee-nested -- see
            // DepartmentController's own docblock for why these three
            // controllers authorize inline (in addition to this route
            // middleware) rather than relying on the service check
            // alone.
            Route::get('/hr-departments', [DepartmentController::class, 'index'])
                ->name('schools.hr-departments.index');
            Route::post('/hr-departments', [DepartmentController::class, 'store'])
                ->middleware(['capability:hr.departments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.hr-departments.store');
            Route::patch('/hr-departments/{department}', [DepartmentController::class, 'update'])
                ->middleware(['capability:hr.departments.manage', 'throttle:school-api-mutations'])
                ->name('schools.hr-departments.update');
            Route::post('/hr-departments/{department}/archive', [DepartmentController::class, 'archive'])
                ->middleware(['capability:hr.departments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.hr-departments.archive');
            Route::post('/hr-departments/{department}/reactivate', [DepartmentController::class, 'reactivate'])
                ->middleware(['capability:hr.departments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.hr-departments.reactivate');
            Route::post('/hr-departments/{department}/reparent', [DepartmentController::class, 'reparent'])
                ->middleware(['capability:hr.departments.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.hr-departments.reparent');

            Route::get('/positions', [PositionController::class, 'index'])
                ->name('schools.positions.index');
            Route::post('/positions', [PositionController::class, 'store'])
                ->middleware(['capability:hr.positions.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.positions.store');
            Route::patch('/positions/{position}', [PositionController::class, 'update'])
                ->middleware(['capability:hr.positions.manage', 'throttle:school-api-mutations'])
                ->name('schools.positions.update');
            Route::post('/positions/{position}/archive', [PositionController::class, 'archive'])
                ->middleware(['capability:hr.positions.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.positions.archive');
            Route::post('/positions/{position}/reactivate', [PositionController::class, 'reactivate'])
                ->middleware(['capability:hr.positions.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.positions.reactivate');

            Route::get('/employee-categories', [EmployeeCategoryController::class, 'index'])
                ->name('schools.employee-categories.index');
            Route::post('/employee-categories', [EmployeeCategoryController::class, 'store'])
                ->middleware(['capability:hr.categories.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employee-categories.store');
            Route::patch('/employee-categories/{category}', [EmployeeCategoryController::class, 'update'])
                ->middleware(['capability:hr.categories.manage', 'throttle:school-api-mutations'])
                ->name('schools.employee-categories.update');
            Route::post('/employee-categories/{category}/archive', [EmployeeCategoryController::class, 'archive'])
                ->middleware(['capability:hr.categories.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employee-categories.archive');
            Route::post('/employee-categories/{category}/reactivate', [EmployeeCategoryController::class, 'reactivate'])
                ->middleware(['capability:hr.categories.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.employee-categories.reactivate');

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

            // Phase 1G.4: subject-mapping configuration -- addressed by
            // the SOURCE SubjectOffering id directly (the natural
            // operator identity, matching `enrollment_rollover_subject_mappings`'
            // own (plan_id, source_subject_offering_id) uniqueness) --
            // never an internal mapping row UUID. PUT with
            // `target_subject_offering_id: null` means EXPLICIT OMIT;
            // DELETE means UNCONFIGURED (no row at all) -- the two are
            // never conflated. Read access to the mapping list/discovery
            // remains embedded in the Plan detail response above (no
            // separate list endpoint), matching the existing Grade/
            // Section mappings' identical embedding.
            Route::put('/enrollment-rollovers/{rollover}/subject-mappings/{subjectOffering}', [EnrollmentRolloverSubjectMappingController::class, 'upsert'])
                ->middleware(['capability:enrollments.manage', 'capability:enrollments.rollovers.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.enrollment-rollovers.subject-mappings.upsert');
            // No `idempotent` middleware -- EnrollmentRolloverPlanService::removeSubjectMapping()
            // is ITSELF a true no-op against an already-unconfigured
            // source Offering (Phase 1G.1), the same natural-idempotency
            // rationale StudentGuardianRelationshipController::destroy()'s
            // route already documents above.
            Route::delete('/enrollment-rollovers/{rollover}/subject-mappings/{subjectOffering}', [EnrollmentRolloverSubjectMappingController::class, 'destroy'])
                ->middleware(['capability:enrollments.manage', 'capability:enrollments.rollovers.manage', 'throttle:school-api-mutations'])
                ->name('schools.enrollment-rollovers.subject-mappings.destroy');

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

            // --- Phase 10E: Inventory (Item/Location directory,
            // quantity stock lifecycle). `receive`/`issue`/`transfer`
            // carry `idempotent` -- each creates a new immutable
            // StockMovement and mutates a StockBalance; a network
            // retry could otherwise duplicate the stock effect, the
            // same reasoning already established for Library checkout,
            // Transport's assign mutations, Visitor check-in, and
            // Hostel residency assignment. No generic
            // `POST /stock-movements` exists -- only these three
            // explicit-intent commands, each backed by
            // InventoryStockService.
            Route::get('/inventory-items', [InventoryItemController::class, 'index'])
                ->name('schools.inventory-items.index');
            Route::post('/inventory-items', [InventoryItemController::class, 'store'])
                ->middleware(['capability:inventory.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.inventory-items.store');
            Route::get('/inventory-items/{inventoryItem}', [InventoryItemController::class, 'show'])
                ->name('schools.inventory-items.show');
            Route::patch('/inventory-items/{inventoryItem}', [InventoryItemController::class, 'update'])
                ->middleware(['capability:inventory.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.inventory-items.update');

            Route::get('/inventory-locations', [InventoryLocationController::class, 'index'])
                ->name('schools.inventory-locations.index');
            Route::post('/inventory-locations', [InventoryLocationController::class, 'store'])
                ->middleware(['capability:inventory.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.inventory-locations.store');
            Route::get('/inventory-locations/{inventoryLocation}', [InventoryLocationController::class, 'show'])
                ->name('schools.inventory-locations.show');
            Route::patch('/inventory-locations/{inventoryLocation}', [InventoryLocationController::class, 'update'])
                ->middleware(['capability:inventory.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.inventory-locations.update');

            Route::get('/inventory-stock', [InventoryStockController::class, 'index'])
                ->name('schools.inventory-stock.index');
            Route::get('/inventory-stock/movements', [InventoryStockController::class, 'movements'])
                ->name('schools.inventory-stock.movements');
            Route::post('/inventory-stock/receive', [InventoryStockController::class, 'receive'])
                ->middleware(['capability:inventory.stock.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.inventory-stock.receive');
            Route::post('/inventory-stock/issue', [InventoryStockController::class, 'issue'])
                ->middleware(['capability:inventory.stock.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.inventory-stock.issue');
            Route::post('/inventory-stock/transfer', [InventoryStockController::class, 'transfer'])
                ->middleware(['capability:inventory.stock.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.inventory-stock.transfer');

            // Phase 10F: Canteen (Outlet/Item/recipe directory, billing
            // configuration, Order lifecycle). `place`/`fulfill` carry
            // `idempotent` -- each creates/mutates a Charge and, for
            // `fulfill`, Inventory stock; a network retry could
            // otherwise duplicate either effect, the same reasoning
            // already established for every other consequential
            // mutation in this file. `cancel` does NOT carry
            // idempotency -- its own conditional lifecycle transition
            // (only a pending Order may transition) already makes a
            // repeated/retried request safe by construction, exactly
            // like Hostel residency `end()`'s own precedent. Helper
            // search endpoints (`search/students`, `search/items`,
            // `ledger-accounts`) are gated by capability BEFORE any
            // query executes.
            Route::get('/canteen-outlets', [CanteenOutletController::class, 'index'])
                ->name('schools.canteen-outlets.index');
            Route::post('/canteen-outlets', [CanteenOutletController::class, 'store'])
                ->middleware(['capability:canteen.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.canteen-outlets.store');
            Route::get('/canteen-outlets/{canteenOutlet}', [CanteenOutletController::class, 'show'])
                ->name('schools.canteen-outlets.show');
            Route::patch('/canteen-outlets/{canteenOutlet}', [CanteenOutletController::class, 'update'])
                ->middleware(['capability:canteen.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.canteen-outlets.update');

            Route::get('/canteen-items', [CanteenItemController::class, 'index'])
                ->name('schools.canteen-items.index');
            Route::post('/canteen-items', [CanteenItemController::class, 'store'])
                ->middleware(['capability:canteen.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.canteen-items.store');
            Route::get('/canteen-items/{canteenItem}', [CanteenItemController::class, 'show'])
                ->name('schools.canteen-items.show');
            Route::patch('/canteen-items/{canteenItem}', [CanteenItemController::class, 'update'])
                ->middleware(['capability:canteen.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.canteen-items.update');

            Route::get('/canteen-items/{canteenItem}/recipe', [CanteenRecipeController::class, 'index'])
                ->name('schools.canteen-items.recipe.index');
            Route::post('/canteen-items/{canteenItem}/recipe', [CanteenRecipeController::class, 'store'])
                ->middleware(['capability:canteen.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.canteen-items.recipe.store');
            Route::patch('/canteen-items/{canteenItem}/recipe/{requirement}', [CanteenRecipeController::class, 'update'])
                ->middleware(['capability:canteen.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.canteen-items.recipe.update');
            Route::delete('/canteen-items/{canteenItem}/recipe/{requirement}', [CanteenRecipeController::class, 'destroy'])
                ->middleware(['capability:canteen.directory.manage', 'throttle:school-api-mutations'])
                ->name('schools.canteen-items.recipe.destroy');

            Route::get('/canteen-billing-configuration', [CanteenBillingConfigurationController::class, 'show'])
                ->middleware('capability:canteen.settings.view')
                ->name('schools.canteen-billing-configuration.show');
            Route::put('/canteen-billing-configuration', [CanteenBillingConfigurationController::class, 'update'])
                ->middleware(['capability:canteen.settings.manage', 'throttle:school-api-mutations'])
                ->name('schools.canteen-billing-configuration.update');
            Route::get('/canteen-billing-configuration/ledger-accounts', [CanteenBillingConfigurationController::class, 'ledgerAccounts'])
                ->middleware('capability:canteen.settings.manage')
                ->name('schools.canteen-billing-configuration.ledger-accounts');

            Route::get('/canteen-orders', [CanteenOrderController::class, 'index'])
                ->middleware('capability:canteen.orders.view')
                ->name('schools.canteen-orders.index');
            Route::post('/canteen-orders', [CanteenOrderController::class, 'store'])
                ->middleware(['capability:canteen.orders.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.canteen-orders.store');
            Route::get('/canteen-orders/search/students', [CanteenOrderController::class, 'searchStudents'])
                ->middleware('capability:canteen.orders.view')
                ->name('schools.canteen-orders.search-students');
            Route::get('/canteen-orders/search/items', [CanteenOrderController::class, 'searchItems'])
                ->middleware('capability:canteen.orders.view')
                ->name('schools.canteen-orders.search-items');
            Route::get('/canteen-orders/{canteenOrder}', [CanteenOrderController::class, 'show'])
                ->middleware('capability:canteen.orders.view')
                ->name('schools.canteen-orders.show');
            Route::post('/canteen-orders/{canteenOrder}/fulfill', [CanteenOrderController::class, 'fulfill'])
                ->middleware(['capability:canteen.orders.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.canteen-orders.fulfill');
            Route::post('/canteen-orders/{canteenOrder}/cancel', [CanteenOrderController::class, 'cancel'])
                ->middleware(['capability:canteen.orders.manage', 'throttle:school-api-mutations'])
                ->name('schools.canteen-orders.cancel');

            // Phase 0H: Timetable (Period catalogue + weekly schedule
            // Entries). `create`/`update`/`activate` are NOT idempotent
            // -- every double-booking effect (teacher/Section/Room, per
            // day-of-week + Period) is already prevented at the database
            // layer by three partial unique indexes
            // (`create_timetable_entries_table` migration), so a network
            // retry of an identical request can never duplicate the
            // scheduling effect; the worst case is a clean, typed 409
            // the client already needs to handle for a genuine conflict
            // anyway. This mirrors AcademicYear activation's identical
            // "protected by a database uniqueness guarantee, so no
            // `idempotent` middleware needed" precedent. `deactivate` is
            // a conditional status flip, safe to retry by construction,
            // exactly like Hostel residency `end()`. Helper search
            // endpoints (`search/*`) are gated by capability BEFORE any
            // query executes -- Timetable's OWN capabilities only, never
            // Academic Structure's `academics.*` or HR's employee-
            // management capabilities (the Canteen capability-boundary
            // lesson, carried forward explicitly).
            Route::get('/timetable-periods', [TimetablePeriodController::class, 'index'])
                ->middleware('capability:timetable.periods.view')
                ->name('schools.timetable-periods.index');
            Route::post('/timetable-periods', [TimetablePeriodController::class, 'store'])
                ->middleware(['capability:timetable.periods.manage', 'throttle:school-api-mutations'])
                ->name('schools.timetable-periods.store');
            Route::get('/timetable-periods/{timetablePeriod}', [TimetablePeriodController::class, 'show'])
                ->middleware('capability:timetable.periods.view')
                ->name('schools.timetable-periods.show');
            Route::patch('/timetable-periods/{timetablePeriod}', [TimetablePeriodController::class, 'update'])
                ->middleware(['capability:timetable.periods.manage', 'throttle:school-api-mutations'])
                ->name('schools.timetable-periods.update');
            Route::post('/timetable-periods/{timetablePeriod}/activate', [TimetablePeriodController::class, 'activate'])
                ->middleware(['capability:timetable.periods.manage', 'throttle:school-api-mutations'])
                ->name('schools.timetable-periods.activate');
            Route::post('/timetable-periods/{timetablePeriod}/deactivate', [TimetablePeriodController::class, 'deactivate'])
                ->middleware(['capability:timetable.periods.manage', 'throttle:school-api-mutations'])
                ->name('schools.timetable-periods.deactivate');

            Route::get('/timetable-entries', [TimetableEntryController::class, 'index'])
                ->middleware('capability:timetable.schedule.view')
                ->name('schools.timetable-entries.index');
            Route::post('/timetable-entries', [TimetableEntryController::class, 'store'])
                ->middleware(['capability:timetable.schedule.manage', 'throttle:school-api-mutations'])
                ->name('schools.timetable-entries.store');
            Route::get('/timetable-entries/search/subject-offerings', [TimetableEntryController::class, 'searchSubjectOfferings'])
                ->middleware('capability:timetable.schedule.manage')
                ->name('schools.timetable-entries.search-subject-offerings');
            Route::get('/timetable-entries/search/sections', [TimetableEntryController::class, 'searchSections'])
                ->middleware('capability:timetable.schedule.manage')
                ->name('schools.timetable-entries.search-sections');
            Route::get('/timetable-entries/search/teachers', [TimetableEntryController::class, 'searchTeachers'])
                ->middleware('capability:timetable.schedule.manage')
                ->name('schools.timetable-entries.search-teachers');
            Route::get('/timetable-entries/search/rooms', [TimetableEntryController::class, 'searchRooms'])
                ->middleware('capability:timetable.schedule.manage')
                ->name('schools.timetable-entries.search-rooms');
            Route::get('/timetable-entries/search/periods', [TimetableEntryController::class, 'searchPeriods'])
                ->middleware('capability:timetable.periods.view')
                ->name('schools.timetable-entries.search-periods');
            Route::get('/timetable-entries/{timetableEntry}', [TimetableEntryController::class, 'show'])
                ->middleware('capability:timetable.schedule.view')
                ->name('schools.timetable-entries.show');
            Route::patch('/timetable-entries/{timetableEntry}', [TimetableEntryController::class, 'update'])
                ->middleware(['capability:timetable.schedule.manage', 'throttle:school-api-mutations'])
                ->name('schools.timetable-entries.update');
            Route::post('/timetable-entries/{timetableEntry}/activate', [TimetableEntryController::class, 'activate'])
                ->middleware(['capability:timetable.schedule.manage', 'throttle:school-api-mutations'])
                ->name('schools.timetable-entries.activate');
            Route::post('/timetable-entries/{timetableEntry}/deactivate', [TimetableEntryController::class, 'deactivate'])
                ->middleware(['capability:timetable.schedule.manage', 'throttle:school-api-mutations'])
                ->name('schools.timetable-entries.deactivate');

            // Phase 9.8 (idempotency corrected): Payroll HTTP transport --
            // thin controllers over the already-authorized 9.7
            // Administration/Read boundary (never PayrollRunService/
            // PayrollPostingService/etc., the trusted cores, directly),
            // mirroring JournalEntryController's exact split. `capability:`
            // middleware here double-checks the identical capability each
            // Administration/Read service already enforces internally,
            // matching every other module's established convention.
            //
            // The structural backstops (a row lock closing the
            // concurrent-posting race window, a conditional UPDATE for
            // approve(), partial unique indexes for one-original-posting/
            // one-reversal-per-posting, `payroll_runs_one_regular_per_period`,
            // proven by Checkpoint 9.4/9.5's real two-process concurrency
            // tests) guarantee AT MOST ONE genuine business effect ever
            // happens -- but they do NOT provide the HTTP retry/replay
            // CONTRACT: without `Idempotency-Key`, a client retrying a
            // request whose successful response was lost (network drop,
            // timeout) receives an invalid-transition/already-posted/
            // already-reversed error instead of the original success. See
            // the per-route comment just below the run/posting block for
            // the exact five consequential commands that carry `idempotent`
            // and why the rest deliberately do not.
            Route::get('/salary-components', [SalaryComponentController::class, 'index'])
                ->middleware('capability:payroll.structures.view')
                ->name('schools.salary-components.index');
            Route::post('/salary-components', [SalaryComponentController::class, 'store'])
                ->middleware(['capability:payroll.structures.manage', 'throttle:school-api-mutations'])
                ->name('schools.salary-components.store');
            Route::post('/salary-components/{salaryComponent}/deactivate', [SalaryComponentController::class, 'deactivate'])
                ->middleware(['capability:payroll.structures.manage', 'throttle:school-api-mutations'])
                ->name('schools.salary-components.deactivate');

            Route::get('/salary-structures', [SalaryStructureController::class, 'index'])
                ->middleware('capability:payroll.structures.view')
                ->name('schools.salary-structures.index');
            Route::post('/salary-structures', [SalaryStructureController::class, 'store'])
                ->middleware(['capability:payroll.structures.manage', 'throttle:school-api-mutations'])
                ->name('schools.salary-structures.store');
            Route::get('/salary-structures/{salaryStructure}', [SalaryStructureController::class, 'show'])
                ->middleware('capability:payroll.structures.view')
                ->name('schools.salary-structures.show');
            Route::post('/salary-structures/{salaryStructure}/components', [SalaryStructureController::class, 'addComponent'])
                ->middleware(['capability:payroll.structures.manage', 'throttle:school-api-mutations'])
                ->name('schools.salary-structures.components.store');
            Route::post('/salary-structures/{salaryStructure}/activate', [SalaryStructureController::class, 'activate'])
                ->middleware(['capability:payroll.structures.manage', 'throttle:school-api-mutations'])
                ->name('schools.salary-structures.activate');

            Route::get('/employment-records/{employmentRecord}/compensation-assignments', [CompensationAssignmentController::class, 'index'])
                ->middleware('capability:payroll.compensation.view')
                ->name('schools.employment-records.compensation-assignments.index');
            Route::post('/employment-records/{employmentRecord}/compensation-assignments', [CompensationAssignmentController::class, 'store'])
                ->middleware(['capability:payroll.compensation.sensitive.manage', 'throttle:school-api-mutations'])
                ->name('schools.employment-records.compensation-assignments.store');
            Route::get('/compensation-assignments/{compensationAssignment}/values', [CompensationAssignmentController::class, 'values'])
                ->middleware('capability:payroll.compensation.sensitive.view')
                ->name('schools.compensation-assignments.values');

            Route::post('/payroll-periods', [PayrollPeriodController::class, 'store'])
                ->middleware(['capability:payroll.periods.manage', 'throttle:school-api-mutations'])
                ->name('schools.payroll-periods.store');
            Route::post('/payroll-periods/{payrollPeriod}/open', [PayrollPeriodController::class, 'open'])
                ->middleware(['capability:payroll.periods.manage', 'throttle:school-api-mutations'])
                ->name('schools.payroll-periods.open');
            Route::post('/payroll-periods/{payrollPeriod}/close', [PayrollPeriodController::class, 'close'])
                ->middleware(['capability:payroll.periods.manage', 'throttle:school-api-mutations'])
                ->name('schools.payroll-periods.close');

            // Idempotency (Phase 9.8 correction, docs/architecture/RELIABILITY.md
            // "API idempotency"): `idempotent` is applied ONLY to the five
            // consequential commands whose retry-after-success would
            // otherwise surface a confusing error instead of replaying the
            // original success -- create run, create correction run,
            // approve, post, reverse. It is placed AFTER `capability:...`
            // in every array below (never before) so a revoked actor is
            // rejected by EnsureCapability before EnsureIdempotent is ever
            // consulted -- a cached successful response can never be
            // replayed to an actor who has since lost the capability
            // (docs/security/AUTHORIZATION.md "Idempotent replay is not an
            // authorization bypass"). `calculate` deliberately does NOT
            // carry it -- recalculation is a whole-result-set replacement,
            // safely re-runnable with an identical deterministic outcome,
            // so a network retry poses no risk `idempotent` would guard
            // against. `manual-overrides`/`correction-deltas` deliberately
            // do NOT carry it either -- both write to the append-only
            // `payroll_adjustments` table, whose OWN read-side resolution
            // (`DISTINCT ON (salary_component_id) ... ORDER BY created_at
            // DESC`, Checkpoint 9.3/9.5) already takes only the latest row
            // per component, so a byte-identical retry never compounds a
            // financial effect even though it does insert an extra
            // (harmless, superseded) audit row. Ordinary draft CRUD
            // (salary-components/-structures, payroll-periods themselves,
            // compensation-assignment creation, accounting configuration)
            // is likewise excluded -- matching this codebase's own
            // precedent (e.g. `hostels.store` carries no `idempotent`
            // either) and, for accounting configuration specifically, its
            // own `updateOrCreate` upsert is already naturally safe to
            // retry.
            Route::get('/payroll-periods/{payrollPeriod}/payroll-runs', [PayrollRunController::class, 'index'])
                ->middleware('capability:payroll.runs.view')
                ->name('schools.payroll-periods.payroll-runs.index');
            Route::post('/payroll-periods/{payrollPeriod}/payroll-runs', [PayrollRunController::class, 'store'])
                ->middleware(['capability:payroll.runs.prepare', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.payroll-periods.payroll-runs.store');
            Route::get('/payroll-runs/{payrollRun}', [PayrollRunController::class, 'show'])
                ->middleware('capability:payroll.runs.view')
                ->name('schools.payroll-runs.show');
            Route::post('/payroll-runs/{payrollRun}/correction', [PayrollRunController::class, 'correction'])
                ->middleware(['capability:payroll.runs.prepare', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.payroll-runs.correction');
            Route::post('/payroll-runs/{payrollRun}/manual-overrides', [PayrollRunController::class, 'manualOverride'])
                ->middleware(['capability:payroll.runs.prepare', 'throttle:school-api-mutations'])
                ->name('schools.payroll-runs.manual-overrides');
            Route::post('/payroll-runs/{payrollRun}/correction-deltas', [PayrollRunController::class, 'correctionDelta'])
                ->middleware(['capability:payroll.runs.prepare', 'throttle:school-api-mutations'])
                ->name('schools.payroll-runs.correction-deltas');
            Route::post('/payroll-runs/{payrollRun}/calculate', [PayrollRunController::class, 'calculate'])
                ->middleware(['capability:payroll.runs.prepare', 'throttle:school-api-mutations'])
                ->name('schools.payroll-runs.calculate');
            Route::post('/payroll-runs/{payrollRun}/approve', [PayrollRunController::class, 'approve'])
                ->middleware(['capability:payroll.runs.approve', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.payroll-runs.approve');

            Route::get('/payroll-runs/{payrollRun}/results', [PayrollRunPostingController::class, 'results'])
                ->middleware('capability:payroll.compensation.sensitive.view')
                ->name('schools.payroll-runs.results');

            // Phase 9.10: on-demand payslip render, never persisted.
            // Same capability as /results (`payroll.compensation.sensitive.view`
            // alone -- `payroll.runs.view` never suffices, see
            // PayslipReadService's own docblock) -- eligibility
            // (approved/posted only) is enforced inside the service.
            Route::get('/payroll-runs/{payrollRun}/payslips/{employmentRecord}', [PayslipController::class, 'show'])
                ->middleware('capability:payroll.compensation.sensitive.view')
                ->name('schools.payroll-runs.payslips.show');

            Route::post('/payroll-runs/{payrollRun}/post', [PayrollRunPostingController::class, 'post'])
                ->middleware(['capability:payroll.runs.post', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.payroll-runs.post');
            Route::post('/payroll-runs/{payrollRun}/reverse', [PayrollRunPostingController::class, 'reverse'])
                ->middleware(['capability:payroll.runs.reverse', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.payroll-runs.reverse');

            Route::post('/payroll-accounting-configuration', [PayrollAccountingConfigurationController::class, 'store'])
                ->middleware(['capability:payroll.accounting.manage', 'throttle:school-api-mutations'])
                ->name('schools.payroll-accounting-configuration.store');

            // Checkpoint 9.6I (Section 2) -- Statutory Payroll
            // administration. `capability:` middleware here
            // double-checks what each Admin service already enforces
            // internally, matching every other Payroll route above.
            // `payroll.statutory.identifiers.view`/`.manage` are
            // separate, narrower capabilities from `.view`/`.manage`
            // (Highly Sensitive government identifiers) -- see
            // StatutoryIdentifierAdminService's own docblock.
            Route::get('/payroll-statutory/rule-status', [StatutoryRuleStatusController::class, 'show'])
                ->middleware('capability:payroll.statutory.view')
                ->name('schools.payroll-statutory.rule-status.show');

            Route::get('/payroll-statutory/pf-status/{employmentRecord}', [StatutoryPfStatusController::class, 'show'])
                ->middleware('capability:payroll.statutory.view')
                ->name('schools.payroll-statutory.pf-status.show');
            Route::post('/payroll-statutory/pf-status/{employmentRecord}', [StatutoryPfStatusController::class, 'store'])
                ->middleware(['capability:payroll.statutory.manage', 'throttle:school-api-mutations'])
                ->name('schools.payroll-statutory.pf-status.store');

            Route::get('/payroll-statutory/esi-coverage/{employmentRecord}', [StatutoryEsiCoverageController::class, 'index'])
                ->middleware('capability:payroll.statutory.view')
                ->name('schools.payroll-statutory.esi-coverage.index');
            Route::post('/payroll-statutory/esi-coverage/{employmentRecord}', [StatutoryEsiCoverageController::class, 'store'])
                ->middleware(['capability:payroll.statutory.manage', 'throttle:school-api-mutations'])
                ->name('schools.payroll-statutory.esi-coverage.store');

            Route::get('/payroll-statutory/tax-profile/{employmentRecord}', [StatutoryTaxProfileController::class, 'index'])
                ->middleware('capability:payroll.statutory.view')
                ->name('schools.payroll-statutory.tax-profile.index');
            Route::post('/payroll-statutory/tax-profile/{employmentRecord}', [StatutoryTaxProfileController::class, 'store'])
                ->middleware(['capability:payroll.statutory.manage', 'throttle:school-api-mutations'])
                ->name('schools.payroll-statutory.tax-profile.store');

            Route::get('/payroll-statutory/identifiers/{employmentRecord}', [StatutoryIdentifierController::class, 'index'])
                ->middleware('capability:payroll.statutory.view')
                ->name('schools.payroll-statutory.identifiers.index');
            Route::get('/payroll-statutory/identifiers/{employmentRecord}/{identifierType}/reveal', [StatutoryIdentifierController::class, 'reveal'])
                ->middleware('capability:payroll.statutory.identifiers.view')
                ->name('schools.payroll-statutory.identifiers.reveal');
            Route::post('/payroll-statutory/identifiers/{employmentRecord}', [StatutoryIdentifierController::class, 'store'])
                ->middleware(['capability:payroll.statutory.identifiers.manage', 'throttle:school-api-mutations'])
                ->name('schools.payroll-statutory.identifiers.store');

            Route::get('/payroll-statutory/accounting-configuration', [StatutoryAccountingConfigurationController::class, 'show'])
                ->middleware('capability:payroll.statutory.view')
                ->name('schools.payroll-statutory.accounting-configuration.show');
            Route::post('/payroll-statutory/accounting-configuration', [StatutoryAccountingConfigurationController::class, 'store'])
                ->middleware(['capability:payroll.statutory.manage', 'throttle:school-api-mutations'])
                ->name('schools.payroll-statutory.accounting-configuration.store');

            Route::get('/payroll-runs/{payrollRun}/statutory-exports/ecr', [StatutoryExportController::class, 'ecr'])
                ->middleware('capability:payroll.statutory.exports.generate')
                ->name('schools.payroll-runs.statutory-exports.ecr');
            Route::get('/payroll-runs/{payrollRun}/statutory-exports/esi-worksheet', [StatutoryExportController::class, 'esiWorksheet'])
                ->middleware('capability:payroll.statutory.exports.generate')
                ->name('schools.payroll-runs.statutory-exports.esi-worksheet');
            Route::get('/payroll-runs/{payrollRun}/statutory-exports/tds-draft-statement', [StatutoryExportController::class, 'tdsDraftStatement'])
                ->middleware('capability:payroll.statutory.exports.generate')
                ->name('schools.payroll-runs.statutory-exports.tds-draft-statement');

            // Phase 0H.2 (Student Attendance foundation). A deliberately
            // narrow COMMAND surface, never generic CRUD: a register is
            // submitted once, complete, and an already-submitted record
            // is only ever changed through the explicit
            // expected-status correction command. There is no Session
            // update/replace/delete route and no generic
            // AttendanceRecord update route, by design.
            //
            // `store` carries `idempotent`: submitting a register is a
            // consequential mutation a browser or flaky connection can
            // plausibly retry, and a duplicate submission would be both
            // costly (a second authoritative register) and confusing.
            // The capability middleware is declared BEFORE `idempotent`
            // so authorization is re-evaluated ahead of any replay --
            // an actor whose capability was revoked can never replay a
            // stored success (CLAUDE.md rule 32, the same ordering
            // webhook-endpoint mutations already use).
            //
            // `correct` deliberately does NOT carry `idempotent`: it is
            // already safe to retry by construction, because
            // expected-status compare-and-swap makes a duplicate
            // delivery of the same correction fail closed with a
            // typed 409 rather than apply twice.
            //
            // The two helper endpoints are gated by Attendance's OWN
            // capability before any query executes -- never Timetable's
            // `timetable.*` or Students' capabilities, carrying forward
            // the Canteen capability-boundary lesson explicitly. They
            // are registered BEFORE `{attendanceSession}` so their
            // literal path segments are not swallowed by the parameter
            // route.
            Route::get('/attendance-sessions', [AttendanceSessionController::class, 'index'])
                ->middleware('capability:attendance.view')
                ->name('schools.attendance-sessions.index');
            Route::post('/attendance-sessions', [AttendanceSessionController::class, 'store'])
                ->middleware(['capability:attendance.manage', 'throttle:school-api-mutations', 'idempotent'])
                ->name('schools.attendance-sessions.store');
            Route::get('/attendance-sessions/scheduled-classes', [AttendanceSessionController::class, 'scheduledClasses'])
                ->middleware('capability:attendance.manage')
                ->name('schools.attendance-sessions.scheduled-classes');
            Route::get('/attendance-sessions/roster-preview', [AttendanceSessionController::class, 'rosterPreview'])
                ->middleware('capability:attendance.manage')
                ->name('schools.attendance-sessions.roster-preview');
            Route::get('/attendance-sessions/{attendanceSession}', [AttendanceSessionController::class, 'show'])
                ->middleware('capability:attendance.view')
                ->name('schools.attendance-sessions.show');
            Route::post('/attendance-records/{attendanceRecord}/correct', [AttendanceSessionController::class, 'correct'])
                ->middleware(['capability:attendance.manage', 'throttle:school-api-mutations'])
                ->name('schools.attendance-records.correct');

            // Phase 0H.3A (Syllabus Foundation -- the first concrete
            // Academics fact). Exactly FOUR operations: list/create
            // nested under the owning SubjectOffering, show/update
            // flat, matching Academic Structure's established nesting
            // convention.
            //
            // Deliberately NO delete, NO activate and NO deactivate
            // route. `status` moves through the ordinary PATCH exactly
            // like every other Academic Structure reference entity
            // (Section, SubjectOffering, Room, Subject, GradeLevel) --
            // none of which has a lifecycle route. The entities that DO
            // have activate/deactivate here (AcademicYear,
            // TimetablePeriod, TimetableEntry) each re-validate a real
            // invariant on activation; a SyllabusUnit cannot conflict
            // with anything on reactivation because its unique code
            // index is unconditional.
            //
            // Gated by Syllabus's OWN capability family -- never
            // Academic Structure's `academics.subjects.*`, even though
            // the parent Offering belongs to that module (the Canteen
            // capability-boundary lesson, carried forward). No
            // Idempotency-Key: these are small reference-catalogue
            // mutations whose duplicate semantic creation is already
            // prevented by `syllabus_units_offering_code_ci_unique`.
            Route::get('/subject-offerings/{subjectOffering}/syllabus-units', [SyllabusUnitController::class, 'index'])
                ->middleware('capability:syllabus.view')
                ->name('schools.subject-offerings.syllabus-units.index');
            Route::post('/subject-offerings/{subjectOffering}/syllabus-units', [SyllabusUnitController::class, 'store'])
                ->middleware(['capability:syllabus.manage', 'throttle:school-api-mutations'])
                ->name('schools.subject-offerings.syllabus-units.store');
            Route::get('/syllabus-units/{syllabusUnit}', [SyllabusUnitController::class, 'show'])
                ->middleware('capability:syllabus.view')
                ->name('schools.syllabus-units.show');
            Route::patch('/syllabus-units/{syllabusUnit}', [SyllabusUnitController::class, 'update'])
                ->middleware(['capability:syllabus.manage', 'throttle:school-api-mutations'])
                ->name('schools.syllabus-units.update');

            // Phase 0I.2 (Learning Content Foundation -- the first
            // concrete LMS fact, ADR 0039). Exactly SIX operations:
            // list/create nested under the owning SubjectOffering,
            // show/update flat, publish/archive as dedicated action
            // routes -- the CurriculumDelivery-style split (PATCH never
            // accepts `status`; a lifecycle change always goes through
            // its own action).
            //
            // Deliberately NO delete route (status-based retirement
            // only, rule 73) and NO Assignment/Submission route of any
            // kind -- those remain future, separately-gated checkpoints.
            //
            // Gated by LMS's OWN capability family (`lms.content.*`) --
            // never Academic Structure's `academics.subjects.*`, even
            // though the parent Offering belongs to that module (the
            // Canteen capability-boundary lesson, carried forward). No
            // Idempotency-Key: create/update/publish/archive are all
            // either naturally idempotent (PATCH) or already guarded by
            // the service's own row lock + closed transition map
            // (duplicate publish/archive fails closed with a 422, never
            // applies twice).
            Route::get('/subject-offerings/{subjectOffering}/learning-content', [LearningContentController::class, 'index'])
                ->middleware('capability:lms.content.view')
                ->name('schools.subject-offerings.learning-content.index');
            Route::post('/subject-offerings/{subjectOffering}/learning-content', [LearningContentController::class, 'store'])
                ->middleware(['capability:lms.content.manage', 'throttle:school-api-mutations'])
                ->name('schools.subject-offerings.learning-content.store');
            Route::get('/learning-content/{learningContent}', [LearningContentController::class, 'show'])
                ->middleware('capability:lms.content.view')
                ->name('schools.learning-content.show');
            Route::patch('/learning-content/{learningContent}', [LearningContentController::class, 'update'])
                ->middleware(['capability:lms.content.manage', 'throttle:school-api-mutations'])
                ->name('schools.learning-content.update');
            Route::post('/learning-content/{learningContent}/publish', [LearningContentController::class, 'publish'])
                ->middleware(['capability:lms.content.manage', 'throttle:school-api-mutations'])
                ->name('schools.learning-content.publish');
            Route::post('/learning-content/{learningContent}/archive', [LearningContentController::class, 'archive'])
                ->middleware(['capability:lms.content.manage', 'throttle:school-api-mutations'])
                ->name('schools.learning-content.archive');

            // Phase 0I.2 -- the LearningContent owner-type extension of
            // the shared Documents module (ADR 0039 decision 8). Reuses
            // the EXACT same `DocumentController`/throttle/
            // `private-no-store` shape `employees/{employee}/documents`
            // above already established -- store/index are owner-type-
            // specific (need the owner id in the URL); the existing
            // generic `/documents/{document}` show/content/archive
            // routes below already work for this owner type unchanged,
            // since DocumentReadService/DocumentService resolve owner
            // type from the ALREADY-PERSISTED row, never from the URL.
            Route::post('/learning-content/{learningContent}/documents', [DocumentController::class, 'storeForLearningContent'])
                ->middleware(['throttle:documents-writes', 'private-no-store'])
                ->name('schools.learning-content.documents.store');
            Route::get('/learning-content/{learningContent}/documents', [DocumentController::class, 'indexForLearningContent'])
                ->middleware(['throttle:documents-reads', 'private-no-store'])
                ->name('schools.learning-content.documents.index');

            // Phase 0I.3 (Assignments -- the second concrete LMS fact,
            // ADR 0039). Exactly SIX operations, structurally identical
            // to Learning Content's own shape: list/create nested under
            // the owning SubjectOffering, show/update flat, publish/
            // close as dedicated action routes -- `status` moves ONLY
            // through those two, never through the ordinary PATCH.
            //
            // Deliberately NO delete route and NO Submission operation
            // of any kind -- Submission remains a future, separately
            // legal-review-gated checkpoint (ADR 0039 §4).
            //
            // Gated by LMS's OWN capability family (`lms.assignments.*`)
            // -- never Academic Structure's `academics.subjects.*`, the
            // Canteen capability-boundary lesson carried forward.
            Route::get('/subject-offerings/{subjectOffering}/assignments', [AssignmentController::class, 'index'])
                ->middleware('capability:lms.assignments.view')
                ->name('schools.subject-offerings.assignments.index');
            Route::post('/subject-offerings/{subjectOffering}/assignments', [AssignmentController::class, 'store'])
                ->middleware(['capability:lms.assignments.manage', 'throttle:school-api-mutations'])
                ->name('schools.subject-offerings.assignments.store');
            Route::get('/assignments/{assignment}', [AssignmentController::class, 'show'])
                ->middleware('capability:lms.assignments.view')
                ->name('schools.assignments.show');
            Route::patch('/assignments/{assignment}', [AssignmentController::class, 'update'])
                ->middleware(['capability:lms.assignments.manage', 'throttle:school-api-mutations'])
                ->name('schools.assignments.update');
            Route::post('/assignments/{assignment}/publish', [AssignmentController::class, 'publish'])
                ->middleware(['capability:lms.assignments.manage', 'throttle:school-api-mutations'])
                ->name('schools.assignments.publish');
            Route::post('/assignments/{assignment}/close', [AssignmentController::class, 'close'])
                ->middleware(['capability:lms.assignments.manage', 'throttle:school-api-mutations'])
                ->name('schools.assignments.close');

            // Phase 0I.3 -- the Assignment owner-type extension of the
            // shared Documents module (ADR 0039 decision 8), reusing the
            // EXACT same shape the Learning Content owner arm above
            // already established. The existing generic
            // `/documents/{document}` show/content/archive routes need
            // no change for this owner type either.
            Route::post('/assignments/{assignment}/documents', [DocumentController::class, 'storeForAssignment'])
                ->middleware(['throttle:documents-writes', 'private-no-store'])
                ->name('schools.assignments.documents.store');
            Route::get('/assignments/{assignment}/documents', [DocumentController::class, 'indexForAssignment'])
                ->middleware(['throttle:documents-reads', 'private-no-store'])
                ->name('schools.assignments.documents.index');

            // Phase 0H.3B (Curriculum Delivery -- the second concrete
            // Academics fact). Exactly FIVE operations: list/start
            // nested under the owning SubjectOffering, show/correct/
            // transition flat.
            //
            // Deliberately NO delete, NO archive, NO activate/
            // deactivate, NO bulk, NO reorder, NO search or discovery
            // helper, NO reporting/aggregate endpoint, NO teacher route
            // and NO Student route. These rows are historical
            // instructional activity, so there is no hard-delete API
            // (CLAUDE.md rule 73).
            //
            // `status` moves ONLY through the dedicated transition
            // operation, never through PATCH. Unlike SyllabusUnit --
            // which correctly has no lifecycle route because it can
            // conflict with nothing -- completing or reopening a
            // delivery is guarded by an expected-status
            // compare-and-swap that an ordinary PATCH cannot express,
            // the same criterion that gives AcademicYear/
            // TimetablePeriod/TimetableEntry their own commands.
            //
            // Gated by Curriculum Delivery's OWN capability family --
            // never Syllabus's `syllabus.*` and never Academic
            // Structure's `academics.subjects.*`, even though both
            // parents belong to those modules (the Canteen
            // capability-boundary lesson, carried forward). Keeping the
            // catalogue and its delivery independently grantable is
            // also what leaves room for a future teacher role to hold
            // delivery rights without the right to rewrite the syllabus.
            //
            // No Idempotency-Key (rule 29, evaluated per endpoint):
            // duplicate creation is already prevented by
            // `curriculum_deliveries_section_unit_unique`, a duplicate
            // transition fails closed on the compare-and-swap, and
            // PATCH is naturally idempotent.
            Route::get('/subject-offerings/{subjectOffering}/curriculum-deliveries', [CurriculumDeliveryController::class, 'index'])
                ->middleware('capability:curriculum.delivery.view')
                ->name('schools.subject-offerings.curriculum-deliveries.index');
            Route::post('/subject-offerings/{subjectOffering}/curriculum-deliveries', [CurriculumDeliveryController::class, 'store'])
                ->middleware(['capability:curriculum.delivery.manage', 'throttle:school-api-mutations'])
                ->name('schools.subject-offerings.curriculum-deliveries.store');
            Route::get('/curriculum-deliveries/{curriculumDelivery}', [CurriculumDeliveryController::class, 'show'])
                ->middleware('capability:curriculum.delivery.view')
                ->name('schools.curriculum-deliveries.show');
            Route::patch('/curriculum-deliveries/{curriculumDelivery}', [CurriculumDeliveryController::class, 'update'])
                ->middleware(['capability:curriculum.delivery.manage', 'throttle:school-api-mutations'])
                ->name('schools.curriculum-deliveries.update');
            Route::post('/curriculum-deliveries/{curriculumDelivery}/transition', [CurriculumDeliveryController::class, 'transition'])
                ->middleware(['capability:curriculum.delivery.manage', 'throttle:school-api-mutations'])
                ->name('schools.curriculum-deliveries.transition');

            // Phase 0H.4A (Examination Foundation -- the first
            // Examinations fact). Exactly FOUR operations: list/create
            // nested under the owning AcademicYear, show/update flat,
            // matching AcademicTermController's established nesting
            // convention.
            //
            // An Examination is a WINDOW, not a paper. There is
            // deliberately NO paper, scheduling, marks, grade-scale,
            // result, report-card, transcript, search, bulk or
            // reporting endpoint here -- the per-Subject entity is a
            // future ExaminationPaper (Phase 0H.4B, its own gate).
            //
            // Deliberately NO delete, NO activate and NO deactivate
            // route. `status` moves through the ordinary PATCH exactly
            // like Section/SubjectOffering/Room/Subject/GradeLevel/
            // SyllabusUnit -- none of which has a lifecycle route. An
            // Examination cannot conflict with anything on
            // reactivation because `examinations_year_code_ci_unique`
            // is unconditional, so an inactive one already reserves its
            // code.
            //
            // Gated by Examinations' OWN capability family -- never
            // Academic Structure's `academics.years.*`, even though the
            // parent AcademicYear belongs to that module (the Canteen
            // capability-boundary lesson, carried forward). Depth-2
            // (`examinations.definitions.*`) so a later marks or
            // result-publication family can never be granted by the
            // same key.
            //
            // No Idempotency-Key (rule 29, evaluated per endpoint):
            // duplicate creation is already prevented by the unique
            // code index, and PATCH is naturally idempotent.
            Route::get('/academic-years/{academicYear}/examinations', [ExaminationController::class, 'index'])
                ->middleware('capability:examinations.definitions.view')
                ->name('schools.academic-years.examinations.index');
            Route::post('/academic-years/{academicYear}/examinations', [ExaminationController::class, 'store'])
                ->middleware(['capability:examinations.definitions.manage', 'throttle:school-api-mutations'])
                ->name('schools.academic-years.examinations.store');
            Route::get('/examinations/{examination}', [ExaminationController::class, 'show'])
                ->middleware('capability:examinations.definitions.view')
                ->name('schools.examinations.show');
            Route::patch('/examinations/{examination}', [ExaminationController::class, 'update'])
                ->middleware(['capability:examinations.definitions.manage', 'throttle:school-api-mutations'])
                ->name('schools.examinations.update');

            // Phase 0H.4B (ExaminationPaper / Scheduling). Exactly FOUR
            // operations: list/create nested under the owning
            // Examination, show/update flat -- the identical nesting
            // convention ExaminationController itself established.
            //
            // An ExaminationPaper is one SubjectOffering assessed within
            // one Examination -- Offering-wide, never Section-specific.
            // Deliberately NO delete, NO activate and NO deactivate
            // route; `status` moves through the ordinary PATCH exactly
            // like Examination itself, since the aggregate unique
            // constraint is unconditional and an inactive Paper already
            // reserves its Examination x SubjectOffering pair.
            //
            // Gated by Examinations' own capability family, one level
            // deeper than `examinations.definitions.*`
            // (`examinations.papers.*`) -- exactly the depth-2 room the
            // 0H.4A capability catalog left for this checkpoint.
            //
            // No Idempotency-Key (rule 29, evaluated per endpoint):
            // duplicate creation is already prevented by the aggregate
            // unique constraint, and PATCH is naturally idempotent.
            Route::get('/examinations/{examination}/examination-papers', [ExaminationPaperController::class, 'index'])
                ->middleware('capability:examinations.papers.view')
                ->name('schools.examinations.examination-papers.index');
            Route::post('/examinations/{examination}/examination-papers', [ExaminationPaperController::class, 'store'])
                ->middleware(['capability:examinations.papers.manage', 'throttle:school-api-mutations'])
                ->name('schools.examinations.examination-papers.store');
            Route::get('/examination-papers/{examinationPaper}', [ExaminationPaperController::class, 'show'])
                ->middleware('capability:examinations.papers.view')
                ->name('schools.examination-papers.show');
            Route::patch('/examination-papers/{examinationPaper}', [ExaminationPaperController::class, 'update'])
                ->middleware(['capability:examinations.papers.manage', 'throttle:school-api-mutations'])
                ->name('schools.examination-papers.update');

            // Phase 0H.4C (GradeScale). School-only -- no Examination
            // nesting of any kind, deliberately: GradeScale is
            // independent of the Examination chain (ADR 0032/0035).
            // Exactly SEVEN operations: list/create/show/update the
            // scale, plus create/update/delete a band.
            //
            // `status` is never a raw mass-assignable field on the
            // update route -- GradeScaleService interprets it as a
            // guarded lifecycle transition (exactly three legal
            // transitions; every no-op and every illegal cross-state
            // request is rejected). GradeBands may be created, edited,
            // or deleted ONLY while the parent scale is `draft`; once
            // ever `active`, bands are frozen forever.
            //
            // Gated by its OWN capability family
            // (`examinations.grade_scales.*`), the depth-2 leaf 0H.4A's
            // capability catalog already reserved -- never implied by
            // `examinations.definitions.*`/`examinations.papers.*`, and
            // never implying `examinations.marks.*`/`.results.*`.
            //
            // No Idempotency-Key (rule 29, evaluated per endpoint):
            // duplicate creation is already prevented by the unique
            // code/threshold indexes, and PATCH is naturally
            // idempotent.
            Route::get('/grade-scales', [GradeScaleController::class, 'index'])
                ->middleware('capability:examinations.grade_scales.view')
                ->name('schools.grade-scales.index');
            Route::post('/grade-scales', [GradeScaleController::class, 'store'])
                ->middleware(['capability:examinations.grade_scales.manage', 'throttle:school-api-mutations'])
                ->name('schools.grade-scales.store');
            Route::get('/grade-scales/{gradeScale}', [GradeScaleController::class, 'show'])
                ->middleware('capability:examinations.grade_scales.view')
                ->name('schools.grade-scales.show');
            Route::patch('/grade-scales/{gradeScale}', [GradeScaleController::class, 'update'])
                ->middleware(['capability:examinations.grade_scales.manage', 'throttle:school-api-mutations'])
                ->name('schools.grade-scales.update');
            Route::post('/grade-scales/{gradeScale}/bands', [GradeScaleController::class, 'storeBand'])
                ->middleware(['capability:examinations.grade_scales.manage', 'throttle:school-api-mutations'])
                ->name('schools.grade-scales.bands.store');
            Route::patch('/grade-scales/{gradeScale}/bands/{gradeBand}', [GradeScaleController::class, 'updateBand'])
                ->middleware(['capability:examinations.grade_scales.manage', 'throttle:school-api-mutations'])
                ->name('schools.grade-scales.bands.update');
            Route::delete('/grade-scales/{gradeScale}/bands/{gradeBand}', [GradeScaleController::class, 'destroyBand'])
                ->middleware(['capability:examinations.grade_scales.manage', 'throttle:school-api-mutations'])
                ->name('schools.grade-scales.bands.destroy');
        });

    // Phase 0O.3 (ADR 0049 sections 3 and 6.4): the PARTNER surface. Deny by
    // default: a route exists here only when registered with a scope from
    // App\Support\Api\PartnerScopeRegistry, and no production partner
    // scope is approved (owner decision, O15 open) -- so production has NO
    // partner route. Partner routes take no `{school}` parameter: the
    // School is the credential's own immutable binding (`partner-context`).
    // `auth:partner` is framework-prioritized ahead of ThrottleRequests, so
    // `api-read` keys by (School, client).
    Route::prefix('partner')->name('partner.')->middleware(['auth:partner', 'partner-context'])->group(function (): void {
        // The substrate's proof, `local`/`testing` only -- never registered
        // (and so never route-cached) in production.
        if (PartnerScopeRegistry::probeEnabled()) {
            Route::get('/probe', [PartnerProbeController::class, 'show'])
                ->middleware(['partner-scope:'.PartnerScopeRegistry::PROBE, 'throttle:api-read'])
                ->name('probe');
        }
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

    // Gap G1 (AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md): the gateway must have
    // Laravel verify a signed context token before running ANY model
    // completion. Same service capability as tool invocation -- both are
    // the gateway acting for a verified human in one School.
    Route::post('/completions/authorize', [AiCompletionAuthorizationController::class, 'authorizeCompletion'])
        ->middleware(['ai-service:ai.tools.invoke', 'throttle:internal-service'])
        ->name('api.internal.ai.completions.authorize');
});

// Phase 0O.3 (ADR 0049 section 7): no unthrottled `/api/v1` route. A route
// without its own named limiter gets the default class for its method --
// `api-read` for safe methods, `api-mutation` otherwise -- keyed by the
// principal. Routes that already declare a limiter keep it (the existing
// stricter ones stay). Registered here, at route-definition time, so it is
// part of the route cache; guarded by
// Tests\Feature\Api\ApiRouteThrottleCoverageTest.
foreach (Route::getRoutes()->getRoutes() as $route) {
    if (! str_starts_with($route->uri(), 'api/v1/')) {
        continue;
    }

    $throttled = collect($route->middleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));

    if (! $throttled) {
        $safe = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) === [];
        $route->middleware($safe ? 'throttle:api-read' : 'throttle:api-mutation');
    }
}
