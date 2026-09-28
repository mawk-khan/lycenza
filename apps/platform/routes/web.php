<?php

use App\Domain\Communications\Http\Controllers\AnnouncementController;
use App\Domain\Communications\Http\Controllers\CommunicationAnalyticsController;
use App\Domain\Communications\Http\Controllers\CommunicationApprovalController;
use App\Domain\Communications\Http\Controllers\CommunicationApprovalPolicyController;
use App\Domain\Communications\Http\Controllers\CommunicationAttachmentController;
use App\Domain\Communications\Http\Controllers\CommunicationAudienceSearchController;
use App\Domain\Communications\Http\Controllers\CommunicationAuditController;
use App\Domain\Communications\Http\Controllers\CommunicationChannelPolicyController;
use App\Domain\Communications\Http\Controllers\CommunicationConversationPolicyController;
use App\Domain\Communications\Http\Controllers\CommunicationDeliveryTimingPolicyController;
use App\Domain\Communications\Http\Controllers\CommunicationHubController;
use App\Domain\Communications\Http\Controllers\CommunicationInboxController;
use App\Domain\Communications\Http\Controllers\CommunicationPreferenceController;
use App\Domain\Communications\Http\Controllers\CommunicationTemplateController;
use App\Http\Controllers\App\Account\AccountSecurityController;
use App\Http\Controllers\App\Account\ApiTokenController;
use App\Http\Controllers\App\Account\MfaAdminController;
use App\Http\Controllers\App\AdmissionApplicationController;
use App\Http\Controllers\App\Analytics\CurriculumCoverageController;
use App\Http\Controllers\App\ApplicantController;
use App\Http\Controllers\App\Attendance\AttendanceController;
use App\Http\Controllers\App\Automation\AutomationController;
use App\Http\Controllers\App\Canteen\CanteenBillingConfigurationController;
use App\Http\Controllers\App\Canteen\CanteenItemController;
use App\Http\Controllers\App\Canteen\CanteenOrderController;
use App\Http\Controllers\App\Canteen\CanteenOutletController;
use App\Http\Controllers\App\Compliance\AuditLogController;
use App\Http\Controllers\App\CurriculumDelivery\CurriculumDeliveryController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\Domains\SchoolDomainController;
use App\Http\Controllers\App\EnrollmentRolloverController;
use App\Http\Controllers\App\EnrollmentRolloverItemController;
use App\Http\Controllers\App\EnrollmentRolloverMappingController;
use App\Http\Controllers\App\EnrollmentRolloverSubjectMappingController;
use App\Http\Controllers\App\Examinations\ExaminationController;
use App\Http\Controllers\App\Examinations\ExaminationPaperController;
use App\Http\Controllers\App\Examinations\GradeScaleController;
use App\Http\Controllers\App\Finance\ChargeController as FinanceChargeController;
use App\Http\Controllers\App\Finance\FinanceController;
use App\Http\Controllers\App\Finance\JournalEntryController as FinanceJournalEntryController;
use App\Http\Controllers\App\Finance\LedgerAccountController as FinanceLedgerAccountController;
use App\Http\Controllers\App\Finance\PaymentController as FinancePaymentController;
use App\Http\Controllers\App\Groups\GroupReportController;
use App\Http\Controllers\App\Groups\SchoolGroupController;
use App\Http\Controllers\App\GuardianAccountInvitationController;
use App\Http\Controllers\App\GuardianAccountLinkController;
use App\Http\Controllers\App\GuardianCommunicationPreferenceController;
use App\Http\Controllers\App\GuardianController;
use App\Http\Controllers\App\HostelController;
use App\Http\Controllers\App\HostelResidencyController;
use App\Http\Controllers\App\HostelRoomController;
use App\Http\Controllers\App\HR\HrController;
use App\Http\Controllers\App\HR\HrDepartmentController;
use App\Http\Controllers\App\HR\HrEmployeeAddressController;
use App\Http\Controllers\App\HR\HrEmployeeAssignmentController;
use App\Http\Controllers\App\HR\HrEmployeeCategoryController;
use App\Http\Controllers\App\HR\HrEmployeeCertificationController;
use App\Http\Controllers\App\HR\HrEmployeeController;
use App\Http\Controllers\App\HR\HrEmployeeDocumentController;
use App\Http\Controllers\App\HR\HrEmployeeEmergencyContactController;
use App\Http\Controllers\App\HR\HrEmployeeExperienceController;
use App\Http\Controllers\App\HR\HrEmployeeImportController;
use App\Http\Controllers\App\HR\HrEmployeeNoteController;
use App\Http\Controllers\App\HR\HrEmployeePersonalDetailController;
use App\Http\Controllers\App\HR\HrEmployeeQualificationController;
use App\Http\Controllers\App\HR\HrEmploymentController;
use App\Http\Controllers\App\HR\HrPositionController;
use App\Http\Controllers\App\Integrations\ApiClientController;
use App\Http\Controllers\App\InventoryItemController;
use App\Http\Controllers\App\InventoryLocationController;
use App\Http\Controllers\App\InventoryStockController;
use App\Http\Controllers\App\LibraryCatalogueController;
use App\Http\Controllers\App\LibraryCirculationController;
use App\Http\Controllers\App\LMS\AssignmentController as LmsAssignmentController;
use App\Http\Controllers\App\LMS\LearningContentController as LmsLearningContentController;
use App\Http\Controllers\App\Payroll\CompensationController as PayrollCompensationController;
use App\Http\Controllers\App\Payroll\PayrollAccountingConfigurationController;
use App\Http\Controllers\App\Payroll\PayrollController;
use App\Http\Controllers\App\Payroll\PayrollPeriodController;
use App\Http\Controllers\App\Payroll\PayrollRunController;
use App\Http\Controllers\App\Payroll\PayrollRunPostingController;
use App\Http\Controllers\App\Payroll\PayslipController as PayrollPayslipController;
use App\Http\Controllers\App\Payroll\SalaryComponentController as PayrollSalaryComponentController;
use App\Http\Controllers\App\Payroll\SalaryStructureController as PayrollSalaryStructureController;
use App\Http\Controllers\App\Payroll\Statutory\StatutoryAccountingConfigurationController;
use App\Http\Controllers\App\Payroll\Statutory\StatutoryController as StatutoryPayrollController;
use App\Http\Controllers\App\Payroll\Statutory\StatutoryEmployeeController;
use App\Http\Controllers\App\Payroll\Statutory\StatutoryExportController as StatutoryPayrollExportController;
use App\Http\Controllers\App\Platform\PlatformAuditLogController;
use App\Http\Controllers\App\Platform\PlatformRoleAdminController;
use App\Http\Controllers\App\Platform\PlatformSchoolAdminController;
use App\Http\Controllers\App\Platform\SchoolElevationController;
use App\Http\Controllers\App\Platform\SchoolGroupAdminController;
use App\Http\Controllers\App\SchoolSettingsController;
use App\Http\Controllers\App\SchoolSetupController;
use App\Http\Controllers\App\SchoolSwitchController;
use App\Http\Controllers\App\StudentAccountLinkController;
use App\Http\Controllers\App\StudentController;
use App\Http\Controllers\App\StudentEnrollmentController;
use App\Http\Controllers\App\StudentGuardianRelationshipController;
use App\Http\Controllers\App\StudentProcessingAuthorizationController;
use App\Http\Controllers\App\StudentSubjectEnrollmentController;
use App\Http\Controllers\App\SubjectOfferingController;
use App\Http\Controllers\App\Syllabus\SyllabusUnitController;
use App\Http\Controllers\App\Timetable\TimetableEntryController;
use App\Http\Controllers\App\Timetable\TimetablePeriodController;
use App\Http\Controllers\App\TransportOperationsController;
use App\Http\Controllers\App\TransportRouteController;
use App\Http\Controllers\App\TransportStudentAssignmentController;
use App\Http\Controllers\App\TransportVehicleController;
use App\Http\Controllers\App\VisitorController;
use App\Http\Controllers\App\VisitorVisitController;
use App\Http\Controllers\Auth\AccountRecoveryController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MfaChallengeController;
use App\Http\Controllers\Auth\SessionHandoffController;
use App\Http\Controllers\Identity\InvitationAcceptanceController;
use App\Http\Controllers\Internal\MfaDemoController;
use App\Http\Controllers\SystemStatusController;
use Illuminate\Support\Facades\Route;

// Unauthenticated Phase 0A primitive -- unchanged.
Route::get('/', [SystemStatusController::class, 'index'])->name('system.status');

// `private-no-store` on the three guest pages that can turn into a
// signed-in page without a full reload (Inertia sign-in on /login and
// /login/mfa, invitation acceptance): the browser must not keep that
// document for back/forward restore once someone has signed in on it
// and then logged out. Signed-in pages get the same header from
// PreventAuthenticatedPageCaching. See docs/security/AUTHORIZATION.md
// ("After logout").
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->middleware('private-no-store')->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');

    // Phase 0H.4D-P1: stage 2 of the two-stage login for an
    // MFA-enrolled User. Deliberately under 'guest' (like /login
    // itself) -- reached only via session('mfa_pending_user_id'), the
    // request is NOT yet authenticated (MfaChallengeController::
    // create() redirects to /login if that key is absent). Its own
    // dedicated throttle (mfa-challenge, user-keyed once the pending
    // User is known) is applied inside the controller, not here,
    // since the limiter key needs the pending user id from the
    // session, unavailable at route-middleware-declaration time.
    Route::get('/login/mfa', [MfaChallengeController::class, 'create'])->middleware('private-no-store')->name('login.mfa');
    Route::post('/login/mfa', [MfaChallengeController::class, 'store'])
        ->middleware('throttle:mfa-challenge')
        ->name('login.mfa.store');
});

// Phase 0O.10A (ADR 0056): self-service password recovery -- platform host
// only (not in SchoolHostSurface: 404 on a School host), no School, no
// TenantContext. no-store + no-referrer on every page; the POSTs are CSRF-
// protected and throttled (per IP + global; per IP + selector). The GET
// reset page is never throttled by selector, so a mail scanner's prefetch
// cannot use up the owner's attempts.
Route::middleware(['private-no-store', 'no-referrer'])->group(function (): void {
    Route::get('/account-recovery', [AccountRecoveryController::class, 'create'])->name('account-recovery.create');
    Route::post('/account-recovery', [AccountRecoveryController::class, 'store'])
        ->middleware('throttle:account-recovery-request')->name('account-recovery.store');
    Route::get('/account-recovery/{selector}', [AccountRecoveryController::class, 'edit'])
        ->where('selector', '[A-Za-z0-9_-]{22}')->name('account-recovery.edit');
    Route::post('/account-recovery/{selector}', [AccountRecoveryController::class, 'update'])
        ->where('selector', '[A-Za-z0-9_-]{22}')
        ->middleware('throttle:account-recovery-reset')->name('account-recovery.update');
});

// Phase 5D.3 -- the Guardian account-invitation acceptance page.
// Deliberately NOT wrapped in 'guest' (an existing User must be able
// to view/confirm this page while already authenticated -- brief
// §11-13's existing-account branch) nor 'auth' (a brand-new person has
// no account yet). See InvitationAcceptanceController's docblock for
// how tenant context is resolved for an RLS-protected lookup without
// either.
Route::middleware('throttle:guardian-invitation-accept')->group(function (): void {
    Route::get('/invitations/{school}/{token}', [InvitationAcceptanceController::class, 'show'])->middleware('private-no-store')->name('invitations.show');
    Route::post('/invitations/{school}/{token}', [InvitationAcceptanceController::class, 'store'])->name('invitations.store');
});

// Phase 0O.8A (ADR 0054 amendment): redeems a one-time cross-host sign-in
// handoff on the TARGET origin of a School switch (see
// SessionHandoffController). Neither `guest` nor `auth`: it establishes the
// login itself after its own checks. `private-no-store`; the controller also
// sends Referrer-Policy: no-referrer and redirects to a clean URL at once.
Route::get('/session/handoff', SessionHandoffController::class)
    ->middleware(['throttle:session-handoff', 'private-no-store'])
    ->name('session.handoff');

// Signed-in routes that need NO School context (Phase 0N.1, D9(a)/D10(a)):
// the /app landing (School selection, or the neutral state for an
// account with no School), School activation itself, the User's own
// account security, logout and the platform-scoped actions (including
// entering and exiting platform elevation, Phase 0N.3). This list is
// deliberately small and mirrored by the explicit allowlist in
// Tests\Feature\Tenancy\SchoolContextRouteGuardTest -- anything that
// reads or writes one School's data belongs in the School group below.
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/app', [DashboardController::class, 'index'])->name('app.dashboard');

    Route::post('/app/schools/{school}/activate', [SchoolSwitchController::class, 'store'])
        ->name('app.schools.activate');

    // Phase 0H.4D-P1 section 17: User-level "Account Security" --
    // deliberately NOT under app/settings or app/school-setup (those
    // are School-scoped); no `capability:`/`school-membership`
    // middleware, since a User manages their OWN MFA regardless of
    // which School is active. Each mutating action's own
    // fresh-password-confirmation/rate-limiting is enforced inside
    // AccountSecurityController (PasswordConfirmationService), not
    // route middleware -- see its docblock.
    Route::prefix('app/account/security')->name('app.account.security.')->group(function (): void {
        Route::get('/', [AccountSecurityController::class, 'show'])->name('show');
        Route::post('/password-confirmation', [AccountSecurityController::class, 'confirmPassword'])
            ->middleware('throttle:mfa-password-confirmation')
            ->name('password-confirmation');

        Route::post('/mfa/begin', [AccountSecurityController::class, 'beginMfaEnrollment'])->name('mfa.begin');
        Route::post('/mfa/confirm', [AccountSecurityController::class, 'confirmMfaEnrollment'])
            ->middleware('throttle:mfa-enrollment-confirm')
            ->name('mfa.confirm');
        Route::post('/mfa/recovery-codes/regenerate', [AccountSecurityController::class, 'regenerateRecoveryCodes'])
            ->middleware('throttle:mfa-recovery-code')
            ->name('mfa.recovery-codes.regenerate');
        Route::delete('/mfa', [AccountSecurityController::class, 'disableMfa'])
            ->middleware('throttle:mfa-recovery-code')
            ->name('mfa.disable');
    });

    // Phase 0O.3 (ADR 0049 section 2): the person's OWN human API tokens --
    // Account/Security area, no School context (a token represents the
    // person; each API request re-checks their School access). Issue needs
    // an enrolled factor and a fresh code (inside the controller);
    // revoking needs only the session.
    Route::prefix('app/account/api-tokens')->name('app.account.api-tokens.')->group(function (): void {
        Route::get('/', [ApiTokenController::class, 'index'])->name('index');
        Route::post('/', [ApiTokenController::class, 'store'])
            ->middleware('throttle:credential-management')
            ->name('store');
        Route::delete('/{token}', [ApiTokenController::class, 'destroy'])
            ->middleware('throttle:credential-management')
            ->name('destroy');
    });

    // Phase 0H.4D-P1: a tiny infrastructure-only demonstration route
    // proving the `mfa` middleware composes with `capability:` rather
    // than substituting for it -- see MfaDemoController's docblock.
    // Reuses the existing `platform.operations.view` capability rather
    // than inventing a demo-only one; matches
    // IdempotencyDemoController's exact local/testing-only pattern.
    if (app()->environment(['local', 'testing'])) {
        Route::get('/internal/mfa-demo/ping', [MfaDemoController::class, 'ping'])
            ->middleware(['capability:platform.operations.view,platform', 'mfa'])
            ->name('internal.mfa-demo.ping');
    }

    // Phase 0H.4D-P1 section 19: platform-level MFA reset -- see
    // MfaAdminController's docblock for why this is not a
    // School-scoped route.
    Route::post('/app/account/admin/users/{targetUser}/mfa/reset', [MfaAdminController::class, 'reset'])
        ->name('app.account.admin.mfa.reset');

    // Phase 0N.3 (ADR 0044): platform elevation into ONE School -- exact
    // target, confirmation, fresh MFA, 30 minutes, audited. Context-neutral
    // by design (it is how an elevated context begins and ends). The form
    // page requires the capability; the POSTs check it inside
    // SchoolElevationService so a refusal is audited. Exit needs no
    // capability: an actor must always be able to leave.
    Route::prefix('app/platform/elevation')->name('app.platform.elevation.')->group(function (): void {
        Route::get('/', [SchoolElevationController::class, 'create'])
            ->middleware('capability:platform.schools.elevate,platform')
            ->name('create');
        Route::post('/confirm', [SchoolElevationController::class, 'confirm'])
            ->middleware('throttle:platform-elevation')
            ->name('confirm');
        Route::get('/confirm', fn () => redirect()->route('app.platform.elevation.create'))->name('confirm.show');
        Route::post('/', [SchoolElevationController::class, 'store'])
            ->middleware('throttle:platform-elevation')
            ->name('store');
        Route::post('/exit', [SchoolElevationController::class, 'exit'])->name('exit');
    });

    // Phase 0N.5 (ADR 0045 section 5): platform governance of School
    // Groups -- which Schools belong to a Group and who holds Group
    // authority. Reads need platform.school_groups.view; every change is
    // re-checked (manage / grants.manage) and audited inside
    // SchoolGroupGovernanceService. Separate from the Group Admin pages.
    Route::prefix('app/platform/groups')->name('app.platform.groups.')
        ->middleware('capability:platform.school_groups.view,platform')
        ->group(function (): void {
            Route::get('/', [SchoolGroupAdminController::class, 'index'])->name('index');
            Route::post('/', [SchoolGroupAdminController::class, 'store'])->name('store');
            Route::get('/{schoolGroup}', [SchoolGroupAdminController::class, 'show'])->name('show');
            Route::put('/{schoolGroup}', [SchoolGroupAdminController::class, 'rename'])->name('rename');
            Route::post('/{schoolGroup}/archive', [SchoolGroupAdminController::class, 'archive'])->name('archive');
            Route::post('/{schoolGroup}/schools', [SchoolGroupAdminController::class, 'addSchool'])->name('schools.store');
            Route::delete('/{schoolGroup}/schools/{school}', [SchoolGroupAdminController::class, 'removeSchool'])->name('schools.destroy');
            Route::post('/{schoolGroup}/grants', [SchoolGroupAdminController::class, 'grant'])->name('grants.store');
            Route::post('/{schoolGroup}/grants/{grant}/revoke', [SchoolGroupAdminController::class, 'revoke'])->name('grants.revoke');
        });

    // Phase 0N.7 (ADR 0046 sections 7-9): platform audit review --
    // context-neutral, Highly Sensitive; the capability here and again in
    // PlatformAuditLogReviewService, which also requires current MFA
    // assurance and writes one platform.audit_log.viewed per review.
    Route::get('/app/platform/audit-log', [PlatformAuditLogController::class, 'index'])
        ->middleware('capability:platform.audit.view,platform')
        ->name('app.platform.audit-log');

    // Phase 0N.7 (ADR 0046 sections 3-4): grant/revoke the one runtime-
    // assignable platform role (platform_auditor). The root role is never
    // grantable or revocable here; PlatformRoleGovernanceService re-checks
    // the root-reserved capability and audits every refusal.
    // The POSTs carry no capability middleware on purpose: a refusal must
    // reach the service to be audited (platform.role_grant.denied).
    Route::prefix('app/platform/roles')->name('app.platform.roles.')->group(function (): void {
        Route::get('/', [PlatformRoleAdminController::class, 'index'])
            ->middleware('capability:platform.role_grants.manage,platform')
            ->name('index');
        Route::post('/grants', [PlatformRoleAdminController::class, 'grant'])->name('grants.store');
        Route::post('/grants/{assignment}/revoke', [PlatformRoleAdminController::class, 'revoke'])->name('grants.revoke');
    });

    // Phase 0N.9 (ADR 0047): School lifecycle -- create (with the bootstrap
    // School Administrator), replace that administrator while
    // `provisioning`, activate, suspend, resume. Context-neutral; no
    // archive or delete route exists. Reads need platform.schools.manage;
    // the POSTs carry no capability middleware on purpose so a refusal
    // reaches the service and is audited (platform.school.lifecycle_denied).
    Route::prefix('app/platform/schools')->name('app.platform.schools.')->group(function (): void {
        Route::middleware('capability:platform.schools.manage,platform')->group(function (): void {
            Route::get('/', [PlatformSchoolAdminController::class, 'index'])->name('index');
            Route::get('/create', [PlatformSchoolAdminController::class, 'create'])->name('create');
            Route::get('/{school}', [PlatformSchoolAdminController::class, 'show'])->whereUuid('school')->name('show');
            Route::get('/{school}/{action}', [PlatformSchoolAdminController::class, 'review'])
                ->whereUuid('school')->whereIn('action', ['activate', 'suspend', 'resume', 'bootstrap-admin'])->name('review');
        });
        Route::post('/', [PlatformSchoolAdminController::class, 'store'])
            ->middleware('throttle:platform-school-lifecycle')->name('store');
        Route::post('/{school}/{action}', [PlatformSchoolAdminController::class, 'perform'])
            ->whereUuid('school')->whereIn('action', ['activate', 'suspend', 'resume', 'bootstrap-admin'])
            ->middleware('throttle:platform-school-lifecycle')->name('perform');
    });

    // Phase 0N.5 (ADR 0045 sections 9, 12): the Group Admin surface -- the
    // Groups the actor holds a grant in (read-only metadata), and entering
    // one member School through the ADR 0044 elevation flow under that ONE
    // Group's authority. Authorized per request from {schoolGroup}; never
    // School context.
    Route::prefix('app/groups')->name('app.groups.')->group(function (): void {
        Route::get('/', [SchoolGroupController::class, 'index'])->name('index');
        Route::get('/{schoolGroup}', [SchoolGroupController::class, 'show'])->name('show');
        Route::get('/{schoolGroup}/elevation', [SchoolElevationController::class, 'createForGroup'])->name('elevation.create');
        Route::post('/{schoolGroup}/elevation/confirm', [SchoolElevationController::class, 'confirmForGroup'])
            ->middleware('throttle:platform-elevation')
            ->name('elevation.confirm');
        Route::post('/{schoolGroup}/elevation', [SchoolElevationController::class, 'storeForGroup'])
            ->middleware('throttle:platform-elevation')
            ->name('elevation.store');
        // Phase 0N.11 (ADR 0048): the one Group-safe cross-School report,
        // read one School at a time through Analytics' Group-safe gate.
        Route::get('/{schoolGroup}/reports/curriculum-coverage', [GroupReportController::class, 'curriculumCoverage'])
            ->name('reports.curriculum-coverage');
    });
});

// Every School-scoped web route (Phase 0N.1, D10(a)). `school-context`
// (App\Http\Middleware\RequireSchoolContext) requires a valid selected
// School -- active School, active membership, not a disabled account --
// before route model binding, capability checks or the controller run:
// a page request without one goes back to the /app landing, a mutation
// or JSON request gets a 409 `school_context_required`. It never selects
// a School. Controllers here may rely on TenantContext::requireSchool().
Route::middleware(['auth', 'school-context'])->group(function (): void {
    Route::get('/app/settings', [SchoolSettingsController::class, 'show'])
        ->middleware('capability:school.settings.view')
        ->name('app.settings.show');

    // Phase 0O.8A (ADR 0054 section 10): the School's custom domains. View:
    // school.domains.view; add/regenerate/primary/remove: .manage + a fresh
    // MFA code; check now: .manage, per-School/per-domain limits, queued.
    // All checked in SchoolDomainController and SchoolDomainService.
    Route::prefix('app/settings/domains')->name('app.settings.domains.')->group(function (): void {
        Route::get('/', [SchoolDomainController::class, 'index'])->name('index');
        Route::post('/', [SchoolDomainController::class, 'store'])
            ->middleware('throttle:domain-management')
            ->name('store');
        Route::post('/{domain}/challenge', [SchoolDomainController::class, 'regenerate'])
            ->whereUuid('domain')->middleware('throttle:domain-management')->name('challenge');
        Route::post('/{domain}/primary', [SchoolDomainController::class, 'primary'])
            ->whereUuid('domain')->middleware('throttle:domain-management')->name('primary');
        Route::post('/{domain}/revoke', [SchoolDomainController::class, 'revoke'])
            ->whereUuid('domain')->middleware('throttle:domain-management')->name('revoke');
        Route::post('/{domain}/check', [SchoolDomainController::class, 'check'])
            ->whereUuid('domain')->name('check');
    });

    // Phase 0O.3 (ADR 0049 section 3): School Integrations -- partner API
    // clients of the SELECTED School only. View: capability + current MFA
    // assurance; issue/rotate/revoke: `.manage` + a fresh MFA code (both
    // checked in ApiClientController).
    Route::prefix('app/integrations/api-clients')->name('app.integrations.api-clients.')->group(function (): void {
        Route::get('/', [ApiClientController::class, 'index'])->name('index');
        Route::post('/', [ApiClientController::class, 'store'])
            ->middleware('throttle:credential-management')
            ->name('store');
        Route::post('/{client}/rotate', [ApiClientController::class, 'rotate'])
            ->middleware('throttle:credential-management')
            ->name('rotate');
        Route::post('/{client}/revoke', [ApiClientController::class, 'revoke'])
            ->middleware('throttle:credential-management')
            ->name('revoke');
    });

    // Authorization for this action is enforced inside the controller
    // via the AuthorizesCapability trait, not route middleware -- see
    // SchoolSettingsController::update() for why (both patterns are
    // valid; this route demonstrates the controller-level one).
    Route::put('/app/settings', [SchoolSettingsController::class, 'update'])
        ->name('app.settings.update');

    // Phase 0D sections 69-76: the minimal "School Setup" area.
    // Capability checks live inside SchoolSetupController itself
    // (AuthorizesCapability trait), matching SchoolSettingsController's
    // controller-level pattern -- every action re-derives the active
    // School from TenantContext, never a client-supplied id.
    Route::prefix('app/school-setup')->name('app.school-setup.')->group(function (): void {
        Route::get('/', [SchoolSetupController::class, 'index'])->name('index');

        Route::get('/profile', [SchoolSetupController::class, 'profile'])->name('profile');
        Route::put('/profile', [SchoolSetupController::class, 'updateProfile'])->name('profile.update');

        Route::get('/campuses', [SchoolSetupController::class, 'campuses'])->name('campuses');
        Route::post('/campuses', [SchoolSetupController::class, 'storeCampus'])->name('campuses.store');

        Route::get('/academic-years', [SchoolSetupController::class, 'academicYears'])->name('academic-years');
        Route::post('/academic-years', [SchoolSetupController::class, 'storeAcademicYear'])->name('academic-years.store');
        Route::post('/academic-years/{academicYear}/activate', [SchoolSetupController::class, 'activateAcademicYear'])->name('academic-years.activate');
        Route::post('/academic-years/{academicYear}/close', [SchoolSetupController::class, 'closeAcademicYear'])->name('academic-years.close');

        Route::get('/grade-levels', [SchoolSetupController::class, 'gradeLevels'])->name('grade-levels');
        Route::post('/grade-levels', [SchoolSetupController::class, 'storeGradeLevel'])->name('grade-levels.store');

        Route::get('/subjects', [SchoolSetupController::class, 'subjects'])->name('subjects');
        Route::post('/subjects', [SchoolSetupController::class, 'storeSubject'])->name('subjects.store');
    });

    // Phase 1A.6: Student & Guardian Identity administrative UI.
    // Capability checks live inside each controller (AuthorizesCapability
    // trait), matching SchoolSetupController's pattern -- every action
    // re-derives the active School from TenantContext, never a
    // client-supplied id. See
    // docs/modules/STUDENT-GUARDIAN-IDENTITY.md ("Administrative UI").
    Route::prefix('app/students')->name('app.students.')->group(function (): void {
        Route::get('/', [StudentController::class, 'index'])->name('index');
        Route::get('/create', [StudentController::class, 'create'])->name('create');
        Route::post('/', [StudentController::class, 'store'])->name('store');
        Route::get('/{student}', [StudentController::class, 'show'])->name('show');
        Route::get('/{student}/edit', [StudentController::class, 'edit'])->name('edit');
        Route::put('/{student}', [StudentController::class, 'update'])->name('update');
        Route::post('/{student}/status', [StudentController::class, 'changeStatus'])->name('status');

        // "Add guardian" workflow (section 15) -- read-only search/
        // candidate endpoints are plain JSON (fetched live from Vue),
        // not Inertia pages.
        Route::get('/{student}/guardians/add', [StudentGuardianRelationshipController::class, 'create'])->name('guardians.create');
        Route::get('/{student}/guardians/search', [StudentGuardianRelationshipController::class, 'searchGuardians'])->name('guardians.search');
        Route::get('/{student}/guardians/candidates', [StudentGuardianRelationshipController::class, 'candidateGuardians'])->name('guardians.candidates');
        Route::post('/{student}/guardians/link', [StudentGuardianRelationshipController::class, 'linkExisting'])->name('guardians.link');
        Route::post('/{student}/guardians', [StudentGuardianRelationshipController::class, 'linkNew'])->name('guardians.store');

        // Phase 5B.2: the optional School OS account link.
        Route::get('/{student}/account-link/search', [StudentAccountLinkController::class, 'search'])->name('account-link.search');
        Route::post('/{student}/account-link', [StudentAccountLinkController::class, 'store'])->name('account-link.store');
        Route::delete('/{student}/account-link', [StudentAccountLinkController::class, 'destroy'])->name('account-link.destroy');

        // Phase 1B.6: Enrollment administrative UI -- a Student's own
        // "enroll into a Section" flow, nested exactly like the
        // guardians.* routes above.
        Route::get('/{student}/enrollments/create', [StudentEnrollmentController::class, 'create'])->name('enrollments.create');
        Route::post('/{student}/enrollments', [StudentEnrollmentController::class, 'store'])->name('enrollments.store');

        // Phase 0H.4D-P2: the Student Processing Authorization
        // Registry -- Highly Sensitive, so unlike every other route in
        // this group, `capability:` + `mfa` are composed explicitly at
        // the route level (this group's other actions gate capability
        // in-controller; this is the first genuine production
        // consumer of ADR 0037's capability+MFA seam, not a
        // demonstration route). No PATCH of historical records, no
        // DELETE.
        Route::prefix('/{student}/processing-authorizations')->name('processing-authorizations.')->group(function (): void {
            Route::get('/', [StudentProcessingAuthorizationController::class, 'index'])
                ->middleware(['capability:students.processing_authorizations.view', 'mfa'])
                ->name('index');
            Route::post('/', [StudentProcessingAuthorizationController::class, 'store'])
                ->middleware(['capability:students.processing_authorizations.manage', 'mfa'])
                ->name('store');
            Route::post('/{authorization}/withdraw', [StudentProcessingAuthorizationController::class, 'withdraw'])
                ->middleware(['capability:students.processing_authorizations.manage', 'mfa'])
                ->name('withdraw');
            Route::post('/{authorization}/revoke', [StudentProcessingAuthorizationController::class, 'revoke'])
                ->middleware(['capability:students.processing_authorizations.manage', 'mfa'])
                ->name('revoke');
            Route::post('/{authorization}/supersede', [StudentProcessingAuthorizationController::class, 'supersede'])
                ->middleware(['capability:students.processing_authorizations.manage', 'mfa'])
                ->name('supersede');
        });
    });

    Route::prefix('app/relationships')->name('app.relationships.')->group(function (): void {
        Route::put('/{relationship}', [StudentGuardianRelationshipController::class, 'update'])->name('update');
        Route::post('/{relationship}/primary', [StudentGuardianRelationshipController::class, 'setPrimary'])->name('primary');
        Route::delete('/{relationship}', [StudentGuardianRelationshipController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('app/guardians')->name('app.guardians.')->group(function (): void {
        Route::get('/', [GuardianController::class, 'index'])->name('index');
        Route::get('/create', [GuardianController::class, 'create'])->name('create');
        Route::post('/', [GuardianController::class, 'store'])->name('store');
        Route::get('/{guardian}', [GuardianController::class, 'show'])->name('show');
        Route::get('/{guardian}/edit', [GuardianController::class, 'edit'])->name('edit');
        Route::put('/{guardian}', [GuardianController::class, 'update'])->name('update');
        Route::post('/{guardian}/status', [GuardianController::class, 'changeStatus'])->name('status');
        Route::post('/{guardian}/contacts', [GuardianController::class, 'storeContact'])->name('contacts.store');

        // Phase 5B.2: the optional School OS account link (an
        // ALREADY-existing membership).
        Route::get('/{guardian}/account-link/search', [GuardianAccountLinkController::class, 'search'])->name('account-link.search');
        Route::post('/{guardian}/account-link', [GuardianAccountLinkController::class, 'store'])->name('account-link.store');
        Route::delete('/{guardian}/account-link', [GuardianAccountLinkController::class, 'destroy'])->name('account-link.destroy');

        // Phase 5D.3: invite/resend/revoke -- provisions a NEW
        // User/SchoolMembership via the acceptance flow, distinct from
        // account-link above (which only ever links an EXISTING one).
        // Gated by guardians.manage + school.members.manage, see
        // GuardianAccountInvitationController's docblock. No throttle
        // middleware here, matching every other admin Inertia mutation
        // in this group -- `school-api-mutations` is keyed off a
        // `{school}` ROUTE parameter (RateLimiterServiceProvider::
        // tenantKey()), which this `{guardian}`-scoped group does not
        // have; the public token-acceptance endpoints below are where
        // brief §41's rate limiting actually applies.
        Route::post('/{guardian}/account-invitation', [GuardianAccountInvitationController::class, 'store'])
            ->name('account-invitation.store');
        Route::post('/{guardian}/account-invitation/resend', [GuardianAccountInvitationController::class, 'resend'])
            ->name('account-invitation.resend');
        Route::delete('/{guardian}/account-invitation', [GuardianAccountInvitationController::class, 'destroy'])
            ->name('account-invitation.destroy');

        // Phase 5D.2 §29/§30: administrative recording of the
        // Guardian's own domain communication preference/consent --
        // gated by BOTH communications.manage AND guardians.manage,
        // see the controller's docblock.
        Route::put('/{guardian}/communication-preference', [GuardianCommunicationPreferenceController::class, 'updatePreference'])->name('communication-preference.update');
        Route::post('/{guardian}/communication-consent', [GuardianCommunicationPreferenceController::class, 'recordConsent'])->name('communication-consent.record');
    });

    Route::prefix('app/contacts')->name('app.contacts.')->group(function (): void {
        Route::post('/{contact}/primary', [GuardianController::class, 'setPrimaryContact'])->name('primary');
        Route::post('/{contact}/deactivate', [GuardianController::class, 'deactivateContact'])->name('deactivate');
    });

    // Phase 5A.1: the Communication Hub foundation -- session-
    // authenticated Inertia pages against the ambient active School,
    // same convention as SchoolSetupController above. Capability checks
    // live inside CommunicationHubController itself (AuthorizesCapability
    // trait).
    Route::prefix('app/communications')->name('app.communications.')->group(function (): void {
        // Phase 5A.8 §33: the Hub's root is now the operational Inbox
        // (App\Domain\Communications\Http\Controllers\CommunicationInboxController),
        // not the conversation list -- that moved to `/conversations`
        // below (brief §33's own suggested route shape).
        Route::get('/', [CommunicationInboxController::class, 'index'])->name('index');
        Route::get('/unread', [CommunicationInboxController::class, 'unread'])->name('unread');
        Route::get('/sent', [CommunicationInboxController::class, 'sent'])->name('sent');
        Route::get('/failed', [CommunicationInboxController::class, 'failed'])->name('failed');
        Route::get('/search', [CommunicationInboxController::class, 'search'])->name('search');

        Route::get('/conversations', [CommunicationHubController::class, 'conversations'])->name('conversations');
        Route::post('/conversations', [CommunicationHubController::class, 'store'])->name('store');

        // Phase 5A.2: registered BEFORE the '/{thread}' wildcard below
        // so 'announcements' never matches as a thread id.
        Route::prefix('announcements')->name('announcements.')->group(function (): void {
            Route::get('/', [AnnouncementController::class, 'index'])->name('index');
            Route::get('/create', [AnnouncementController::class, 'create'])->name('create');
            Route::post('/', [AnnouncementController::class, 'store'])->name('store');
            Route::get('/{announcement}', [AnnouncementController::class, 'show'])->name('show');
            Route::put('/{announcement}', [AnnouncementController::class, 'update'])->name('update');
            Route::post('/{announcement}/publish', [AnnouncementController::class, 'publish'])->name('publish');
            Route::post('/{announcement}/cancel', [AnnouncementController::class, 'cancel'])->name('cancel');
            // Phase 5A.4: one endpoint serves both the initial schedule
            // and a reschedule -- see AnnouncementController::schedule()'s
            // docblock.
            Route::post('/{announcement}/schedule', [AnnouncementController::class, 'schedule'])->name('schedule');

            // Phase 5A.6: attachment upload/removal, scoped to their
            // owning Announcement -- download is registered separately
            // below (brief §11's exact route shape).
            Route::post('/{announcement}/attachments', [CommunicationAttachmentController::class, 'store'])->name('attachments.store');
            Route::delete('/{announcement}/attachments/{attachment}', [CommunicationAttachmentController::class, 'destroy'])->name('attachments.destroy');

            // Phase 5A.11: read-only audit/delivery-analytics surfaces
            // for one Announcement -- distinct capabilities
            // (communications.audit.view / communications.manage, brief
            // §30), distinct controllers (CommunicationAuditController /
            // CommunicationAnalyticsController, brief §4's audit-vs-
            // analytics separation).
            Route::get('/{announcement}/audit', [CommunicationAuditController::class, 'show'])->name('audit');
            Route::get('/{announcement}/analytics', [CommunicationAnalyticsController::class, 'announcement'])->name('analytics');

            // Phase 5A.12 §27/§29/§37: submit-for-approval and
            // withdrawal, scoped to their owning Announcement --
            // approve/reject live under their own
            // `/app/communications/approvals` prefix below (brief §42),
            // since a decision is made against the REQUEST, not the
            // Announcement directly.
            Route::post('/{announcement}/submit-approval', [AnnouncementController::class, 'submitForApproval'])->name('submit-approval');
            Route::post('/{announcement}/withdraw-approval', [AnnouncementController::class, 'withdrawApproval'])->name('withdraw-approval');
        });

        // Phase 5A.12 §42/§45: registered BEFORE the '/{thread}'
        // wildcard below, same reasoning as 'announcements' above.
        Route::prefix('approvals')->name('approvals.')->group(function (): void {
            Route::get('/', [CommunicationApprovalController::class, 'index'])->name('index');
            Route::get('/{approvalRequest}', [CommunicationApprovalController::class, 'show'])->name('show');
            Route::post('/{approvalRequest}/approve', [CommunicationApprovalController::class, 'approve'])->name('approve');
            Route::post('/{approvalRequest}/reject', [CommunicationApprovalController::class, 'reject'])->name('reject');
        });

        // Phase 5A.6 §11: registered BEFORE the '/{thread}' wildcard
        // below, same reasoning as 'announcements'/'templates' above.
        // An attachment id alone resolves its own School/parent
        // Announcement -- no announcement id appears in this route.
        Route::get('/attachments/{attachment}/download', [CommunicationAttachmentController::class, 'download'])->name('attachments.download');

        // Phase 5A.4: registered BEFORE the '/{thread}' wildcard below,
        // same reasoning as 'announcements' above.
        Route::prefix('templates')->name('templates.')->group(function (): void {
            Route::get('/', [CommunicationTemplateController::class, 'index'])->name('index');
            Route::get('/create', [CommunicationTemplateController::class, 'create'])->name('create');
            Route::post('/', [CommunicationTemplateController::class, 'store'])->name('store');
            Route::get('/{template}/edit', [CommunicationTemplateController::class, 'edit'])->name('edit');
            Route::put('/{template}', [CommunicationTemplateController::class, 'update'])->name('update');
            Route::post('/{template}/activate', [CommunicationTemplateController::class, 'activate'])->name('activate');
            Route::post('/{template}/deactivate', [CommunicationTemplateController::class, 'deactivate'])->name('deactivate');
        });

        // Phase 5A.5: registered BEFORE the '/{thread}' wildcard below,
        // same reasoning as 'announcements'/'templates' above.
        Route::get('/preferences', [CommunicationPreferenceController::class, 'show'])->name('preferences');
        Route::put('/preferences', [CommunicationPreferenceController::class, 'update'])->name('preferences.update');

        Route::prefix('settings')->name('settings.')->group(function (): void {
            Route::get('/channels', [CommunicationChannelPolicyController::class, 'show'])->name('channels');
            Route::put('/channels', [CommunicationChannelPolicyController::class, 'update'])->name('channels.update');

            // Phase 5A.9: write side of the same Channels settings
            // page's quiet-hours section -- see
            // CommunicationDeliveryTimingPolicyController's docblock.
            Route::put('/timing', [CommunicationDeliveryTimingPolicyController::class, 'update'])->name('timing.update');

            // Phase 5A.12 §41: write side of the same Channels settings
            // page's Approval Workflow section -- see
            // CommunicationApprovalPolicyController's docblock.
            Route::put('/approvals', [CommunicationApprovalPolicyController::class, 'update'])->name('approvals.update');

            // Phase 5D.1 §16, wired into the Channels settings page in
            // 5D.1b: write side of the page's Private conversations
            // section -- READ is aggregated into
            // CommunicationChannelPolicyController::show()'s existing
            // payload, same split as 'timing'/'approvals' above.
            Route::put('/conversations', [CommunicationConversationPolicyController::class, 'update'])->name('conversations.update');
        });

        // Phase 5A.7 §10: registered BEFORE the '/{thread}' wildcard
        // below, same reasoning as 'announcements'/'templates' above.
        Route::get('/participants/search', [CommunicationHubController::class, 'searchParticipants'])->name('participants.search');

        // Phase 5D.1 §27: the conversation composer's Guardian/Student
        // domain-participant search -- registered BEFORE the '/{thread}'
        // wildcard below, same reasoning as 'participants/search' above.
        Route::get('/participants/search/guardians', [CommunicationHubController::class, 'searchGuardianParticipants'])->name('participants.search-guardians');
        Route::get('/participants/search/students', [CommunicationHubController::class, 'searchStudentParticipants'])->name('participants.search-students');

        // Phase 5B.1 §25/§26: registered BEFORE the '/{thread}'
        // wildcard below, same reasoning as 'participants/search'
        // above -- the Announcement composer's Student/Guardian
        // audience-picker search.
        Route::get('/audience/students/search', [CommunicationAudienceSearchController::class, 'students'])->name('audience.students.search');
        Route::get('/audience/guardians/search', [CommunicationAudienceSearchController::class, 'guardians'])->name('audience.guardians.search');

        // Phase 5B.3 §6/§7/§34: the Announcement composer's Grade/
        // Section academic-cohort picker search -- same registration
        // reasoning as 'audience/students|guardians/search' above.
        Route::get('/audience/grade-levels/search', [CommunicationAudienceSearchController::class, 'gradeLevels'])->name('audience.grade-levels.search');
        Route::get('/audience/sections/search', [CommunicationAudienceSearchController::class, 'sections'])->name('audience.sections.search');

        // Phase 5C.1: the Announcement composer's SubjectOffering
        // academic-cohort picker search -- same registration reasoning
        // as 'audience/grade-levels|sections/search' above. Backend/API
        // surface only in this checkpoint -- no composer UI option
        // exists yet to reach it.
        Route::get('/audience/subject-offerings/search', [CommunicationAudienceSearchController::class, 'subjectOfferings'])->name('audience.subject-offerings.search');

        // Phase 5A.11 §25/§32: the School-wide operational delivery
        // overview -- registered BEFORE the '/{thread}' wildcard below,
        // same reasoning as every other literal-segment route in this
        // group.
        Route::get('/analytics', [CommunicationAnalyticsController::class, 'overview'])->name('analytics');

        Route::get('/{thread}', [CommunicationHubController::class, 'show'])->name('show');
        Route::post('/{thread}/messages', [CommunicationHubController::class, 'storeMessage'])->name('messages.store');
        Route::post('/{thread}/archive', [CommunicationHubController::class, 'archive'])->name('archive');
        Route::post('/{thread}/unarchive', [CommunicationHubController::class, 'unarchive'])->name('unarchive');

        // Phase 5A.7 §15: attachment upload/removal scoped to their
        // owning Thread, mirroring the Announcement pair above --
        // download reuses the SAME generic endpoint registered above
        // (brief §32: authorization is re-derived from the parent,
        // whichever type it is).
        Route::post('/{thread}/attachments', [CommunicationAttachmentController::class, 'storeForThread'])->name('threads.attachments.store');
        Route::delete('/{thread}/attachments/{attachment}', [CommunicationAttachmentController::class, 'destroyForThread'])->name('threads.attachments.destroy');
    });

    // Phase 1B.6: Enrollment administrative UI (docs/modules/STUDENT-ENROLLMENT.md,
    // "Administrative UI"). Capability checks live inside the controller
    // (AuthorizesCapability trait), matching every other App/ controller's
    // pattern -- every action re-derives the active School from
    // TenantContext, never a client-supplied id.
    Route::prefix('app/enrollments')->name('app.enrollments.')->group(function (): void {
        Route::get('/', [StudentEnrollmentController::class, 'index'])->name('index');
        Route::post('/{enrollment}/complete', [StudentEnrollmentController::class, 'complete'])->name('complete');
        Route::post('/{enrollment}/withdraw', [StudentEnrollmentController::class, 'withdraw'])->name('withdraw');
        Route::post('/{enrollment}/cancel', [StudentEnrollmentController::class, 'cancel'])->name('cancel');
        Route::get('/{enrollment}/transfer', [StudentEnrollmentController::class, 'transferCreate'])->name('transfer.create');
        Route::post('/{enrollment}/transfer', [StudentEnrollmentController::class, 'transfer'])->name('transfer.store');
    });

    // Phase 1B.7F: Enrollment Rollover administrative UI
    // (docs/modules/STUDENT-ENROLLMENT.md, "Rollover Administrative
    // UI"). Capability checks live inside each controller
    // (AuthorizesCapability trait), matching every other App/
    // controller's pattern -- every action re-derives the active
    // School from TenantContext, never a client-supplied id. No
    // Grade/Section Mapping delete / Plan cancellation routes exist --
    // neither has a sanctioned Application-service operation (Phase
    // 1B.7E). Subject mappings (Phase 1G.4, below) DO have a delete
    // route -- `removeSubjectMapping()` is a real, sanctioned Phase
    // 1G.1 operation returning a source Offering to UNCONFIGURED,
    // distinct from the Grade/Section mapping model's own semantics.
    Route::prefix('app/enrollment-rollovers')->name('app.enrollment-rollovers.')->group(function (): void {
        Route::get('/', [EnrollmentRolloverController::class, 'index'])->name('index');
        Route::get('/create', [EnrollmentRolloverController::class, 'create'])->name('create');
        Route::post('/', [EnrollmentRolloverController::class, 'store'])->name('store');
        Route::get('/{rollover}', [EnrollmentRolloverController::class, 'show'])->name('show');
        Route::post('/{rollover}/validate', [EnrollmentRolloverController::class, 'validate'])->name('validate');
        Route::post('/{rollover}/start', [EnrollmentRolloverController::class, 'start'])->name('start');
        Route::post('/{rollover}/resume', [EnrollmentRolloverController::class, 'resume'])->name('resume');

        Route::post('/{rollover}/mappings', [EnrollmentRolloverMappingController::class, 'store'])->name('mappings.store');
        Route::patch('/{rollover}/mappings/{mapping}', [EnrollmentRolloverMappingController::class, 'update'])->name('mappings.update');

        // Phase 1G.4: subject-mapping configuration, addressed by the
        // SOURCE SubjectOffering id -- same shape/rationale as the JSON
        // API's identical routes (routes/api.php).
        Route::put('/{rollover}/subject-mappings/{subjectOffering}', [EnrollmentRolloverSubjectMappingController::class, 'upsert'])->name('subject-mappings.upsert');
        Route::delete('/{rollover}/subject-mappings/{subjectOffering}', [EnrollmentRolloverSubjectMappingController::class, 'destroy'])->name('subject-mappings.destroy');

        Route::patch('/{rollover}/items/{item}', [EnrollmentRolloverItemController::class, 'update'])->name('items.update');
    });

    // Phase 1H.1: SubjectOffering roster / elective administration
    // (docs/students/PHASE-1H-1-ELECTIVE-ADMINISTRATION-UI.md).
    // Capability checks live inside each controller
    // (AuthorizesCapability trait), matching every other App/
    // controller's pattern -- every action re-derives the active
    // School from TenantContext, never a client-supplied id. Gated
    // entirely by `academics.subjects.view`/`.manage` -- never
    // `students.view`/`students.manage` (the 1D Guardian-picker
    // lesson: `eligible-students`/{enrollment}/transfer-targets are
    // narrow workflow-specific adapters, not the generic Student
    // picker). Mutation/adapter actions live on
    // StudentSubjectEnrollmentController (App), mirroring the exact
    // domain-level split the Bearer-token JSON API already uses.
    Route::prefix('app/subject-offerings')->name('app.subject-offerings.')->group(function (): void {
        Route::get('/', [SubjectOfferingController::class, 'index'])->name('index');
        Route::get('/{subjectOffering}', [SubjectOfferingController::class, 'show'])->name('show');
        Route::get('/{subjectOffering}/eligible-students', [StudentSubjectEnrollmentController::class, 'eligibleStudents'])->name('eligible-students');
        Route::post('/{subjectOffering}/enrollments', [StudentSubjectEnrollmentController::class, 'enroll'])->name('enrollments.store');
    });

    Route::prefix('app/subject-enrollments')->name('app.subject-enrollments.')->group(function (): void {
        Route::post('/{enrollment}/withdraw', [StudentSubjectEnrollmentController::class, 'withdraw'])->name('withdraw');
        Route::post('/{enrollment}/cancel', [StudentSubjectEnrollmentController::class, 'cancel'])->name('cancel');
        Route::post('/{enrollment}/transfer', [StudentSubjectEnrollmentController::class, 'transfer'])->name('transfer');
        Route::get('/{enrollment}/transfer-targets', [StudentSubjectEnrollmentController::class, 'transferTargets'])->name('transfer-targets');
    });

    // Phase 1D.6: Admissions administrative UI
    // (docs/admissions/PHASE-1D-6-ADMINISTRATIVE-UI.md). Capability
    // checks live inside each controller (AuthorizesCapability trait),
    // matching every other App/ controller's pattern -- every action
    // re-derives the active School from TenantContext, never a
    // client-supplied id. 'applicants' and 'create' are registered
    // BEFORE the '/{admissionApplication}' wildcard below, same
    // reasoning as the Communications group's 'announcements'/
    // 'templates' literal-segment routes.
    Route::prefix('app/admissions')->name('app.admissions.')->group(function (): void {
        Route::get('/', [AdmissionApplicationController::class, 'index'])->name('index');

        Route::prefix('applicants')->name('applicants.')->group(function (): void {
            Route::get('/', [ApplicantController::class, 'index'])->name('index');
            Route::get('/create', [ApplicantController::class, 'create'])->name('create');
            Route::post('/', [ApplicantController::class, 'store'])->name('store');
            Route::get('/{applicant}', [ApplicantController::class, 'show'])->name('show');

            Route::get('/{applicant}/applications/create', [AdmissionApplicationController::class, 'create'])->name('applications.create');
            Route::post('/{applicant}/applications', [AdmissionApplicationController::class, 'store'])->name('applications.store');
        });

        // Guardian-picker adapter for the conversion form's "link
        // existing Guardian" mode -- see AdmissionApplicationController's
        // class docblock for why this is a narrow new pair rather than
        // reusing /app/students/{student}/guardians/search|candidates.
        Route::get('/guardians/search', [AdmissionApplicationController::class, 'searchGuardians'])->name('guardians.search');
        Route::get('/guardians/candidates', [AdmissionApplicationController::class, 'candidateGuardians'])->name('guardians.candidates');

        Route::get('/{admissionApplication}', [AdmissionApplicationController::class, 'show'])->name('show');
        Route::post('/{admissionApplication}/submit', [AdmissionApplicationController::class, 'submit'])->name('submit');
        Route::post('/{admissionApplication}/accept', [AdmissionApplicationController::class, 'accept'])->name('accept');
        Route::post('/{admissionApplication}/reject', [AdmissionApplicationController::class, 'reject'])->name('reject');
        Route::post('/{admissionApplication}/withdraw', [AdmissionApplicationController::class, 'withdraw'])->name('withdraw');
        Route::post('/{admissionApplication}/convert', [AdmissionApplicationController::class, 'convert'])->name('convert');
    });

    // Phase 10A: Library catalogue + circulation administrative UI.
    // Capability checks live inside each controller
    // (AuthorizesCapability trait), matching every other module's
    // Inertia controller in this file.
    Route::prefix('app/library/titles')->name('app.library.titles.')->group(function (): void {
        Route::get('/', [LibraryCatalogueController::class, 'index'])->name('index');
        Route::get('/create', [LibraryCatalogueController::class, 'create'])->name('create');
        Route::post('/', [LibraryCatalogueController::class, 'store'])->name('store');
        Route::get('/{libraryTitle}', [LibraryCatalogueController::class, 'show'])->name('show');
        Route::post('/{libraryTitle}/copies', [LibraryCatalogueController::class, 'storeCopy'])->name('copies.store');
    });

    Route::prefix('app/library/circulation')->name('app.library.circulation.')->group(function (): void {
        Route::get('/', [LibraryCirculationController::class, 'index'])->name('index');
        Route::get('/create', [LibraryCirculationController::class, 'create'])->name('create');
        Route::get('/search/copies', [LibraryCirculationController::class, 'searchAvailableCopies'])->name('search-copies');
        Route::get('/search/students', [LibraryCirculationController::class, 'searchStudents'])->name('search-students');
        Route::post('/', [LibraryCirculationController::class, 'store'])->name('store');
        Route::post('/{libraryLoan}/check-in', [LibraryCirculationController::class, 'checkIn'])->name('check-in');
    });

    // Phase 10B: Transport (Routes/Stops, Vehicles, Route operational
    // Vehicle/Driver assignment, Student Transport assignment)
    // administrative UI. Capability checks live inside each controller
    // (AuthorizesCapability trait), matching every other module's
    // Inertia controller in this file.
    Route::prefix('app/transport/routes')->name('app.transport.routes.')->group(function (): void {
        Route::get('/', [TransportRouteController::class, 'index'])->name('index');
        Route::get('/create', [TransportRouteController::class, 'create'])->name('create');
        Route::post('/', [TransportRouteController::class, 'store'])->name('store');
        Route::get('/{transportRoute}', [TransportRouteController::class, 'show'])->name('show');
        Route::patch('/{transportRoute}', [TransportRouteController::class, 'update'])->name('update');
        Route::post('/{transportRoute}/stops', [TransportRouteController::class, 'storeStop'])->name('stops.store');
    });

    Route::patch('app/transport/stops/{transportStop}', [TransportRouteController::class, 'updateStop'])
        ->name('app.transport.stops.update');

    Route::prefix('app/transport/vehicles')->name('app.transport.vehicles.')->group(function (): void {
        Route::get('/', [TransportVehicleController::class, 'index'])->name('index');
        Route::get('/create', [TransportVehicleController::class, 'create'])->name('create');
        Route::post('/', [TransportVehicleController::class, 'store'])->name('store');
        Route::patch('/{transportVehicle}', [TransportVehicleController::class, 'update'])->name('update');
    });

    Route::prefix('app/transport/operations')->name('app.transport.operations.')->group(function (): void {
        Route::get('/', [TransportOperationsController::class, 'index'])->name('index');
        Route::get('/search/vehicles', [TransportOperationsController::class, 'searchVehicles'])->name('search-vehicles');
        Route::get('/search/drivers', [TransportOperationsController::class, 'searchDrivers'])->name('search-drivers');
        Route::get('/{transportRoute}', [TransportOperationsController::class, 'show'])->name('show');
        Route::post('/{transportRoute}', [TransportOperationsController::class, 'store'])->name('store');
    });

    Route::post('app/transport/route-assignments/{transportRouteAssignment}/end', [TransportOperationsController::class, 'end'])
        ->name('app.transport.route-assignments.end');

    Route::prefix('app/transport/assignments')->name('app.transport.assignments.')->group(function (): void {
        Route::get('/', [TransportStudentAssignmentController::class, 'index'])->name('index');
        Route::get('/create', [TransportStudentAssignmentController::class, 'create'])->name('create');
        Route::get('/search/students', [TransportStudentAssignmentController::class, 'searchStudents'])->name('search-students');
        Route::get('/search/routes', [TransportStudentAssignmentController::class, 'searchRoutes'])->name('search-routes');
        Route::get('/routes/{transportRoute}/stops', [TransportStudentAssignmentController::class, 'routeStops'])->name('route-stops');
        Route::post('/', [TransportStudentAssignmentController::class, 'store'])->name('store');
        Route::post('/{transportStudentAssignment}/end', [TransportStudentAssignmentController::class, 'end'])->name('end');
    });

    // Phase 10C: Visitor (directory, check-in/check-out Visit
    // lifecycle) administrative UI. Capability checks live inside each
    // controller (AuthorizesCapability trait), matching every other
    // module's Inertia controller in this file.
    Route::prefix('app/visitor/directory')->name('app.visitor.directory.')->group(function (): void {
        Route::get('/', [VisitorController::class, 'index'])->name('index');
        Route::get('/create', [VisitorController::class, 'create'])->name('create');
        Route::post('/', [VisitorController::class, 'store'])->name('store');
        Route::patch('/{visitor}', [VisitorController::class, 'update'])->name('update');
    });

    Route::prefix('app/visitor/visits')->name('app.visitor.visits.')->group(function (): void {
        Route::get('/', [VisitorVisitController::class, 'index'])->name('index');
        Route::get('/create', [VisitorVisitController::class, 'create'])->name('create');
        Route::get('/search/visitors', [VisitorVisitController::class, 'searchVisitors'])->name('search-visitors');
        Route::get('/search/hosts', [VisitorVisitController::class, 'searchHosts'])->name('search-hosts');
        Route::post('/', [VisitorVisitController::class, 'store'])->name('store');
        Route::post('/{visitorVisit}/end', [VisitorVisitController::class, 'end'])->name('end');
    });

    // Phase 0G.7: Finance/Fees/Payments administrative UI
    // (docs/modules/FINANCE.md, "0G.7 as-built"). Session-authenticated
    // Inertia pages against the ambient active School -- the same
    // convention every other App/ controller in this file follows, NOT
    // the Bearer-token JSON API under /api/v1 that 0G.6 built (that
    // surface exists for Flutter/external consumers). Capability checks
    // live inside each controller (AuthorizesCapability trait), same as
    // every other module. Payments is read-only throughout -- no
    // `finance.payments.manage` capability exists and no mutation route
    // is registered for it.
    Route::prefix('app/finance')->name('app.finance.')->group(function (): void {
        Route::get('/', [FinanceController::class, 'index'])->name('index');

        Route::get('/ledger-accounts', [FinanceLedgerAccountController::class, 'index'])->name('ledger-accounts.index');

        // 'create' registered BEFORE the '/{journalEntry}' wildcard
        // below, matching this file's own established convention
        // (e.g. 'announcements'/'templates' inside the Communications
        // group above).
        Route::get('/journal-entries/create', [FinanceJournalEntryController::class, 'create'])->name('journal-entries.create');
        Route::get('/journal-entries', [FinanceJournalEntryController::class, 'index'])->name('journal-entries.index');
        Route::post('/journal-entries', [FinanceJournalEntryController::class, 'store'])->name('journal-entries.store');
        Route::get('/journal-entries/{journalEntry}', [FinanceJournalEntryController::class, 'show'])->name('journal-entries.show');
        Route::post('/journal-entries/{journalEntry}/reverse', [FinanceJournalEntryController::class, 'reverse'])->name('journal-entries.reverse');

        // 'create' and 'students/search' registered BEFORE the
        // '/{charge}' wildcard below, same reasoning as
        // 'journal-entries/create' above.
        Route::get('/charges/create', [FinanceChargeController::class, 'create'])->name('charges.create');
        Route::get('/charges/students/search', [FinanceChargeController::class, 'searchStudents'])->name('charges.students.search');
        Route::get('/charges', [FinanceChargeController::class, 'index'])->name('charges.index');
        Route::post('/charges', [FinanceChargeController::class, 'store'])->name('charges.store');
        Route::get('/charges/{charge}', [FinanceChargeController::class, 'show'])->name('charges.show');
        Route::post('/charges/{charge}/cancel', [FinanceChargeController::class, 'cancel'])->name('charges.cancel');

        Route::get('/payments', [FinancePaymentController::class, 'index'])->name('payments.index');
        Route::get('/payments/{payment}', [FinancePaymentController::class, 'show'])->name('payments.show');
    });

    // Phase 8A closure correction (item 2) -- the HR administrative UI
    // the original roadmap promised. Capability checks live inside each
    // controller (AuthorizesCapability trait), matching every other
    // module's Inertia controller in this file -- no route-level
    // `capability:` middleware for these session-authenticated pages
    // (that pattern is reserved for the Bearer-token /api/v1 JSON API,
    // see item 9's own reasoning there). Every mutation delegates to
    // the SAME Application-layer services the JSON API controllers use.
    Route::prefix('app/hr')->name('app.hr.')->group(function (): void {
        Route::get('/', [HrController::class, 'index'])->name('index');

        // 'employees/create' and 'employees/import' registered BEFORE
        // the '/{employee}' wildcard below, matching this file's own
        // established convention.
        Route::get('/employees/create', [HrEmployeeController::class, 'create'])->name('employees.create');
        Route::get('/employees', [HrEmployeeController::class, 'index'])->name('employees.index');
        Route::post('/employees', [HrEmployeeController::class, 'store'])->name('employees.store');

        Route::get('/employees/import', [HrEmployeeImportController::class, 'create'])->name('employees.import.create');
        Route::post('/employees/import', [HrEmployeeImportController::class, 'store'])->name('employees.import.store');

        Route::get('/employees/{employee}', [HrEmployeeController::class, 'show'])->name('employees.show');
        Route::get('/employees/{employee}/edit', [HrEmployeeController::class, 'edit'])->name('employees.edit');
        Route::put('/employees/{employee}', [HrEmployeeController::class, 'update'])->name('employees.update');
        Route::post('/employees/{employee}/archive', [HrEmployeeController::class, 'archive'])->name('employees.archive');
        Route::post('/employees/{employee}/restore', [HrEmployeeController::class, 'restore'])->name('employees.restore');

        Route::put('/employees/{employee}/personal-detail', [HrEmployeePersonalDetailController::class, 'update'])->name('employees.personal-detail.update');

        Route::post('/employees/{employee}/addresses', [HrEmployeeAddressController::class, 'store'])->name('employees.addresses.store');
        Route::delete('/employees/{employee}/addresses/{address}', [HrEmployeeAddressController::class, 'destroy'])->name('employees.addresses.destroy');

        Route::post('/employees/{employee}/emergency-contacts', [HrEmployeeEmergencyContactController::class, 'store'])->name('employees.emergency-contacts.store');
        Route::delete('/employees/{employee}/emergency-contacts/{contact}', [HrEmployeeEmergencyContactController::class, 'destroy'])->name('employees.emergency-contacts.destroy');
        Route::post('/employees/{employee}/emergency-contacts/{contact}/primary', [HrEmployeeEmergencyContactController::class, 'setPrimary'])->name('employees.emergency-contacts.set-primary');

        Route::post('/employees/{employee}/notes', [HrEmployeeNoteController::class, 'store'])->name('employees.notes.store');
        Route::delete('/employees/{employee}/notes/{note}', [HrEmployeeNoteController::class, 'destroy'])->name('employees.notes.destroy');

        Route::post('/employees/{employee}/employment-records', [HrEmploymentController::class, 'store'])->name('employees.employment-records.store');
        Route::post('/employees/{employee}/employment-records/{employment}/end', [HrEmploymentController::class, 'end'])->name('employees.employment-records.end');
        Route::post('/employees/{employee}/employment-records/{employment}/separate', [HrEmploymentController::class, 'separate'])->name('employees.employment-records.separate');
        Route::post('/employees/{employee}/rehire', [HrEmploymentController::class, 'rehire'])->name('employees.rehire');

        Route::post('/employees/{employee}/employment-records/{employment}/assignments', [HrEmployeeAssignmentController::class, 'store'])->name('employees.assignments.store');
        Route::post('/employees/{employee}/employment-records/{employment}/assignments/{assignment}/end', [HrEmployeeAssignmentController::class, 'end'])->name('employees.assignments.end');
        Route::post('/employees/{employee}/employment-records/{employment}/assignments/{assignment}/primary', [HrEmployeeAssignmentController::class, 'setPrimary'])->name('employees.assignments.set-primary');
        Route::post('/employees/{employee}/employment-records/{employment}/assignments/{assignment}/manager', [HrEmployeeAssignmentController::class, 'setManager'])->name('employees.assignments.set-manager');

        Route::post('/employees/{employee}/qualifications', [HrEmployeeQualificationController::class, 'store'])->name('employees.qualifications.store');
        Route::delete('/employees/{employee}/qualifications/{qualification}', [HrEmployeeQualificationController::class, 'destroy'])->name('employees.qualifications.destroy');
        Route::post('/employees/{employee}/qualifications/{qualification}/verify', [HrEmployeeQualificationController::class, 'verify'])->name('employees.qualifications.verify');
        Route::post('/employees/{employee}/qualifications/{qualification}/reject', [HrEmployeeQualificationController::class, 'reject'])->name('employees.qualifications.reject');

        Route::post('/employees/{employee}/experience', [HrEmployeeExperienceController::class, 'store'])->name('employees.experience.store');
        Route::delete('/employees/{employee}/experience/{experience}', [HrEmployeeExperienceController::class, 'destroy'])->name('employees.experience.destroy');

        Route::post('/employees/{employee}/certifications', [HrEmployeeCertificationController::class, 'store'])->name('employees.certifications.store');
        Route::delete('/employees/{employee}/certifications/{certification}', [HrEmployeeCertificationController::class, 'destroy'])->name('employees.certifications.destroy');
        Route::post('/employees/{employee}/certifications/{certification}/verify', [HrEmployeeCertificationController::class, 'verify'])->name('employees.certifications.verify');
        Route::post('/employees/{employee}/certifications/{certification}/reject', [HrEmployeeCertificationController::class, 'reject'])->name('employees.certifications.reject');

        Route::post('/employees/{employee}/hr-document-records', [HrEmployeeDocumentController::class, 'store'])->name('employees.hr-document-records.store');
        Route::post('/employees/{employee}/hr-document-records/{document}/archive', [HrEmployeeDocumentController::class, 'archive'])->name('employees.hr-document-records.archive');

        Route::get('/departments', [HrDepartmentController::class, 'index'])->name('departments.index');
        Route::post('/departments', [HrDepartmentController::class, 'store'])->name('departments.store');
        Route::post('/departments/{department}/archive', [HrDepartmentController::class, 'archive'])->name('departments.archive');
        Route::post('/departments/{department}/reactivate', [HrDepartmentController::class, 'reactivate'])->name('departments.reactivate');

        Route::get('/positions', [HrPositionController::class, 'index'])->name('positions.index');
        Route::post('/positions', [HrPositionController::class, 'store'])->name('positions.store');
        Route::post('/positions/{position}/archive', [HrPositionController::class, 'archive'])->name('positions.archive');
        Route::post('/positions/{position}/reactivate', [HrPositionController::class, 'reactivate'])->name('positions.reactivate');

        Route::get('/categories', [HrEmployeeCategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [HrEmployeeCategoryController::class, 'store'])->name('categories.store');
        Route::post('/categories/{category}/archive', [HrEmployeeCategoryController::class, 'archive'])->name('categories.archive');
        Route::post('/categories/{category}/reactivate', [HrEmployeeCategoryController::class, 'reactivate'])->name('categories.reactivate');
    });

    // Phase 10D: Hostel (Hostel/Room/Bed directory, Student residency
    // lifecycle) administrative UI. Capability checks live inside each
    // controller (AuthorizesCapability trait), matching every other
    // module's Inertia controller in this file.
    Route::prefix('app/hostels')->name('app.hostels.')->group(function (): void {
        Route::get('/', [HostelController::class, 'index'])->name('index');
        Route::get('/create', [HostelController::class, 'create'])->name('create');
        Route::post('/', [HostelController::class, 'store'])->name('store');
        Route::get('/{hostel}', [HostelController::class, 'show'])->name('show');
        Route::patch('/{hostel}', [HostelController::class, 'update'])->name('update');
        Route::post('/{hostel}/rooms', [HostelController::class, 'storeRoom'])->name('rooms.store');
    });

    Route::prefix('app/hostel-rooms')->name('app.hostel-rooms.')->group(function (): void {
        Route::get('/{hostelRoom}', [HostelRoomController::class, 'show'])->name('show');
        Route::patch('/{hostelRoom}', [HostelRoomController::class, 'update'])->name('update');
        Route::post('/{hostelRoom}/beds', [HostelRoomController::class, 'storeBed'])->name('beds.store');
    });

    Route::patch('app/hostel-beds/{hostelBed}', [HostelRoomController::class, 'updateBed'])
        ->name('app.hostel-beds.update');

    Route::prefix('app/hostel-residency')->name('app.hostel-residency.')->group(function (): void {
        Route::get('/', [HostelResidencyController::class, 'index'])->name('index');
        Route::get('/create', [HostelResidencyController::class, 'create'])->name('create');
        Route::get('/search/students', [HostelResidencyController::class, 'searchStudents'])->name('search-students');
        Route::get('/search/beds', [HostelResidencyController::class, 'searchBeds'])->name('search-beds');
        Route::post('/', [HostelResidencyController::class, 'store'])->name('store');
        Route::post('/{hostelResidencyAssignment}/end', [HostelResidencyController::class, 'end'])->name('end');
    });

    // Phase 10E: Inventory (Item/Location directory, quantity stock
    // lifecycle) administrative UI. Capability checks live inside each
    // controller (AuthorizesCapability trait), matching every other
    // module's Inertia controller in this file.
    Route::prefix('app/inventory-items')->name('app.inventory-items.')->group(function (): void {
        Route::get('/', [InventoryItemController::class, 'index'])->name('index');
        Route::get('/create', [InventoryItemController::class, 'create'])->name('create');
        Route::post('/', [InventoryItemController::class, 'store'])->name('store');
        Route::patch('/{inventoryItem}', [InventoryItemController::class, 'update'])->name('update');
    });

    Route::prefix('app/inventory-locations')->name('app.inventory-locations.')->group(function (): void {
        Route::get('/', [InventoryLocationController::class, 'index'])->name('index');
        Route::get('/create', [InventoryLocationController::class, 'create'])->name('create');
        Route::post('/', [InventoryLocationController::class, 'store'])->name('store');
        Route::patch('/{inventoryLocation}', [InventoryLocationController::class, 'update'])->name('update');
    });

    Route::prefix('app/inventory-stock')->name('app.inventory-stock.')->group(function (): void {
        Route::get('/', [InventoryStockController::class, 'index'])->name('index');
        Route::get('/receive', [InventoryStockController::class, 'createReceive'])->name('receive.create');
        Route::post('/receive', [InventoryStockController::class, 'storeReceive'])->name('receive.store');
        Route::get('/issue', [InventoryStockController::class, 'createIssue'])->name('issue.create');
        Route::post('/issue', [InventoryStockController::class, 'storeIssue'])->name('issue.store');
        Route::get('/transfer', [InventoryStockController::class, 'createTransfer'])->name('transfer.create');
        Route::post('/transfer', [InventoryStockController::class, 'storeTransfer'])->name('transfer.store');
        Route::get('/search/items', [InventoryStockController::class, 'searchItems'])->name('search-items');
        Route::get('/search/locations', [InventoryStockController::class, 'searchLocations'])->name('search-locations');
    });

    // Phase 10F: Canteen (Outlet/Item/recipe directory, billing
    // configuration, Order lifecycle) administrative UI. Capability
    // checks live inside each controller (AuthorizesCapability trait),
    // matching every other module's Inertia controller in this file.
    Route::prefix('app/canteen-outlets')->name('app.canteen-outlets.')->group(function (): void {
        Route::get('/', [CanteenOutletController::class, 'index'])->name('index');
        Route::get('/create', [CanteenOutletController::class, 'create'])->name('create');
        // Phase 10F fix: narrow, read-only InventoryLocation lookup
        // gated by `canteen.directory.manage` -- replaces the prior
        // reuse of Inventory's own `inventory.stock.manage`-gated
        // search endpoint (see CanteenOutletController's docblock).
        Route::get('/search/inventory-locations', [CanteenOutletController::class, 'searchInventoryLocations'])->name('search-inventory-locations');
        Route::post('/', [CanteenOutletController::class, 'store'])->name('store');
        Route::patch('/{canteenOutlet}', [CanteenOutletController::class, 'update'])->name('update');
    });

    Route::prefix('app/canteen-items')->name('app.canteen-items.')->group(function (): void {
        Route::get('/', [CanteenItemController::class, 'index'])->name('index');
        Route::get('/create', [CanteenItemController::class, 'create'])->name('create');
        // Phase 10F fix: narrow, read-only InventoryItem lookup gated
        // by `canteen.directory.manage` -- replaces the prior reuse of
        // Inventory's own `inventory.stock.manage`-gated search
        // endpoint (see CanteenItemController's docblock).
        Route::get('/search/inventory-items', [CanteenItemController::class, 'searchInventoryItems'])->name('search-inventory-items');
        Route::post('/', [CanteenItemController::class, 'store'])->name('store');
        Route::get('/{canteenItem}', [CanteenItemController::class, 'show'])->name('show');
        Route::patch('/{canteenItem}', [CanteenItemController::class, 'update'])->name('update');
        Route::post('/{canteenItem}/recipe', [CanteenItemController::class, 'storeRequirement'])->name('recipe.store');
        Route::delete('/{canteenItem}/recipe/{requirement}', [CanteenItemController::class, 'destroyRequirement'])->name('recipe.destroy');
    });

    Route::prefix('app/canteen-settings')->name('app.canteen-settings.')->group(function (): void {
        Route::get('/', [CanteenBillingConfigurationController::class, 'edit'])->name('edit');
        Route::put('/', [CanteenBillingConfigurationController::class, 'update'])->name('update');
    });

    Route::prefix('app/canteen-orders')->name('app.canteen-orders.')->group(function (): void {
        Route::get('/', [CanteenOrderController::class, 'index'])->name('index');
        Route::get('/create', [CanteenOrderController::class, 'create'])->name('create');
        Route::get('/search/students', [CanteenOrderController::class, 'searchStudents'])->name('search-students');
        Route::get('/search/items', [CanteenOrderController::class, 'searchItems'])->name('search-items');
        Route::post('/', [CanteenOrderController::class, 'store'])->name('store');
        Route::get('/{canteenOrder}', [CanteenOrderController::class, 'show'])->name('show');
        Route::post('/{canteenOrder}/fulfill', [CanteenOrderController::class, 'fulfill'])->name('fulfill');
        Route::post('/{canteenOrder}/cancel', [CanteenOrderController::class, 'cancel'])->name('cancel');
    });

    // Phase 0H: Timetable (Period catalogue + weekly schedule).
    // Capability authorization happens both at the controller (every
    // action calls `authorizeCapability()`) AND inside
    // App\Domain\Timetable\Application\{TimetablePeriodService,TimetableScheduleService}
    // themselves -- mirroring every other module's established
    // double-layered pattern.
    Route::prefix('app/timetable-periods')->name('app.timetable-periods.')->group(function (): void {
        Route::get('/', [TimetablePeriodController::class, 'index'])->name('index');
        Route::get('/create', [TimetablePeriodController::class, 'create'])->name('create');
        Route::post('/', [TimetablePeriodController::class, 'store'])->name('store');
        Route::patch('/{timetablePeriod}', [TimetablePeriodController::class, 'update'])->name('update');
    });

    // Phase 0H.2 -- the administrative Student Attendance workflow.
    // Capability checks live in the controller itself (the
    // AuthorizesCapability trait), matching every other App/* Inertia
    // controller in this codebase; the API surface uses route
    // middleware instead, where `idempotent` ordering matters.
    // Phase 0H.3A -- the administrative Syllabus surface. Capability
    // checks live in the controller (the AuthorizesCapability trait),
    // matching every other App/* Inertia controller in this codebase.
    // No delete and no activate/deactivate route: `status` is edited
    // through the ordinary update, exactly like the API.
    Route::prefix('app/syllabus')->name('app.syllabus.')->group(function (): void {
        Route::get('/', [SyllabusUnitController::class, 'index'])->name('index');
        Route::post('/', [SyllabusUnitController::class, 'store'])->name('store');
        Route::patch('/{syllabusUnit}', [SyllabusUnitController::class, 'update'])->name('update');
    });

    // Phase 0H.3B -- the administrative Curriculum Delivery surface.
    // Capability checks live in the controller (the
    // AuthorizesCapability trait), matching every other App/* Inertia
    // controller in this codebase. No delete and no archive route:
    // these rows are historical instructional activity. `status` moves
    // only through the dedicated transition route, never through the
    // ordinary update -- completing/reopening is expected-status
    // compare-and-swap guarded, which a plain PATCH cannot express.
    Route::prefix('app/syllabus-delivery')->name('app.syllabus-delivery.')->group(function (): void {
        Route::get('/', [CurriculumDeliveryController::class, 'index'])->name('index');
        Route::post('/', [CurriculumDeliveryController::class, 'store'])->name('store');
        Route::patch('/{curriculumDelivery}', [CurriculumDeliveryController::class, 'update'])->name('update');
        Route::post('/{curriculumDelivery}/transition', [CurriculumDeliveryController::class, 'transition'])->name('transition');
    });

    // Phase 0L.2-1 -- Analytics (ADR 0040). One read-only report; the
    // `analytics.view` check, the fail-closed cohort policy, tenancy
    // and audit all live in App\Domain\Analytics\Application\AnalyticsReadGate.
    // No export route (not in this checkpoint) and no API.
    Route::get('/app/analytics/curriculum-coverage', [CurriculumCoverageController::class, 'index'])->name('app.analytics.curriculum-coverage');

    // Phase 0L.4 -- Compliance (ADR 0042): the School audit-log review.
    // Read-only; `school.audit.view`, the ledger read contract and the
    // access audit all live in App\Domain\Compliance\Application\AuditLogReviewService.
    // No filters, export or API in this checkpoint.
    Route::get('/app/compliance/audit-log', [AuditLogController::class, 'index'])->name('app.compliance.audit-log');

    // Phase 0L.6 -- Automation (ADR 0043): one catalog rule type, School
    // opt-in, accountable owner. `automation.view`/`automation.manage` are
    // enforced in the Automation Application services. No manual run.
    Route::get('/app/automation', [AutomationController::class, 'index'])->name('app.automation');
    Route::post('/app/automation/rules/{ruleType}/enable', [AutomationController::class, 'enable'])->name('app.automation.rules.enable');
    Route::post('/app/automation/rules/{ruleType}/disable', [AutomationController::class, 'disable'])->name('app.automation.rules.disable');
    Route::post('/app/automation/rules/{ruleType}/take-ownership', [AutomationController::class, 'takeOwnership'])->name('app.automation.rules.take-ownership');

    // Phase 0I.2 -- the administrative Learning Content surface (ADR
    // 0039). Capability checks live in the controller (the
    // AuthorizesCapability trait), matching every other App/* Inertia
    // controller in this codebase. No delete route: status-based
    // retirement only. `status` moves only through the dedicated
    // publish/archive routes, never through the ordinary update --
    // mirroring the syllabus-delivery group's own transition-route
    // split above.
    Route::prefix('app/learning-content')->name('app.learning-content.')->group(function (): void {
        Route::get('/', [LmsLearningContentController::class, 'index'])->name('index');
        Route::post('/', [LmsLearningContentController::class, 'store'])->name('store');
        Route::patch('/{learningContent}', [LmsLearningContentController::class, 'update'])->name('update');
        Route::post('/{learningContent}/publish', [LmsLearningContentController::class, 'publish'])->name('publish');
        Route::post('/{learningContent}/archive', [LmsLearningContentController::class, 'archive'])->name('archive');
    });

    // Phase 0I.3 -- the administrative Assignment surface (ADR 0039),
    // structurally identical to the Learning Content group above. No
    // delete route: status-based retirement only. `status` moves only
    // through the dedicated publish/close routes, never through the
    // ordinary update.
    Route::prefix('app/assignments')->name('app.assignments.')->group(function (): void {
        Route::get('/', [LmsAssignmentController::class, 'index'])->name('index');
        Route::post('/', [LmsAssignmentController::class, 'store'])->name('store');
        Route::patch('/{assignment}', [LmsAssignmentController::class, 'update'])->name('update');
        Route::post('/{assignment}/publish', [LmsAssignmentController::class, 'publish'])->name('publish');
        Route::post('/{assignment}/close', [LmsAssignmentController::class, 'close'])->name('close');
    });

    // Phase 0H.4A -- the administrative Examination surface. Capability
    // checks live in the controller (the AuthorizesCapability trait),
    // matching every other App/* Inertia controller in this codebase.
    // Exactly three routes: no delete, no activate/deactivate, and no
    // paper/marks/grade-scale/result surface -- `status` is edited
    // through the ordinary update, exactly like the API.
    Route::prefix('app/examinations')->name('app.examinations.')->group(function (): void {
        Route::get('/', [ExaminationController::class, 'index'])->name('index');
        Route::post('/', [ExaminationController::class, 'store'])->name('store');
        Route::patch('/{examination}', [ExaminationController::class, 'update'])->name('update');
    });

    // Phase 0H.4B (ExaminationPaper / Scheduling) -- a drill-down from
    // one Examination. No paper/marks/grade-scale/result surface beyond
    // scheduling -- `status` is edited through the ordinary update,
    // exactly like the Examination page and the API.
    Route::prefix('app/examinations/{examination}/papers')->name('app.examinations.papers.')->group(function (): void {
        Route::get('/', [ExaminationPaperController::class, 'index'])->name('index');
        Route::post('/', [ExaminationPaperController::class, 'store'])->name('store');
        Route::patch('/{examinationPaper}', [ExaminationPaperController::class, 'update'])->name('update');
    });

    // Phase 0H.4C (GradeScale) -- School-only, independent of the
    // Examination chain; no AcademicYear/Examination context of any
    // kind, unlike every other Examinations admin page. `status` is
    // edited through the ordinary update -- there is no dedicated
    // activate/deactivate/reactivate route; the service interprets it
    // as a guarded lifecycle transition.
    Route::prefix('app/examinations/grade-scales')->name('app.examinations.grade-scales.')->group(function (): void {
        Route::get('/', [GradeScaleController::class, 'index'])->name('index');
        Route::post('/', [GradeScaleController::class, 'store'])->name('store');
        Route::patch('/{gradeScale}', [GradeScaleController::class, 'update'])->name('update');
        Route::post('/{gradeScale}/bands', [GradeScaleController::class, 'storeBand'])->name('bands.store');
        Route::patch('/{gradeScale}/bands/{gradeBand}', [GradeScaleController::class, 'updateBand'])->name('bands.update');
        Route::delete('/{gradeScale}/bands/{gradeBand}', [GradeScaleController::class, 'destroyBand'])->name('bands.destroy');
    });

    Route::prefix('app/attendance')->name('app.attendance.')->group(function (): void {
        Route::get('/', [AttendanceController::class, 'index'])->name('index');
        Route::get('/take', [AttendanceController::class, 'take'])->name('take');
        Route::get('/roster', [AttendanceController::class, 'roster'])->name('roster');
        Route::post('/', [AttendanceController::class, 'store'])->name('store');
        Route::get('/{attendanceSession}', [AttendanceController::class, 'show'])->name('show');
        Route::post('/records/{attendanceRecord}/correct', [AttendanceController::class, 'correct'])->name('correct');
    });

    Route::prefix('app/timetable-schedule')->name('app.timetable-schedule.')->group(function (): void {
        Route::get('/', [TimetableEntryController::class, 'index'])->name('index');
        Route::get('/create', [TimetableEntryController::class, 'create'])->name('create');
        Route::get('/search/subject-offerings', [TimetableEntryController::class, 'searchSubjectOfferings'])->name('search-subject-offerings');
        Route::get('/search/sections', [TimetableEntryController::class, 'searchSections'])->name('search-sections');
        Route::get('/search/teachers', [TimetableEntryController::class, 'searchTeachers'])->name('search-teachers');
        Route::get('/search/rooms', [TimetableEntryController::class, 'searchRooms'])->name('search-rooms');
        Route::get('/search/periods', [TimetableEntryController::class, 'searchPeriods'])->name('search-periods');
        Route::post('/', [TimetableEntryController::class, 'store'])->name('store');
        Route::post('/{timetableEntry}/activate', [TimetableEntryController::class, 'activate'])->name('activate');
        Route::post('/{timetableEntry}/deactivate', [TimetableEntryController::class, 'deactivate'])->name('deactivate');
    });

    // Phase 9.9: Payroll administrative UI. Capability checks live
    // inside each controller (AuthorizesCapability trait, or the
    // underlying Application-layer service's own `authorizeCapabilityFor()`
    // -- see each controller's docblock), matching every other module's
    // Inertia controller in this file -- no route-level `capability:`
    // middleware for these session-authenticated pages (that pattern is
    // reserved for the Bearer-token /api/v1 JSON API). Every mutation
    // delegates to the SAME Application-layer services the JSON API
    // controllers use (routes/api.php's own Payroll block).
    //
    // Phase 9.9 finding (documented per CLAUDE.md rule 15 -- an
    // intentional deviation, not a silent gap): the five consequential
    // commands (create run, create correction, approve, post, reverse)
    // deliberately do NOT carry the `idempotent` route middleware here,
    // unlike their API counterparts. `App\Http\Middleware\EnsureIdempotent::replay()`
    // unconditionally returns `response()->json($body, $status)`, and
    // `IdempotencyGuard`'s response capture only ever stores a
    // `Content-Type` header (`ALLOWED_RESPONSE_HEADERS = ['Content-Type']`)
    // and a `json_decode()`'d body -- both built exclusively for the
    // JSON API's `JsonResponse` shape. A `RedirectResponse`'s `Location`
    // header is never captured and its HTML redirect-stub body doesn't
    // decode as JSON, so a replayed retry of one of these actions would
    // come back as a target-less JSON 302 the browser/Inertia client
    // cannot follow -- a genuine defect, not a theoretical one. Reusing
    // the middleware as-is here would silently ship that bug; redesigning
    // it to also support redirect/Inertia responses is a change to an
    // already-reviewed, security-relevant primitive that deserves its
    // own dedicated review, not a rushed addition to this UI checkpoint.
    // Real protection today is: (1) the structural at-most-once
    // guarantees these actions already have regardless of transport (row
    // locks, unique constraints, status-transition checks -- unchanged
    // by this checkpoint), and (2) the standard disabled-button/
    // `processing` double-submit guard every consequential action's Vue
    // component uses, matching this codebase's own established
    // convention (see e.g. `Finance/Charges/Show.vue`'s `cancelling`
    // guard). The Vue components for these five actions also generate
    // and send a stable `Idempotency-Key` header (`resources/js/idempotency.ts`)
    // reused across a retry of the same logical submission -- inert on
    // this transport today, but forward-compatible groundwork should the
    // middleware gain redirect-response support later.
    Route::prefix('app/payroll')->name('app.payroll.')->group(function (): void {
        Route::get('/', [PayrollController::class, 'index'])->name('index');

        Route::get('/components', [PayrollSalaryComponentController::class, 'index'])->name('components.index');
        Route::post('/components', [PayrollSalaryComponentController::class, 'store'])->name('components.store');
        Route::post('/components/{salaryComponent}/deactivate', [PayrollSalaryComponentController::class, 'deactivate'])->name('components.deactivate');

        // 'create' registered BEFORE the '/{salaryStructure}' wildcard
        // below, matching this file's own established convention.
        Route::get('/structures/create', [PayrollSalaryStructureController::class, 'create'])->name('structures.create');
        Route::get('/structures', [PayrollSalaryStructureController::class, 'index'])->name('structures.index');
        Route::post('/structures', [PayrollSalaryStructureController::class, 'store'])->name('structures.store');
        Route::get('/structures/{salaryStructure}', [PayrollSalaryStructureController::class, 'show'])->name('structures.show');
        Route::post('/structures/{salaryStructure}/components', [PayrollSalaryStructureController::class, 'storeComponent'])->name('structures.components.store');
        Route::post('/structures/{salaryStructure}/activate', [PayrollSalaryStructureController::class, 'activate'])->name('structures.activate');

        Route::get('/periods', [PayrollPeriodController::class, 'index'])->name('periods.index');
        Route::post('/periods', [PayrollPeriodController::class, 'store'])->name('periods.store');
        Route::post('/periods/{payrollPeriod}/open', [PayrollPeriodController::class, 'open'])->name('periods.open');
        Route::post('/periods/{payrollPeriod}/close', [PayrollPeriodController::class, 'close'])->name('periods.close');
        Route::post('/periods/{payrollPeriod}/runs', [PayrollRunController::class, 'store'])->name('periods.runs.store');

        Route::get('/runs/{payrollRun}', [PayrollRunController::class, 'show'])->name('runs.show');
        Route::post('/runs/{payrollRun}/calculate', [PayrollRunController::class, 'calculate'])->name('runs.calculate');
        Route::post('/runs/{payrollRun}/manual-overrides', [PayrollRunController::class, 'manualOverride'])->name('runs.manual-overrides.store');
        Route::post('/runs/{payrollRun}/correction-deltas', [PayrollRunController::class, 'correctionDelta'])->name('runs.correction-deltas.store');
        Route::post('/runs/{payrollRun}/correction', [PayrollRunController::class, 'storeCorrection'])->name('runs.correction.store');
        Route::post('/runs/{payrollRun}/approve', [PayrollRunController::class, 'approve'])->name('runs.approve');
        Route::post('/runs/{payrollRun}/post', [PayrollRunPostingController::class, 'post'])->name('runs.post');
        Route::post('/runs/{payrollRun}/reverse', [PayrollRunPostingController::class, 'reverse'])->name('runs.reverse');

        // Phase 9.10: printable on-demand payslip, never persisted --
        // authorization/eligibility both enforced inside PayslipReadService.
        Route::get('/runs/{payrollRun}/payslips/{employmentRecord}', [PayrollPayslipController::class, 'show'])->name('runs.payslips.show');

        // 'search' registered BEFORE the '/{employmentRecord}' wildcard
        // below, matching this file's own established convention.
        Route::get('/compensation', [PayrollCompensationController::class, 'index'])->name('compensation.index');
        Route::get('/compensation/search', [PayrollCompensationController::class, 'search'])->name('compensation.search');
        Route::get('/compensation/{employmentRecord}', [PayrollCompensationController::class, 'show'])->name('compensation.show');
        Route::post('/compensation/{employmentRecord}', [PayrollCompensationController::class, 'store'])->name('compensation.store');
        Route::get('/compensation-assignments/{compensationAssignment}/values', [PayrollCompensationController::class, 'values'])->name('compensation.values');

        Route::get('/accounting', [PayrollAccountingConfigurationController::class, 'show'])->name('accounting.show');
        Route::post('/accounting', [PayrollAccountingConfigurationController::class, 'update'])->name('accounting.update');

        // Checkpoint 9.6I -- Statutory Payroll administrative UI. Same
        // capability-checks-live-inside-each-controller convention as
        // the rest of this file (no route-level `capability:`
        // middleware here either).
        Route::prefix('statutory')->name('statutory.')->group(function (): void {
            Route::get('/', [StatutoryPayrollController::class, 'index'])->name('index');

            Route::get('/employees/search', [StatutoryEmployeeController::class, 'search'])->name('employees.search');
            Route::get('/employees/{employmentRecord}', [StatutoryEmployeeController::class, 'show'])->name('employees.show');
            Route::get('/employees/{employmentRecord}/identifiers/{identifierType}/reveal', [StatutoryEmployeeController::class, 'revealIdentifier'])->name('employees.identifiers.reveal');
            Route::post('/employees/{employmentRecord}/pf-status', [StatutoryEmployeeController::class, 'storePfStatus'])->name('employees.pf-status.store');
            Route::post('/employees/{employmentRecord}/esi-coverage', [StatutoryEmployeeController::class, 'storeEsiCoverage'])->name('employees.esi-coverage.store');
            Route::post('/employees/{employmentRecord}/tax-profile', [StatutoryEmployeeController::class, 'storeTaxProfile'])->name('employees.tax-profile.store');
            Route::post('/employees/{employmentRecord}/identifiers', [StatutoryEmployeeController::class, 'storeIdentifier'])->name('employees.identifiers.store');

            Route::get('/accounting', [StatutoryAccountingConfigurationController::class, 'show'])->name('accounting.show');
            Route::post('/accounting', [StatutoryAccountingConfigurationController::class, 'update'])->name('accounting.update');

            Route::get('/runs/{payrollRun}/exports', [StatutoryPayrollExportController::class, 'show'])->name('runs.exports.show');
            Route::get('/runs/{payrollRun}/exports/ecr', [StatutoryPayrollExportController::class, 'ecr'])->name('runs.exports.ecr');
            Route::get('/runs/{payrollRun}/exports/esi-worksheet', [StatutoryPayrollExportController::class, 'esiWorksheet'])->name('runs.exports.esi-worksheet');
            Route::get('/runs/{payrollRun}/exports/tds-draft-statement', [StatutoryPayrollExportController::class, 'tdsDraftStatement'])->name('runs.exports.tds-draft-statement');
        });
    });
});
