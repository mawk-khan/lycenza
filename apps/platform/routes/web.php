<?php

use App\Domain\Communications\Http\Controllers\AnnouncementController;
use App\Domain\Communications\Http\Controllers\CommunicationAnalyticsController;
use App\Domain\Communications\Http\Controllers\CommunicationApprovalController;
use App\Domain\Communications\Http\Controllers\CommunicationApprovalPolicyController;
use App\Domain\Communications\Http\Controllers\CommunicationAttachmentController;
use App\Domain\Communications\Http\Controllers\CommunicationAudienceSearchController;
use App\Domain\Communications\Http\Controllers\CommunicationAuditController;
use App\Domain\Communications\Http\Controllers\CommunicationCalendarController;
use App\Domain\Communications\Http\Controllers\CommunicationChannelPolicyController;
use App\Domain\Communications\Http\Controllers\CommunicationConversationPolicyController;
use App\Domain\Communications\Http\Controllers\CommunicationDeliveryTimingPolicyController;
use App\Domain\Communications\Http\Controllers\CommunicationEventController;
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
use App\Http\Controllers\App\Attendance\AttendanceExceptionController;
use App\Http\Controllers\App\Attendance\AttendanceOperationsController;
use App\Http\Controllers\App\Attendance\MyAttendanceController;
use App\Http\Controllers\App\Automation\AutomationController;
use App\Http\Controllers\App\Canteen\CanteenBillingConfigurationController;
use App\Http\Controllers\App\Canteen\CanteenItemController;
use App\Http\Controllers\App\Canteen\CanteenOrderController;
use App\Http\Controllers\App\Canteen\CanteenOutletController;
use App\Http\Controllers\App\ClosedYearPlacementController;
use App\Http\Controllers\App\Compliance\AuditLogController;
use App\Http\Controllers\App\CurriculumDelivery\CurriculumDeliveryController;
use App\Http\Controllers\App\CurriculumDelivery\MyCurriculumDeliveryController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\DataHealthController;
use App\Http\Controllers\App\Domains\SchoolDomainController;
use App\Http\Controllers\App\ElectiveGroupController;
use App\Http\Controllers\App\EnrollmentDirectoryController;
use App\Http\Controllers\App\EnrollmentRolloverBulkDecisionController;
use App\Http\Controllers\App\EnrollmentRolloverController;
use App\Http\Controllers\App\EnrollmentRolloverItemController;
use App\Http\Controllers\App\EnrollmentRolloverMappingController;
use App\Http\Controllers\App\EnrollmentRolloverSubjectMappingController;
use App\Http\Controllers\App\Examinations\ExaminationController;
use App\Http\Controllers\App\Examinations\ExaminationPaperController;
use App\Http\Controllers\App\Examinations\GradeScaleController;
use App\Http\Controllers\App\Examinations\StudentMarkController;
use App\Http\Controllers\App\Examinations\StudentMarkCorrectionController;
use App\Http\Controllers\App\Examinations\TeacherStudentMarkController;
use App\Http\Controllers\App\Fees\LibraryFineWebController;
use App\Http\Controllers\App\Fees\OperationalFeeController;
use App\Http\Controllers\App\Finance\ChargeController as FinanceChargeController;
use App\Http\Controllers\App\Finance\ChargeHistoryController as FinanceChargeHistoryController;
use App\Http\Controllers\App\Finance\FeeAssessmentRunController as FinanceFeeAssessmentRunController;
use App\Http\Controllers\App\Finance\FeeConcessionController as FinanceFeeConcessionController;
use App\Http\Controllers\App\Finance\FeeSetupController as FinanceFeeSetupController;
use App\Http\Controllers\App\Finance\FeeStatementController as FinanceFeeStatementController;
use App\Http\Controllers\App\Finance\FinanceController;
use App\Http\Controllers\App\Finance\FinanceDebtorController;
use App\Http\Controllers\App\Finance\FinancialPeriodController as FinanceFinancialPeriodController;
use App\Http\Controllers\App\Finance\JournalEntryController as FinanceJournalEntryController;
use App\Http\Controllers\App\Finance\LateFeeController as FinanceLateFeeController;
use App\Http\Controllers\App\Finance\LedgerAccountController as FinanceLedgerAccountController;
use App\Http\Controllers\App\Finance\ManualPaymentController as FinanceManualPaymentController;
use App\Http\Controllers\App\Finance\OutstandingFeesController as FinanceOutstandingFeesController;
use App\Http\Controllers\App\Finance\PaymentCorrectionRequestController as FinancePaymentCorrectionRequestController;
use App\Http\Controllers\App\Finance\PaymentRecordController as FinancePaymentRecordController;
use App\Http\Controllers\App\Groups\GroupReportController;
use App\Http\Controllers\App\Groups\NetworkGovernanceController;
use App\Http\Controllers\App\Groups\NetworkReportController;
use App\Http\Controllers\App\Groups\SchoolGroupController;
use App\Http\Controllers\App\GuardianAccountInvitationController;
use App\Http\Controllers\App\GuardianAccountLinkController;
use App\Http\Controllers\App\GuardianCommunicationPreferenceController;
use App\Http\Controllers\App\GuardianController;
use App\Http\Controllers\App\GuardianPortalOffboardingController;
use App\Http\Controllers\App\HostelController;
use App\Http\Controllers\App\HostelResidencyController;
use App\Http\Controllers\App\HostelRoomController;
use App\Http\Controllers\App\HR\HrController;
use App\Http\Controllers\App\HR\HrDepartmentController;
use App\Http\Controllers\App\HR\HrEmployeeAccountLinkController;
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
use App\Http\Controllers\App\IntegrationHubController;
use App\Http\Controllers\App\Integrations\ApiClientController;
use App\Http\Controllers\App\InventoryItemController;
use App\Http\Controllers\App\InventoryLocationController;
use App\Http\Controllers\App\InventoryStockController;
use App\Http\Controllers\App\Leave\LeaveAdminController;
use App\Http\Controllers\App\Leave\LeaveApprovalsController;
use App\Http\Controllers\App\LibraryCatalogueController;
use App\Http\Controllers\App\LibraryCirculationController;
use App\Http\Controllers\App\LMS\AssignmentController as LmsAssignmentController;
use App\Http\Controllers\App\LMS\LearningContentController as LmsLearningContentController;
use App\Http\Controllers\App\LMS\MyAssignmentController;
use App\Http\Controllers\App\LMS\MyLearningContentController;
use App\Http\Controllers\App\OrganizationReportController;
use App\Http\Controllers\App\OrganizationSetupController;
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
use App\Http\Controllers\App\Platform\PlatformSchoolModuleController;
use App\Http\Controllers\App\Platform\SchoolElevationController;
use App\Http\Controllers\App\Platform\SchoolGroupAdminController;
use App\Http\Controllers\App\Portal\GuardianAttendanceController;
use App\Http\Controllers\App\Portal\GuardianCalendarController;
use App\Http\Controllers\App\Portal\GuardianCommunicationController;
use App\Http\Controllers\App\Portal\GuardianConversationController;
use App\Http\Controllers\App\Portal\GuardianFeeController;
use App\Http\Controllers\App\SchoolPresentationController;
use App\Http\Controllers\App\SchoolSettingsController;
use App\Http\Controllers\App\SchoolSetupController;
use App\Http\Controllers\App\SchoolSetupStructureController;
use App\Http\Controllers\App\SchoolSwitchController;
use App\Http\Controllers\App\SelfService\StaffSelfServiceController;
use App\Http\Controllers\App\Staff\AccessCenterController;
use App\Http\Controllers\App\Staff\StaffAccountController;
use App\Http\Controllers\App\StaffAttendance\StaffAttendanceAdminController;
use App\Http\Controllers\App\StudentAccountLinkController;
use App\Http\Controllers\App\StudentController;
use App\Http\Controllers\App\StudentDirectoryController;
use App\Http\Controllers\App\StudentEnrollmentController;
use App\Http\Controllers\App\StudentGuardianRelationshipController;
use App\Http\Controllers\App\StudentMovementController;
use App\Http\Controllers\App\StudentProcessingAuthorizationController;
use App\Http\Controllers\App\StudentSubjectEnrollmentController;
use App\Http\Controllers\App\StudentTransferController;
use App\Http\Controllers\App\SubjectOfferingAdminController;
use App\Http\Controllers\App\SubjectOfferingController;
use App\Http\Controllers\App\Syllabus\SyllabusUnitController;
use App\Http\Controllers\App\TeachingAssignments\ElectiveTeachingAssignmentController;
use App\Http\Controllers\App\TeachingAssignments\TeachingAssignmentController;
use App\Http\Controllers\App\Timetable\TimetableEntryController;
use App\Http\Controllers\App\Timetable\TimetablePeriodController;
use App\Http\Controllers\App\TransportOperationsController;
use App\Http\Controllers\App\TransportRiderImportController;
use App\Http\Controllers\App\TransportRouteController;
use App\Http\Controllers\App\TransportStudentAssignmentController;
use App\Http\Controllers\App\TransportVehicleController;
use App\Http\Controllers\App\VisitorController;
use App\Http\Controllers\App\VisitorVisitController;
use App\Http\Controllers\Auth\AccountActivationController;
use App\Http\Controllers\Auth\AccountRecoveryController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MfaChallengeController;
use App\Http\Controllers\Auth\SessionHandoffController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Identity\InvitationAcceptanceController;
use App\Http\Controllers\Identity\StaffInvitationAcceptanceController;
use App\Http\Controllers\Internal\MfaDemoController;
use App\Support\Routing\WebRouteParameters;
use Illuminate\Support\Facades\Route;

// SWC.1 (ADR 0073 section 7.1): the public product entry; a signed-in
// visitor is sent to /app.
Route::get('/', [HomeController::class, 'index'])->name('home');

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

    // Phase 0O.12B (ADR 0059 section 5): a bootstrap account's one-time
    // activation -- the same platform-host-only shape as recovery (the
    // secret is the link's #fragment; only the POST consumes).
    Route::get('/account-activation/{selector}', [AccountActivationController::class, 'edit'])
        ->where('selector', '[A-Za-z0-9_-]{22}')->name('account-activation.edit');
    Route::post('/account-activation/{selector}', [AccountActivationController::class, 'update'])
        ->where('selector', '[A-Za-z0-9_-]{22}')
        ->middleware('throttle:account-activation')->name('account-activation.update');
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

// Phase 0O.12B (ADR 0059 section 6.3): accepting a STAFF account invitation
// -- its own route pair under the same `invitations/{school}/` School-host
// surface. The secret is the link's #fragment (never the path); only the
// CSRF-protected POST consumes. no-store + no-referrer; the POST is throttled
// per IP and per selector.
Route::middleware(['private-no-store', 'no-referrer'])->group(function (): void {
    Route::get('/invitations/{school}/staff/{selector}', [StaffInvitationAcceptanceController::class, 'show'])
        ->whereUuid('school')->where('selector', '[A-Za-z0-9_-]{22}')->name('staff-invitations.show');
    Route::post('/invitations/{school}/staff/{selector}', [StaffInvitationAcceptanceController::class, 'store'])
        ->whereUuid('school')->where('selector', '[A-Za-z0-9_-]{22}')
        ->middleware('throttle:staff-invitation-accept')->name('staff-invitations.store');
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
Route::middleware('auth')->where(WebRouteParameters::uuidPatterns())->group(function (): void {
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
            // ORG.12D (ADR 0080 §9): membership classification (fresh MFA) and
            // placement, and the Group's Regions and Zones -- all authorized by
            // platform.school_groups.manage in the services.
            Route::put('/{schoolGroup}/schools/{school}/classification', [SchoolGroupAdminController::class, 'classify'])->name('schools.classify');
            Route::put('/{schoolGroup}/schools/{school}/placement', [SchoolGroupAdminController::class, 'place'])->name('schools.place');
            Route::post('/{schoolGroup}/regions', [SchoolGroupAdminController::class, 'storeRegion'])->name('regions.store');
            Route::put('/{schoolGroup}/regions/{region}', [SchoolGroupAdminController::class, 'updateRegion'])->name('regions.update');
            Route::post('/{schoolGroup}/zones', [SchoolGroupAdminController::class, 'storeZone'])->name('zones.store');
            Route::put('/{schoolGroup}/zones/{zone}', [SchoolGroupAdminController::class, 'updateZone'])->name('zones.update');
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
    // `provisioning`, activate, suspend, resume, and (E21.2F, E21-D11) close
    // and reopen. Context-neutral; no archive or delete route exists.
    // ORG.3 (ADR 0076 §9.2): the registry list and overview need
    // platform.schools.view. ORG.4 (§7.2): the onboarding pages (create, and
    // the bootstrap administrator while provisioning) need
    // platform.schools.onboard (or .manage); every lifecycle review page needs
    // platform.schools.manage. The POSTs carry no capability middleware on
    // purpose so a refusal reaches the service, which applies the same
    // per-operation rule (SchoolLifecycleAuthority::capabilitiesFor) and
    // audits it (platform.school.lifecycle_denied).
    Route::prefix('app/platform/schools')->name('app.platform.schools.')->group(function (): void {
        Route::middleware('capability:platform.schools.view,platform')->group(function (): void {
            Route::get('/', [PlatformSchoolAdminController::class, 'index'])->name('index');
            Route::get('/{school}', [PlatformSchoolAdminController::class, 'show'])->whereUuid('school')->name('show');
        });
        Route::middleware('capability:platform.schools.onboard|platform.schools.manage,platform')->group(function (): void {
            Route::get('/create', [PlatformSchoolAdminController::class, 'create'])->name('create');
            Route::get('/{school}/bootstrap-admin', [PlatformSchoolAdminController::class, 'review'])
                ->whereUuid('school')->defaults('action', 'bootstrap-admin')->name('bootstrap-admin.review');
        });
        Route::middleware('capability:platform.schools.manage,platform')->group(function (): void {
            Route::get('/{school}/{action}', [PlatformSchoolAdminController::class, 'review'])
                ->whereUuid('school')->whereIn('action', ['activate', 'suspend', 'resume', 'close', 'reopen'])->name('review');
        });
        Route::post('/', [PlatformSchoolAdminController::class, 'store'])
            ->middleware('throttle:platform-school-lifecycle')->name('store');
        Route::post('/{school}/{action}', [PlatformSchoolAdminController::class, 'perform'])
            ->whereUuid('school')->whereIn('action', ['activate', 'suspend', 'resume', 'bootstrap-admin', 'close', 'reopen'])
            ->middleware('throttle:platform-school-lifecycle')->name('perform');

        // ORG.5 (ADR 0076 §31): one optional module for one School. The
        // review page needs platform.school_entitlements.manage; the POST
        // carries no capability middleware so the service authorizes and
        // audits a refusal (platform.school_entitlement.denied).
        Route::get('/{school}/modules/{module}/{action}', [PlatformSchoolModuleController::class, 'review'])
            ->whereUuid('school')->where('module', '[a-z_]{1,64}')->whereIn('action', ['enable', 'disable'])
            ->middleware('capability:platform.school_entitlements.manage,platform')->name('modules.review');
        Route::post('/{school}/modules/{module}/{action}', [PlatformSchoolModuleController::class, 'perform'])
            ->whereUuid('school')->where('module', '[a-z_]{1,64}')->whereIn('action', ['enable', 'disable'])
            ->middleware('throttle:platform-school-lifecycle')->name('modules.perform');
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
        // ORG.12H (ADR 0080 §13.3): the Network MIS -- platform tables only,
        // scoped by GroupGovernanceAccess (whole Group, Region or Zone).
        Route::get('/{schoolGroup}/reports/network', [NetworkReportController::class, 'index'])
            ->name('reports.network');
        // ORG.12E (ADR 0080 §10): Network governance in Group context -- the
        // Network Access page, read-only operator administration (fresh MFA)
        // and a whole-Group Network Manager's structure and membership
        // governance. Authorized per request by GroupGovernanceAccess and the
        // services; never School context.
        Route::get('/{schoolGroup}/access', [NetworkGovernanceController::class, 'access'])->name('access');
        // ORG.12F (ADR 0080 §11.7): the Network brand -- fresh MFA, reason, version.
        Route::put('/{schoolGroup}/network/brand', [NetworkGovernanceController::class, 'updateBrand'])->name('network.brand.update');
        Route::post('/{schoolGroup}/access/operators', [NetworkGovernanceController::class, 'grantOperator'])->name('access.operators.store');
        Route::post('/{schoolGroup}/access/operators/{grant}/revoke', [NetworkGovernanceController::class, 'revokeOperator'])->name('access.operators.revoke');
        Route::put('/{schoolGroup}/network/schools/{school}/classification', [NetworkGovernanceController::class, 'classify'])->name('network.schools.classify');
        Route::put('/{schoolGroup}/network/schools/{school}/placement', [NetworkGovernanceController::class, 'place'])->name('network.schools.place');
        Route::delete('/{schoolGroup}/network/schools/{school}', [NetworkGovernanceController::class, 'removeSchool'])->name('network.schools.destroy');
        Route::post('/{schoolGroup}/network/regions', [NetworkGovernanceController::class, 'storeRegion'])->name('network.regions.store');
        Route::put('/{schoolGroup}/network/regions/{region}', [NetworkGovernanceController::class, 'updateRegion'])->name('network.regions.update');
        Route::post('/{schoolGroup}/network/zones', [NetworkGovernanceController::class, 'storeZone'])->name('network.zones.store');
        Route::put('/{schoolGroup}/network/zones/{zone}', [NetworkGovernanceController::class, 'updateZone'])->name('network.zones.update');
    });
});

// Every School-scoped web route (Phase 0N.1, D10(a)). `school-context`
// (App\Http\Middleware\RequireSchoolContext) requires a valid selected
// School -- active School, active membership, not a disabled account --
// before route model binding, capability checks or the controller run:
// a page request without one goes back to the /app landing, a mutation
// or JSON request gets a 409 `school_context_required`. It never selects
// a School. Controllers here may rely on TenantContext::requireSchool().
// LC-1: a malformed record id in either signed-in group is a plain 404
// (WebRouteParameters), never a database error.
Route::middleware(['auth', 'school-context'])->where(WebRouteParameters::uuidPatterns())->group(function (): void {
    Route::get('/app/settings', [SchoolSettingsController::class, 'show'])
        ->middleware('capability:school.settings.view')
        ->name('app.settings.show');

    // Phase 0O.8A (ADR 0054 section 10): the School's custom domains. View:
    // school.domains.view; add/regenerate/primary/remove: .manage + a fresh
    // MFA code; check now: .manage, per-School/per-domain limits, queued.
    // All checked in SchoolDomainController and SchoolDomainService.
    // Phase 0O.12B (ADR 0059 section 23, owner amendment): Settings -> Staff
    // accounts -- invite, resend/revoke an invitation, off-board (suspend),
    // reactivate, grant/revoke a role. View: school.members.view; every
    // mutation: its capabilities + a fresh MFA code (StaffAccountController),
    // re-checked in the Staff services with the last-administrator invariant.
    Route::prefix('app/settings/staff')->name('app.settings.staff.')->group(function (): void {
        Route::get('/', [StaffAccountController::class, 'index'])->name('index');
        Route::middleware('throttle:staff-account-management')->group(function (): void {
            Route::post('/invitations', [StaffAccountController::class, 'invite'])->name('invitations.store');
            Route::post('/invitations/{invitation}/resend', [StaffAccountController::class, 'resendInvitation'])
                ->whereUuid('invitation')->name('invitations.resend');
            Route::post('/invitations/{invitation}/revoke', [StaffAccountController::class, 'revokeInvitation'])
                ->whereUuid('invitation')->name('invitations.revoke');
            Route::post('/members/{membership}/suspend', [StaffAccountController::class, 'suspend'])
                ->whereUuid('membership')->name('members.suspend');
            Route::post('/members/{membership}/reactivate', [StaffAccountController::class, 'reactivate'])
                ->whereUuid('membership')->name('members.reactivate');
            Route::post('/members/{membership}/roles', [StaffAccountController::class, 'grantRole'])
                ->whereUuid('membership')->name('members.roles.store');
            Route::post('/members/{membership}/roles/{role}/revoke', [StaffAccountController::class, 'revokeRole'])
                ->whereUuid('membership')->where('role', '[a-z0-9_-]{1,100}')->name('members.roles.revoke');
        });
    });

    // ORG.12E (ADR 0080 §10.7): the read-only School Access Center -- role
    // templates, capabilities, the role matrix, the sensitive-data catalogue,
    // assignments and one person's effective access. Mutations stay on Staff
    // accounts.
    Route::prefix('app/settings/access')->name('app.settings.access.')->group(function (): void {
        Route::get('/', [AccessCenterController::class, 'index'])->middleware('capability:school.members.view|school.roles.view')->name('index');
        Route::get('/users', [AccessCenterController::class, 'users'])->middleware('capability:school.roles.view')->name('users');
        Route::get('/users/{membership}', [AccessCenterController::class, 'show'])->middleware('capability:school.roles.view')->name('users.show');
    });

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
        // ORG.12B (ADR 0080 §7): the Organization Setup & Readiness Center.
        Route::get('/readiness', [OrganizationSetupController::class, 'index'])
            ->middleware('capability:school.profile.view')
            ->name('readiness');
        // ORG.12G (ADR 0080 §12): the Integration & Device Readiness Hub --
        // read-only status of each integration family from its owning module.
        Route::get('/integrations', [IntegrationHubController::class, 'index'])
            ->middleware('capability:school.settings.view')
            ->name('integrations');
        // ORG.12H (ADR 0080 §13.2): the School Control Plane report (read-only).
        Route::get('/organization-report', [OrganizationReportController::class, 'index'])
            ->middleware('capability:school.profile.view')
            ->name('organization-report');

        Route::get('/profile', [SchoolSetupController::class, 'profile'])->name('profile');
        Route::put('/profile', [SchoolSetupController::class, 'updateProfile'])->name('profile.update');
        // ORG.12C (ADR 0080 §8): institutional identity -- fresh MFA + closed reason.
        Route::put('/profile/identity', [SchoolSetupController::class, 'updateIdentity'])
            ->middleware('capability:school.profile.manage')
            ->name('profile.identity.update');

        // ORG.12F (ADR 0080 §11.5): presentation profile -- branding and
        // navigation presentation (never permissions).
        Route::get('/presentation', [SchoolPresentationController::class, 'show'])
            ->middleware('capability:school.settings.view')->name('presentation');
        Route::put('/presentation/brand', [SchoolPresentationController::class, 'updateBrand'])
            ->middleware('capability:school.settings.manage')->name('presentation.brand.update');
        Route::put('/presentation/navigation/{audience}', [SchoolPresentationController::class, 'updateNavigation'])
            ->middleware('capability:school.settings.manage')->where('audience', '[a-z0-9_-]{1,64}')->name('presentation.navigation.update');
        Route::delete('/presentation/navigation/{audience}', [SchoolPresentationController::class, 'resetNavigation'])
            ->middleware('capability:school.settings.manage')->where('audience', '[a-z0-9_-]{1,64}')->name('presentation.navigation.reset');

        Route::get('/campuses', [SchoolSetupController::class, 'campuses'])->name('campuses');
        Route::get('/campuses/{campus}', [SchoolSetupController::class, 'showCampus'])->name('campuses.show');
        Route::post('/campuses', [SchoolSetupController::class, 'storeCampus'])->name('campuses.store');
        Route::put('/campuses/{campus}', [SchoolSetupController::class, 'updateCampus'])->name('campuses.update');

        Route::get('/academic-years', [SchoolSetupController::class, 'academicYears'])->name('academic-years');
        Route::post('/academic-years', [SchoolSetupController::class, 'storeAcademicYear'])->name('academic-years.store');
        Route::put('/academic-years/{academicYear}', [SchoolSetupController::class, 'renameAcademicYear'])->name('academic-years.update');
        Route::post('/academic-years/{academicYear}/activate', [SchoolSetupController::class, 'activateAcademicYear'])->name('academic-years.activate');
        Route::post('/academic-years/{academicYear}/close', [SchoolSetupController::class, 'closeAcademicYear'])->name('academic-years.close');
        // SIS 5.1 (MC-3, ADR 0077): date correction of a DRAFT year only.
        Route::get('/academic-years/{academicYear}/dates', [SchoolSetupController::class, 'editAcademicYearDates'])->name('academic-years.dates');
        Route::put('/academic-years/{academicYear}/dates', [SchoolSetupController::class, 'correctAcademicYearDates'])->name('academic-years.dates.update');

        Route::get('/grade-levels', [SchoolSetupController::class, 'gradeLevels'])->name('grade-levels');
        Route::post('/grade-levels', [SchoolSetupController::class, 'storeGradeLevel'])->name('grade-levels.store');
        Route::put('/grade-levels/{gradeLevel}', [SchoolSetupController::class, 'updateGradeLevel'])->name('grade-levels.update');

        Route::get('/subjects', [SchoolSetupController::class, 'subjects'])->name('subjects');
        Route::post('/subjects', [SchoolSetupController::class, 'storeSubject'])->name('subjects.store');
        Route::put('/subjects/{subject}', [SchoolSetupController::class, 'updateSubject'])->name('subjects.update');

        // SWC.2 (ADR 0073 section 10): the screens Phase 0D deferred, over
        // the same Application services as `/api/v1`. Capability checks live
        // in SchoolSetupStructureController (AuthorizesCapability).
        Route::get('/terms', [SchoolSetupStructureController::class, 'terms'])->name('terms');
        Route::post('/academic-years/{academicYear}/terms', [SchoolSetupStructureController::class, 'storeTerm'])->name('terms.store');
        Route::put('/terms/{academicTerm}', [SchoolSetupStructureController::class, 'renameTerm'])->name('terms.update');
        // SIS 5.1: date correction of a Term of a DRAFT year only.
        Route::get('/terms/{academicTerm}/dates', [SchoolSetupStructureController::class, 'editTermDates'])->name('terms.dates');
        Route::put('/terms/{academicTerm}/dates', [SchoolSetupStructureController::class, 'correctTermDates'])->name('terms.dates.update');

        Route::get('/sections', [SchoolSetupStructureController::class, 'sections'])->name('sections');
        Route::post('/academic-years/{academicYear}/sections', [SchoolSetupStructureController::class, 'storeSection'])->name('sections.store');
        Route::put('/sections/{section}', [SchoolSetupStructureController::class, 'updateSection'])->name('sections.update');

        Route::get('/departments', [SchoolSetupStructureController::class, 'departments'])->name('departments');
        Route::post('/departments', [SchoolSetupStructureController::class, 'storeDepartment'])->name('departments.store');
        Route::put('/departments/{academicDepartment}', [SchoolSetupStructureController::class, 'updateDepartment'])->name('departments.update');

        Route::get('/rooms', [SchoolSetupStructureController::class, 'rooms'])->name('rooms');
        Route::post('/campuses/{campus}/rooms', [SchoolSetupStructureController::class, 'storeRoom'])->name('rooms.store');
        Route::put('/rooms/{room}', [SchoolSetupStructureController::class, 'updateRoom'])->name('rooms.update');
    });

    // SWC.2 (ADR 0073 sections 4.3, 16 X1): Elective Groups -- create, and
    // assign/remove an elective Subject Offering of the same context, over
    // ElectiveGroupService. View `academics.subjects.view`; manage
    // `academics.subjects.manage` (checked in ElectiveGroupController).
    Route::prefix('app/elective-groups')->name('app.elective-groups.')->group(function (): void {
        Route::get('/', [ElectiveGroupController::class, 'index'])->name('index');
        Route::post('/', [ElectiveGroupController::class, 'store'])->name('store');
        Route::get('/{electiveGroup}', [ElectiveGroupController::class, 'show'])->name('show');
        Route::post('/{electiveGroup}/offerings', [ElectiveGroupController::class, 'assign'])->name('offerings.store');
        Route::delete('/{electiveGroup}/offerings/{subjectOffering}', [ElectiveGroupController::class, 'remove'])->name('offerings.destroy');
    });

    // Phase 1A.6: Student & Guardian Identity administrative UI.
    // Capability checks live inside each controller (AuthorizesCapability
    // trait), matching SchoolSetupController's pattern -- every action
    // re-derives the active School from TenantContext, never a
    // client-supplied id. See
    // docs/modules/STUDENT-GUARDIAN-IDENTITY.md ("Administrative UI").
    // ORG.7F-1 (ADR 0076 §45): the Directory and the operational overview are
    // Campus-adopted (StudentDirectoryController, through StudentAccess); the
    // full Profile and history carry the ordinary whole-School
    // `capability:students.view` floor (declared whole-School-only
    // operations, CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS).
    Route::prefix('app/students')->name('app.students.')->group(function (): void {
        Route::get('/', [StudentDirectoryController::class, 'index'])->name('index');
        // ORG.7F-2 (ADR 0076 §46): atomic Campus onboarding (students.manage AND
        // enrollments.manage in the destination Campus); the Student number is
        // allocated by the server. Literal path before '/{student}'.
        Route::get('/onboard', [StudentDirectoryController::class, 'onboard'])->name('onboard');
        Route::post('/onboard', [StudentDirectoryController::class, 'storeOnboarding'])->name('onboard.store');
        // SIS.EXP-2: the School-wide movement and exit register (read only,
        // a declared whole-School operation of enrollments.view). Literal
        // path before '/{student}'.
        Route::get('/movements', [StudentMovementController::class, 'index'])->middleware('capability:enrollments.view')->name('movements');
        Route::get('/create', [StudentController::class, 'create'])->middleware('capability:students.manage')->name('create');
        Route::post('/', [StudentController::class, 'store'])->middleware('capability:students.manage')->name('store');
        Route::get('/{student}', [StudentController::class, 'show'])->middleware('capability:students.view')->name('show');
        Route::get('/{student}/overview', [StudentDirectoryController::class, 'overview'])->name('overview');
        // ORG.7F-2: the Campus-aware name edit (first/middle/last name only).
        Route::get('/{student}/names', [StudentDirectoryController::class, 'editNames'])->name('names.edit');
        Route::put('/{student}/names', [StudentDirectoryController::class, 'updateNames'])->name('names.update');
        // ORG.7F-3: Campus transfer of the CURRENT placement (enrollments.manage
        // over the source AND destination Campus).
        Route::get('/{student}/transfer', [StudentTransferController::class, 'create'])->name('transfer.create');
        Route::post('/{student}/transfer', [StudentTransferController::class, 'store'])->name('transfer.store');
        Route::get('/{student}/edit', [StudentController::class, 'edit'])->middleware('capability:students.manage')->name('edit');
        Route::get('/{student}/history', [StudentController::class, 'history'])->middleware('capability:students.view')->name('history');
        Route::put('/{student}', [StudentController::class, 'update'])->middleware('capability:students.manage')->name('update');
        Route::post('/{student}/status', [StudentController::class, 'changeStatus'])->middleware('capability:students.manage')->name('status');

        // "Add guardian" workflow (section 15) -- read-only search/
        // candidate endpoints are plain JSON (fetched live from Vue),
        // not Inertia pages.
        Route::get('/{student}/guardians/add', [StudentGuardianRelationshipController::class, 'create'])->middleware('capability:students.manage')->name('guardians.create');
        Route::get('/{student}/guardians/search', [StudentGuardianRelationshipController::class, 'searchGuardians'])->middleware('capability:students.manage')->name('guardians.search');
        Route::get('/{student}/guardians/candidates', [StudentGuardianRelationshipController::class, 'candidateGuardians'])->middleware('capability:students.manage')->name('guardians.candidates');
        Route::post('/{student}/guardians/link', [StudentGuardianRelationshipController::class, 'linkExisting'])->middleware('capability:students.manage')->name('guardians.link');
        Route::post('/{student}/guardians', [StudentGuardianRelationshipController::class, 'linkNew'])->middleware('capability:students.manage')->name('guardians.store');

        // Phase 5B.2: the optional School OS account link.
        Route::get('/{student}/account-link/search', [StudentAccountLinkController::class, 'search'])->middleware('capability:students.manage')->name('account-link.search');
        Route::post('/{student}/account-link', [StudentAccountLinkController::class, 'store'])->middleware('capability:students.manage')->name('account-link.store');
        Route::delete('/{student}/account-link', [StudentAccountLinkController::class, 'destroy'])->middleware('capability:students.manage')->name('account-link.destroy');

        // Phase 1B.6: Enrollment administrative UI -- a Student's own
        // "enroll into a Section" flow, nested exactly like the
        // guardians.* routes above.
        Route::get('/{student}/enrollments/create', [StudentEnrollmentController::class, 'create'])->middleware('capability:enrollments.manage')->name('enrollments.create');
        Route::post('/{student}/enrollments', [StudentEnrollmentController::class, 'store'])->middleware('capability:enrollments.manage')->name('enrollments.store');

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
        Route::put('/{relationship}', [StudentGuardianRelationshipController::class, 'update'])->middleware('capability:students.manage')->name('update');
        Route::post('/{relationship}/primary', [StudentGuardianRelationshipController::class, 'setPrimary'])->middleware('capability:students.manage')->name('primary');
        Route::delete('/{relationship}', [StudentGuardianRelationshipController::class, 'destroy'])->middleware('capability:students.manage')->name('destroy');
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

        // POR.1 (ADR 0070 §9.2): Guardian off-boarding -- revoke the portal
        // grant and account link; suspend the membership only when it holds no
        // staff role. guardians.manage + school.members.manage + fresh MFA.
        Route::post('/{guardian}/portal-offboard', [GuardianPortalOffboardingController::class, 'store'])
            ->whereUuid('guardian')->name('portal-offboard');

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
        // SIS 2.1 (MC-4): correct an existing contact; reactivate a deactivated one.
        Route::get('/{contact}/edit', [GuardianController::class, 'editContact'])->whereUuid('contact')->name('edit');
        Route::put('/{contact}', [GuardianController::class, 'correctContact'])->whereUuid('contact')->name('update');
        Route::post('/{contact}/reactivate', [GuardianController::class, 'reactivateContact'])->whereUuid('contact')->name('reactivate');
    });

    // Phase 5A.1: the Communication Hub foundation -- session-
    // authenticated Inertia pages against the ambient active School,
    // same convention as SchoolSetupController above. Capability checks
    // live inside CommunicationHubController itself (AuthorizesCapability
    // trait).
    // POR.1 (ADR 0070 §10.1, §17, §18.2): the read-only Guardian portal inbox --
    // web/session only (no API), development only until POR-L1 (E46). Every
    // route: `portal-development-only` FIRST, then the Guardian capability;
    // the controller resolves ActingGuardian fresh; ids are UUID-constrained
    // and anything not this Guardian's is the same 404.
    Route::prefix('app/portal/communications')->name('app.portal.communications.')
        ->middleware(['portal-development-only', 'capability:portal.communications.view'])
        ->group(function (): void {
            Route::get('/', [GuardianCommunicationController::class, 'index'])->name('index');
            Route::get('/announcements/{announcement}', [GuardianCommunicationController::class, 'show'])
                ->whereUuid('announcement')->name('show');
            Route::get('/announcements/{announcement}/attachments/{attachment}/download', [GuardianCommunicationController::class, 'download'])
                ->whereUuid(['announcement', 'attachment'])->name('attachments.download');
        });

    // ORG.9D-3 (ADR 0076 §75): the Guardian's School calendar -- web/session
    // only, development only until E46. `portal-development-only` FIRST, then
    // the Guardian capability; the controller resolves ActingGuardian fresh.
    Route::get('app/portal/calendar', [GuardianCalendarController::class, 'index'])
        ->middleware(['portal-development-only', 'capability:portal.communications.view'])
        ->name('app.portal.calendar.index');

    // POR.2 (ADR 0070 §25): a linked Student's minimized Attendance -- web/session
    // only, development only (POR-L1). `portal-development-only` FIRST, then the
    // Guardian capability, then current MFA assurance (enrolled factor + window);
    // the controller resolves ActingGuardian and the read seam the live
    // GuardianStudentScope. Anything not this Guardian's Student is the same 404.
    Route::prefix('app/portal/attendance')->name('app.portal.attendance.')
        ->middleware(['portal-development-only', 'capability:portal.attendance.view', 'mfa-page'])
        ->group(function (): void {
            Route::get('/', [GuardianAttendanceController::class, 'index'])->name('index');
            Route::get('/students/{student}', [GuardianAttendanceController::class, 'show'])
                ->whereUuid('student')->name('show');
        });

    // POR.3 (ADR 0070 §26): a linked Student's fees -- web/session only, read-only,
    // development only (POR-L1). `portal-development-only` FIRST, then the Guardian
    // capability, then current MFA assurance; the read seam embeds the live
    // GuardianStudentScope. Anything not this Guardian's Student or this Student's
    // Payment is the same 404. No payment, refund, export or PDF route.
    Route::prefix('app/portal/fees')->name('app.portal.fees.')
        ->middleware(['portal-development-only', 'capability:portal.fees.view', 'mfa-page'])
        ->group(function (): void {
            Route::get('/', [GuardianFeeController::class, 'index'])->name('index');
            Route::get('/students/{student}', [GuardianFeeController::class, 'show'])
                ->whereUuid('student')->name('show');
            Route::get('/students/{student}/payments/{payment}', [GuardianFeeController::class, 'payment'])
                ->whereUuid(['student', 'payment'])->name('payment');
        });

    // POR.4 (ADR 0070 §27): a Guardian's existing School conversations and text
    // replies -- web/session only, development only (POR-L1). `portal-development-only`
    // FIRST, then the Guardian capability, then current MFA assurance. A reply also
    // needs `portal.communications.reply` (never instead of `.view`) and its own
    // User-keyed throttle. Anything not this Guardian's is the same 404. No compose,
    // edit, delete, upload or API route.
    Route::prefix('app/portal/conversations')->name('app.portal.conversations.')
        ->middleware(['portal-development-only', 'capability:portal.communications.view', 'mfa-page'])
        ->group(function (): void {
            Route::get('/', [GuardianConversationController::class, 'index'])->name('index');
            Route::get('/{thread}', [GuardianConversationController::class, 'show'])
                ->whereUuid('thread')->name('show');
            Route::post('/{thread}/replies', [GuardianConversationController::class, 'reply'])
                ->whereUuid('thread')
                ->middleware(['capability:portal.communications.reply', 'throttle:guardian-portal-reply'])
                ->name('reply');
            Route::get('/{thread}/attachments/{attachment}/download', [GuardianConversationController::class, 'download'])
                ->whereUuid(['thread', 'attachment'])->name('attachments.download');
        });

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
            // ORG.9D-1R1: a recipient route -- a malformed id is the same 404.
            Route::get('/{announcement}', [AnnouncementController::class, 'show'])->whereUuid('announcement')->name('show');
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

        // ORG.9D-3 (ADR 0076 §75): the School Events Calendar -- registered
        // BEFORE the '/{thread}' wildcard below. Management needs
        // communications.announce (changes: creator or communications.manage,
        // in the controller); decisions stay in the approvals queue. The staff
        // calendar is CONSUMPTION (ORG.9D-3R1, ADR 0076 §76): it needs only
        // communications.events.view -- never communications.view, which opens
        // the Hub and the School's announcement list -- and shows only what the
        // viewer's current audience membership reaches.
        Route::get('/calendar', [CommunicationCalendarController::class, 'index'])->middleware('capability:communications.events.view')->name('calendar');
        Route::prefix('events')->name('events.')->middleware('capability:communications.announce')->group(function (): void {
            Route::get('/', [CommunicationEventController::class, 'index'])->name('index');
            Route::get('/create', [CommunicationEventController::class, 'create'])->name('create');
            Route::post('/', [CommunicationEventController::class, 'store'])->name('store');
            Route::get('/{event}', [CommunicationEventController::class, 'show'])->whereUuid('event')->name('show');
            Route::get('/{event}/edit', [CommunicationEventController::class, 'edit'])->whereUuid('event')->name('edit');
            Route::put('/{event}', [CommunicationEventController::class, 'update'])->whereUuid('event')->name('update');
            Route::post('/{event}/submit-approval', [CommunicationEventController::class, 'submit'])->whereUuid('event')->name('submit-approval');
            Route::post('/{event}/withdraw-approval', [CommunicationEventController::class, 'withdraw'])->whereUuid('event')->name('withdraw-approval');
            Route::post('/{event}/publish', [CommunicationEventController::class, 'publish'])->whereUuid('event')->name('publish');
            Route::post('/{event}/cancel', [CommunicationEventController::class, 'cancel'])->whereUuid('event')->name('cancel');
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
        Route::get('/attachments/{attachment}/download', [CommunicationAttachmentController::class, 'download'])->whereUuid('attachment')->name('attachments.download');

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

        Route::get('/{thread}', [CommunicationHubController::class, 'show'])->whereUuid('thread')->name('show');
        Route::post('/{thread}/messages', [CommunicationHubController::class, 'storeMessage'])->whereUuid('thread')->name('messages.store');
        Route::post('/{thread}/archive', [CommunicationHubController::class, 'archive'])->whereUuid('thread')->name('archive');
        Route::post('/{thread}/unarchive', [CommunicationHubController::class, 'unarchive'])->whereUuid('thread')->name('unarchive');

        // Phase 5A.7 §15: attachment upload/removal scoped to their
        // owning Thread, mirroring the Announcement pair above --
        // download reuses the SAME generic endpoint registered above
        // (brief §32: authorization is re-derived from the parent,
        // whichever type it is).
        Route::post('/{thread}/attachments', [CommunicationAttachmentController::class, 'storeForThread'])->whereUuid('thread')->name('threads.attachments.store');
        Route::delete('/{thread}/attachments/{attachment}', [CommunicationAttachmentController::class, 'destroyForThread'])->whereUuid(['thread', 'attachment'])->name('threads.attachments.destroy');
    });

    // Phase 1B.6: Enrollment administrative UI (docs/modules/STUDENT-ENROLLMENT.md,
    // "Administrative UI"). Capability checks live inside the controller
    // (AuthorizesCapability trait), matching every other App/ controller's
    // pattern -- every action re-derives the active School from
    // TenantContext, never a client-supplied id.
    Route::prefix('app/enrollments')->name('app.enrollments.')->group(function (): void {
        // ORG.7F-1 (ADR 0076 §45): Campus-aware (current in-scope placements for a Campus actor).
        Route::get('/', [EnrollmentDirectoryController::class, 'index'])->name('index');
        // V11.3 (ADR 0075 section 11): placements left open in a closed year.
        // Literal paths before /{enrollment}/... (enrollments.manage).
        Route::get('/closed-year-placements', [ClosedYearPlacementController::class, 'index'])->middleware('capability:enrollments.manage')->name('closed-year-placements.index');
        Route::post('/closed-year-placements/complete', [ClosedYearPlacementController::class, 'complete'])->middleware('capability:enrollments.manage')->name('closed-year-placements.complete');
        Route::post('/{enrollment}/complete', [StudentEnrollmentController::class, 'complete'])->middleware('capability:enrollments.manage')->name('complete');
        Route::post('/{enrollment}/withdraw', [StudentEnrollmentController::class, 'withdraw'])->middleware('capability:enrollments.manage')->name('withdraw');
        Route::post('/{enrollment}/cancel', [StudentEnrollmentController::class, 'cancel'])->middleware('capability:enrollments.manage')->name('cancel');
        // SIS 4.2: cancel a placement before it starts; correct a roll number.
        Route::post('/{enrollment}/cancel-before-start', [StudentEnrollmentController::class, 'cancelBeforeStart'])->middleware('capability:enrollments.manage')->whereUuid('enrollment')->name('cancel-before-start');
        Route::post('/{enrollment}/roll-number', [StudentEnrollmentController::class, 'correctRollNumber'])->middleware('capability:enrollments.manage')->whereUuid('enrollment')->name('roll-number');
        Route::get('/{enrollment}/transfer', [StudentEnrollmentController::class, 'transferCreate'])->middleware('capability:enrollments.manage')->name('transfer.create');
        Route::post('/{enrollment}/transfer', [StudentEnrollmentController::class, 'transfer'])->middleware('capability:enrollments.manage')->name('transfer.store');
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
        // ORG.7F-1 (ADR 0076 §45): rollover reads are declared whole-School-only
        // operations of the adopted enrollments.view.
        Route::get('/', [EnrollmentRolloverController::class, 'index'])->middleware(['capability:enrollments.view', 'capability:enrollments.rollovers.view'])->name('index');
        Route::get('/create', [EnrollmentRolloverController::class, 'create'])->middleware('capability:enrollments.manage')->name('create');
        Route::post('/', [EnrollmentRolloverController::class, 'store'])->middleware('capability:enrollments.manage')->name('store');
        Route::get('/{rollover}', [EnrollmentRolloverController::class, 'show'])->middleware(['capability:enrollments.view', 'capability:enrollments.rollovers.view'])->name('show');
        Route::post('/{rollover}/validate', [EnrollmentRolloverController::class, 'validate'])->middleware('capability:enrollments.manage')->name('validate');
        Route::post('/{rollover}/start', [EnrollmentRolloverController::class, 'start'])->middleware('capability:enrollments.manage')->name('start');
        Route::post('/{rollover}/resume', [EnrollmentRolloverController::class, 'resume'])->middleware('capability:enrollments.manage')->name('resume');

        Route::post('/{rollover}/mappings', [EnrollmentRolloverMappingController::class, 'store'])->middleware('capability:enrollments.manage')->name('mappings.store');
        Route::patch('/{rollover}/mappings/{mapping}', [EnrollmentRolloverMappingController::class, 'update'])->middleware('capability:enrollments.manage')->name('mappings.update');

        // Phase 1G.4: subject-mapping configuration, addressed by the
        // SOURCE SubjectOffering id -- same shape/rationale as the JSON
        // API's identical routes (routes/api.php).
        Route::put('/{rollover}/subject-mappings/{subjectOffering}', [EnrollmentRolloverSubjectMappingController::class, 'upsert'])->middleware('capability:enrollments.manage')->name('subject-mappings.upsert');
        Route::delete('/{rollover}/subject-mappings/{subjectOffering}', [EnrollmentRolloverSubjectMappingController::class, 'destroy'])->middleware('capability:enrollments.manage')->name('subject-mappings.destroy');

        Route::patch('/{rollover}/items/{item}', [EnrollmentRolloverItemController::class, 'update'])->middleware('capability:enrollments.manage')->name('items.update');

        // UAT-C2 (UAT-YR-01b): bulk Promote / Exclude -- preview writes
        // nothing; apply is guarded by the plan's configuration version.
        Route::post('/{rollover}/bulk-decisions/preview', [EnrollmentRolloverBulkDecisionController::class, 'preview'])->middleware('capability:enrollments.manage')->name('bulk-decisions.preview');
        Route::post('/{rollover}/bulk-decisions', [EnrollmentRolloverBulkDecisionController::class, 'apply'])->middleware('capability:enrollments.manage')->name('bulk-decisions.apply');
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
        // SWC.2: create/change an Offering through SubjectOfferingService (ADR 0069 freeze kept).
        Route::post('/', [SubjectOfferingAdminController::class, 'store'])->name('store');
        Route::get('/{subjectOffering}', [SubjectOfferingController::class, 'show'])->name('show');
        Route::put('/{subjectOffering}', [SubjectOfferingAdminController::class, 'update'])->name('update');
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
    // ORG.7D-3 (ADR 0076 §39): the application list, detail and lifecycle are
    // Campus-adopted (checked in the controller); every route below carrying a
    // `capability:admissions.*` floor is a declared whole-School-only operation
    // (CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS).
    Route::prefix('app/admissions')->name('app.admissions.')->group(function (): void {
        Route::get('/', [AdmissionApplicationController::class, 'index'])->name('index');
        // SWC.3 (ADR 0073 section 19): the School's admission fee head (OPF.3).
        // Registered before `/{admissionApplication}` so it is never captured.
        Route::get('/fee-head', [OperationalFeeController::class, 'admissionFeeHead'])->middleware('capability:admissions.view')->name('fee-head');
        Route::put('/fee-head', [OperationalFeeController::class, 'updateAdmissionFeeHead'])->middleware('capability:admissions.manage')->name('fee-head.update');

        Route::prefix('applicants')->name('applicants.')->group(function (): void {
            Route::get('/', [ApplicantController::class, 'index'])->middleware('capability:admissions.view')->name('index');
            Route::get('/create', [ApplicantController::class, 'create'])->middleware('capability:admissions.manage')->name('create');
            Route::post('/', [ApplicantController::class, 'store'])->middleware('capability:admissions.manage')->name('store');
            Route::get('/{applicant}', [ApplicantController::class, 'show'])->middleware('capability:admissions.view')->name('show');
            // SIS 3.1: identity correction (until an application is converted).
            Route::get('/{applicant}/edit', [ApplicantController::class, 'edit'])->middleware('capability:admissions.manage')->name('edit');
            Route::put('/{applicant}', [ApplicantController::class, 'update'])->middleware('capability:admissions.manage')->name('update');

            Route::get('/{applicant}/applications/create', [AdmissionApplicationController::class, 'create'])->middleware('capability:admissions.manage')->name('applications.create');
            Route::post('/{applicant}/applications', [AdmissionApplicationController::class, 'store'])->middleware('capability:admissions.manage')->name('applications.store');
        });

        // Guardian-picker adapter for the conversion form's "link
        // existing Guardian" mode -- see AdmissionApplicationController's
        // class docblock for why this is a narrow new pair rather than
        // reusing /app/students/{student}/guardians/search|candidates.
        Route::get('/guardians/search', [AdmissionApplicationController::class, 'searchGuardians'])->middleware('capability:admissions.manage')->name('guardians.search');
        Route::get('/guardians/candidates', [AdmissionApplicationController::class, 'candidateGuardians'])->middleware('capability:admissions.manage')->name('guardians.candidates');

        Route::get('/{admissionApplication}', [AdmissionApplicationController::class, 'show'])->name('show');
        Route::post('/{admissionApplication}/submit', [AdmissionApplicationController::class, 'submit'])->name('submit');
        Route::post('/{admissionApplication}/accept', [AdmissionApplicationController::class, 'accept'])->name('accept');
        Route::post('/{admissionApplication}/reject', [AdmissionApplicationController::class, 'reject'])->name('reject');
        Route::post('/{admissionApplication}/withdraw', [AdmissionApplicationController::class, 'withdraw'])->name('withdraw');
        // SIS 3.1: state-aware correction and discarding an unused draft
        // (Campus-adopted like the lifecycle actions, checked in the controller).
        Route::get('/{admissionApplication}/edit', [AdmissionApplicationController::class, 'edit'])->name('edit');
        Route::put('/{admissionApplication}', [AdmissionApplicationController::class, 'update'])->name('update');
        Route::post('/{admissionApplication}/discard', [AdmissionApplicationController::class, 'discard'])->name('discard');
        Route::post('/{admissionApplication}/convert', [AdmissionApplicationController::class, 'convert'])->middleware('capability:admissions.manage')->name('convert');
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
    // ORG.7D-5 (ADR 0076 §41): a Copy's Campus move (whole-School catalogue; refused while on loan).
    Route::patch('app/library/copies/{libraryCopy}', [LibraryCatalogueController::class, 'updateCopy'])->whereUuid('libraryCopy')->name('app.library.copies.update');

    Route::prefix('app/library/circulation')->name('app.library.circulation.')->group(function (): void {
        Route::get('/', [LibraryCirculationController::class, 'index'])->name('index');
        Route::get('/create', [LibraryCirculationController::class, 'create'])->name('create');
        Route::get('/search/copies', [LibraryCirculationController::class, 'searchAvailableCopies'])->name('search-copies');
        Route::get('/search/students', [LibraryCirculationController::class, 'searchStudents'])->name('search-students');
        Route::post('/', [LibraryCirculationController::class, 'store'])->name('store');
        Route::post('/{libraryLoan}/check-in', [LibraryCirculationController::class, 'checkIn'])->name('check-in');
        // SWC.3 (ADR 0073 section 19.5): one loan, with its fine (no School-wide fine list, X2).
        Route::get('/{libraryLoan}', [LibraryFineWebController::class, 'loan'])->whereUuid('libraryLoan')->name('show');
    });

    // SWC.3 (ADR 0073 section 19.4-19.5): the Library fine policy and the fine
    // void, over the OPF.4 services. Capability checks in LibraryFineWebController.
    Route::get('app/library/fine-policy', [LibraryFineWebController::class, 'policy'])->name('app.library.fine-policy');
    Route::post('app/library/fine-policy', [LibraryFineWebController::class, 'publish'])->name('app.library.fine-policy.publish');
    Route::post('app/library/fines/{libraryFine}/void', [LibraryFineWebController::class, 'void'])->name('app.library.fines.void');

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
        // SWC.3 (ADR 0073 section 19.2): the route's OPF fee head (OPF.1).
        Route::put('/{transportRoute}/fee-head', [OperationalFeeController::class, 'updateTransportRouteFeeHead'])->name('fee-head.update');
    });

    // SWC.3 (ADR 0073 section 19.2): Transport fee intent and next-year carry-forward (OPF.1).
    // ORG.7D-5 (ADR 0076 §41): declared whole-School-only operations of Campus-adopted keys.
    Route::get('app/transport/fees', [OperationalFeeController::class, 'transportFees'])->middleware('capability:transport.assignments.view')->name('app.transport.fees');
    Route::post('app/transport/fees/carry-forward', [OperationalFeeController::class, 'transportCarryForward'])->middleware('capability:transport.assignments.manage')->name('app.transport.fees.carry-forward');

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
        // ORG.9C-4 (ADR 0076 §72): add-only bulk rider assignment (upload -> preview -> atomic commit).
        Route::get('/import', [TransportRiderImportController::class, 'create'])->name('import.create');
        Route::get('/import/template', [TransportRiderImportController::class, 'template'])->name('import.template');
        Route::post('/import/preview', [TransportRiderImportController::class, 'preview'])->middleware('throttle:transport-rider-import')->name('import.preview');
        Route::post('/import/commit', [TransportRiderImportController::class, 'commit'])->middleware('throttle:transport-rider-import')->name('import.commit');
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
    // every other module. Payments has no edit/delete route and no
    // `finance.payments.manage` capability; its writes are recording an
    // offline payment (Phase 0O.11A, payments/record below) and, since
    // UAT-C3, a correction (payments/{payment}/corrections below).
    Route::prefix('app/finance')->name('app.finance.')->group(function (): void {
        Route::get('/', [FinanceController::class, 'index'])->name('index');

        Route::get('/ledger-accounts', [FinanceLedgerAccountController::class, 'index'])->name('ledger-accounts.index');
        // FEE.1 (ADR 0062 §6, K1): create / activate-deactivate only
        // (finance.accounts.manage). No delete, no type change.
        Route::post('/ledger-accounts', [FinanceLedgerAccountController::class, 'store'])->name('ledger-accounts.store');
        Route::post('/ledger-accounts/{ledgerAccount}/status', [FinanceLedgerAccountController::class, 'updateStatus'])->name('ledger-accounts.status');
        // ORG.9B (ADR 0076 §68), 9B-3: one account's statement (opening,
        // running and closing balance) -- read-only, finance.ledger.view.
        Route::get('/ledger-accounts/{ledgerAccount}/ledger', [FinanceLedgerAccountController::class, 'ledger'])->name('ledger-accounts.ledger');

        // FEE.1 (ADR 0062 §5-§8): Fee setup -- fee heads, fee structures,
        // instalment schedules, optional-fee selections. View needs
        // finance.fee_structures.view, every write .manage (checked in
        // FeeSetupController and again in the Application services).
        // 'students/search' is registered before the '{feeStructure}'
        // wildcard, the same convention as 'charges/create' below.
        Route::get('/fee-setup', [FinanceFeeSetupController::class, 'index'])->name('fee-setup.index');
        Route::post('/fee-setup/fee-heads', [FinanceFeeSetupController::class, 'storeFeeHead'])->name('fee-setup.fee-heads.store');
        Route::post('/fee-setup/receipt-numbering', [FinanceFeeSetupController::class, 'updateReceiptNumbering'])->name('fee-setup.receipt-numbering');
        Route::post('/fee-setup/fee-heads/{feeHead}', [FinanceFeeSetupController::class, 'updateFeeHead'])->name('fee-setup.fee-heads.update');
        Route::post('/fee-setup/structures', [FinanceFeeSetupController::class, 'storeStructure'])->name('fee-setup.structures.store');
        Route::get('/fee-setup/students/search', [FinanceFeeSetupController::class, 'searchStudents'])->name('fee-setup.students.search');
        Route::get('/fee-setup/structures/{feeStructure}', [FinanceFeeSetupController::class, 'showStructure'])->name('fee-setup.structures.show');
        Route::post('/fee-setup/structures/{feeStructure}', [FinanceFeeSetupController::class, 'updateStructure'])->name('fee-setup.structures.update');
        Route::post('/fee-setup/structures/{feeStructure}/activate', [FinanceFeeSetupController::class, 'activate'])->name('fee-setup.structures.activate');
        Route::post('/fee-setup/structures/{feeStructure}/retire', [FinanceFeeSetupController::class, 'retire'])->name('fee-setup.structures.retire');
        Route::post('/fee-setup/structures/{feeStructure}/successor', [FinanceFeeSetupController::class, 'successor'])->name('fee-setup.structures.successor');
        Route::post('/fee-setup/structures/{feeStructure}/lines', [FinanceFeeSetupController::class, 'storeLine'])->name('fee-setup.lines.store');
        Route::post('/fee-setup/structures/{feeStructure}/lines/{line}', [FinanceFeeSetupController::class, 'updateLine'])->name('fee-setup.lines.update');
        Route::post('/fee-setup/structures/{feeStructure}/lines/{line}/remove', [FinanceFeeSetupController::class, 'removeLine'])->name('fee-setup.lines.remove');
        Route::post('/fee-setup/structures/{feeStructure}/lines/{line}/installments', [FinanceFeeSetupController::class, 'replaceInstallments'])->name('fee-setup.lines.installments');
        Route::post('/fee-setup/structures/{feeStructure}/lines/{line}/generate', [FinanceFeeSetupController::class, 'generateInstallments'])->name('fee-setup.lines.generate');
        Route::post('/fee-setup/selections', [FinanceFeeSetupController::class, 'storeSelection'])->name('fee-setup.selections.store');
        Route::post('/fee-setup/selections/{selection}/withdraw', [FinanceFeeSetupController::class, 'withdrawSelection'])->name('fee-setup.selections.withdraw');

        // FEE.2 (ADR 0062 §9): assessment runs. View under
        // finance.charges.view, every run action under
        // finance.fee_assessments.run, assessment void under
        // finance.charges.manage (controller + Application service).
        // ORG.7G-1 (ADR 0076 §50): run reads are declared whole-School
        // (finance.charges.view floor; CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS).
        Route::get('/fee-runs', [FinanceFeeAssessmentRunController::class, 'index'])->middleware('capability:finance.charges.view')->name('fee-runs.index');
        Route::post('/fee-runs', [FinanceFeeAssessmentRunController::class, 'store'])->name('fee-runs.store');
        Route::get('/fee-runs/{run}', [FinanceFeeAssessmentRunController::class, 'show'])->middleware('capability:finance.charges.view')->name('fee-runs.show');
        Route::post('/fee-runs/{run}/preview', [FinanceFeeAssessmentRunController::class, 'preview'])->name('fee-runs.preview');
        Route::post('/fee-runs/{run}/items/{item}/exclude', [FinanceFeeAssessmentRunController::class, 'exclude'])->name('fee-runs.items.exclude');
        Route::post('/fee-runs/{run}/items/{item}/decide', [FinanceFeeAssessmentRunController::class, 'decide'])->name('fee-runs.items.decide');
        Route::post('/fee-runs/{run}/execute', [FinanceFeeAssessmentRunController::class, 'execute'])->name('fee-runs.execute');
        Route::post('/fee-runs/{run}/resume', [FinanceFeeAssessmentRunController::class, 'resume'])->name('fee-runs.resume');
        Route::post('/fee-runs/{run}/cancel', [FinanceFeeAssessmentRunController::class, 'cancel'])->name('fee-runs.cancel');
        Route::post('/fee-assessments/{assessment}/void', [FinanceFeeAssessmentRunController::class, 'void'])->name('fee-assessments.void');

        // FEE.5 (ADR 0062 §16; owner decision H): late fees. Rules under
        // finance.fee_structures.view/.manage; runs under finance.charges.view
        // (read) and finance.fee_assessments.run (act); voiding a late fee
        // under finance.charges.manage (controller + Application service).
        Route::get('/late-fees', [FinanceLateFeeController::class, 'rules'])->name('late-fees.rules');
        Route::post('/late-fees', [FinanceLateFeeController::class, 'storeRule'])->name('late-fees.rules.store');
        Route::post('/late-fees/{rule}', [FinanceLateFeeController::class, 'updateRule'])->name('late-fees.rules.update');
        Route::post('/late-fees/{rule}/status', [FinanceLateFeeController::class, 'ruleStatus'])->name('late-fees.rules.status');
        Route::get('/late-fee-runs', [FinanceLateFeeController::class, 'runs'])->middleware('capability:finance.charges.view')->name('late-fee-runs.index');
        Route::post('/late-fee-runs', [FinanceLateFeeController::class, 'storeRun'])->name('late-fee-runs.store');
        Route::get('/late-fee-runs/{run}', [FinanceLateFeeController::class, 'showRun'])->middleware('capability:finance.charges.view')->name('late-fee-runs.show');
        Route::post('/late-fee-runs/{run}/preview', [FinanceLateFeeController::class, 'preview'])->name('late-fee-runs.preview');
        Route::post('/late-fee-runs/{run}/execute', [FinanceLateFeeController::class, 'execute'])->name('late-fee-runs.execute');
        Route::post('/late-fee-runs/{run}/resume', [FinanceLateFeeController::class, 'resume'])->name('late-fee-runs.resume');
        Route::post('/late-fee-runs/{run}/cancel', [FinanceLateFeeController::class, 'cancel'])->name('late-fee-runs.cancel');
        Route::post('/late-fee-assessments/{assessment}/void', [FinanceLateFeeController::class, 'void'])->name('late-fee-assessments.void');

        // FEE.3 (ADR 0062 §14): concessions. View under
        // finance.fee_concessions.view, request/withdraw under .request,
        // approve/reject/revoke and adjustment cancellation under .approve
        // (never the requester's own), the concession account under
        // finance.fee_structures.manage -- controller + Application service.
        // 'create' and 'students/search' precede the '{concession}' wildcard.
        Route::get('/concessions', [FinanceFeeConcessionController::class, 'index'])->name('concessions.index');
        Route::get('/concessions/create', [FinanceFeeConcessionController::class, 'create'])->name('concessions.create');
        Route::get('/concessions/students/search', [FinanceFeeConcessionController::class, 'searchStudents'])->name('concessions.students.search');
        Route::post('/concessions', [FinanceFeeConcessionController::class, 'store'])->name('concessions.store');
        Route::post('/concessions/settings', [FinanceFeeConcessionController::class, 'updateSettings'])->name('concessions.settings');
        Route::get('/concessions/{concession}', [FinanceFeeConcessionController::class, 'show'])->name('concessions.show');
        Route::post('/concessions/{concession}/withdraw', [FinanceFeeConcessionController::class, 'withdraw'])->name('concessions.withdraw');
        Route::post('/concessions/{concession}/approve', [FinanceFeeConcessionController::class, 'approve'])->name('concessions.approve');
        Route::post('/concessions/{concession}/reject', [FinanceFeeConcessionController::class, 'reject'])->name('concessions.reject');
        Route::post('/concessions/{concession}/revoke', [FinanceFeeConcessionController::class, 'revoke'])->name('concessions.revoke');
        Route::post('/fee-adjustments/{adjustment}/cancel', [FinanceFeeConcessionController::class, 'cancelAdjustment'])->name('fee-adjustments.cancel');

        // 'create' registered BEFORE the '/{journalEntry}' wildcard
        // below, matching this file's own established convention
        // (e.g. 'announcements'/'templates' inside the Communications
        // group above).
        // E21.3A (ADR 0064 §7): financial periods. View under
        // finance.ledger.view; close (irreversible, no reopen) under
        // finance.periods.manage + typed period key + fresh MFA.
        Route::get('/periods', [FinanceFinancialPeriodController::class, 'index'])->name('periods.index');
        Route::post('/periods/{period}/close', [FinanceFinancialPeriodController::class, 'close'])->whereUuid('period')->name('periods.close');

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
        // ORG.7G-1 (ADR 0076 §50): charge history is Campus-aware (C6, stored
        // Charge Campus); creating and cancelling stay whole-School.
        Route::get('/charges', [FinanceChargeHistoryController::class, 'index'])->middleware('capability:finance.charges.view,campus')->name('charges.index');
        Route::post('/charges', [FinanceChargeController::class, 'store'])->name('charges.store');
        Route::get('/charges/{charge}', [FinanceChargeHistoryController::class, 'show'])->middleware('capability:finance.charges.view,campus')->name('charges.show');
        Route::post('/charges/{charge}/cancel', [FinanceChargeController::class, 'cancel'])->name('charges.cancel');

        // Phase 0O.11A (ADR 0031 implementation amendment): recording an
        // OFFLINE payment the School already received (cash, bank
        // transfer, cheque) -- finance.payments.record, checked in the
        // controller and again in ManualPaymentRecordingService. Browser
        // only (web group: session + CSRF); no /api/v1 write. Registered
        // BEFORE the '/{payment}' wildcard, same reasoning as
        // 'charges/create' above.
        // ORG.7G-2 (ADR 0076 §51): Campus-aware (C6) -- a Campus recorder may
        // record only for Charges of ONE stored Campus in scope.
        Route::get('/payments/record', [FinanceManualPaymentController::class, 'create'])->middleware('capability:finance.payments.record,campus')->name('payments.record.create');
        Route::get('/payments/record/students/search', [FinanceManualPaymentController::class, 'searchStudents'])->middleware('capability:finance.payments.record,campus')->name('payments.record.students.search');
        Route::post('/payments/record', [FinanceManualPaymentController::class, 'store'])
            ->middleware(['capability:finance.payments.record,campus', 'throttle:finance-payment-recording'])->name('payments.record.store');

        // ORG.7G-1 (ADR 0076 §50): collections, one Payment and its receipt are
        // Campus-aware (C6): a Payment is Campus-visible only when every Charge
        // it touches has the same stored Campus in scope (G1-D4).
        Route::get('/payments', [FinancePaymentRecordController::class, 'index'])->middleware('capability:finance.payments.view,campus')->name('payments.index');
        Route::get('/payments/{payment}', [FinancePaymentRecordController::class, 'show'])->middleware('capability:finance.payments.view,campus')->name('payments.show');
        // FEE.4 (ADR 0062 §17, §18): the printable payment acknowledgement
        // (finance.payments.view) and the staff Student fee statement
        // (finance.charges.view AND finance.payments.view).
        Route::get('/payments/{payment}/receipt', [FinancePaymentRecordController::class, 'receipt'])->middleware('capability:finance.payments.view,campus')->name('payments.receipt');
        // UAT-C3 (ADR 0031 amendment §3, ADR 0075 §16) + ORG.8C (ADR 0076
        // §61): a reallocation, reversal or refund of a posted Payment is a
        // new linked correction -- never an edit -- and since ORG.8C it is
        // REQUESTED here and executed only when someone else approves it
        // (there is no direct correction route). Whole-School; every check is
        // repeated in PaymentCorrectionRequestService.
        Route::post('/payments/{payment}/correction-requests', [FinancePaymentCorrectionRequestController::class, 'store'])
            ->middleware(['capability:finance.payment_corrections.request', 'throttle:finance-payment-recording'])->name('payments.correction_requests.store');
        Route::get('/payment-correction-requests', [FinancePaymentCorrectionRequestController::class, 'index'])
            ->middleware('capability:finance.payment_corrections.view')->name('payment_correction_requests.index');
        // Approval executes the correction (fresh MFA in the controller).
        Route::post('/payment-correction-requests/{correctionRequest}/approve', [FinancePaymentCorrectionRequestController::class, 'approve'])
            ->middleware(['capability:finance.payment_corrections.approve', 'throttle:finance-payment-recording'])->name('payment_correction_requests.approve');
        Route::post('/payment-correction-requests/{correctionRequest}/reject', [FinancePaymentCorrectionRequestController::class, 'reject'])
            ->middleware(['capability:finance.payment_corrections.approve', 'throttle:finance-payment-recording'])->name('payment_correction_requests.reject');
        Route::post('/payment-correction-requests/{correctionRequest}/withdraw', [FinancePaymentCorrectionRequestController::class, 'withdraw'])
            ->middleware(['capability:finance.payment_corrections.request', 'throttle:finance-payment-recording'])->name('payment_correction_requests.withdraw');
        // UAT-C3 (UAT-FIN-08): who still owes what, for follow-up.
        // ORG.7G-1 (ADR 0076 §50): Campus-aware (C6) by stored Charge Campus;
        // finance.payments.view is required in the action too (both scopes).
        Route::get('/outstanding', [FinanceOutstandingFeesController::class, 'index'])->middleware('capability:finance.charges.view,campus')->name('outstanding.index');
        // ORG.7G-1 (G1-D8): the Finance debtor lookup (open in-scope debt only).
        Route::get('/debtors/search', [FinanceDebtorController::class, 'search'])->middleware('capability:finance.charges.view,campus')->name('debtors.search');
        // The full Student fee statement is declared whole-School (it mixes every
        // Campus, NULL-Campus charges and mixed-Campus Payments); the statement
        // service also requires whole-School finance.payments.view.
        Route::get('/fee-statements', [FinanceFeeStatementController::class, 'index'])->middleware('capability:finance.charges.view')->name('fee-statements.index');
        Route::get('/fee-statements/students/search', [FinanceFeeStatementController::class, 'searchStudents'])->middleware('capability:finance.charges.view')->name('fee-statements.students.search');
        Route::get('/fee-statements/{student}', [FinanceFeeStatementController::class, 'show'])->middleware('capability:finance.charges.view')->name('fee-statements.show');
    });

    // Phase 8A closure correction (item 2) -- the HR administrative UI
    // the original roadmap promised. Capability checks live inside each
    // controller (AuthorizesCapability trait), matching every other
    // module's Inertia controller in this file -- no route-level
    // `capability:` middleware for these session-authenticated pages
    // (that pattern is reserved for the Bearer-token /api/v1 JSON API,
    // see item 9's own reasoning there). Every mutation delegates to
    // the SAME Application-layer services the JSON API controllers use.
    // ORG.7E-1 (ADR 0076 §43): the Directory, operational overview, core edit,
    // onboarding and assignment actions are Campus-adopted (checked in the
    // controllers through HrEmployeeAccess); every route below carrying a
    // `capability:hr.employees.*` floor is a declared whole-School-only
    // operation (CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS).
    Route::prefix('app/hr')->name('app.hr.')->group(function (): void {
        Route::get('/', [HrController::class, 'index'])->name('index');

        // 'employees/create' and 'employees/import' registered BEFORE
        // the '/{employee}' wildcard below, matching this file's own
        // established convention.
        Route::get('/employees/create', [HrEmployeeController::class, 'create'])->middleware('capability:hr.employees.manage')->name('employees.create');
        Route::get('/employees', [HrEmployeeController::class, 'index'])->name('employees.index');
        Route::post('/employees', [HrEmployeeController::class, 'store'])->middleware('capability:hr.employees.manage')->name('employees.store');
        Route::get('/employees/onboard', [HrEmployeeController::class, 'onboard'])->name('employees.onboard');
        Route::post('/employees/onboard', [HrEmployeeController::class, 'storeOnboarding'])->name('employees.onboard.store');

        Route::get('/employees/import', [HrEmployeeImportController::class, 'create'])->middleware('capability:hr.employees.manage')->name('employees.import.create');
        Route::post('/employees/import', [HrEmployeeImportController::class, 'store'])->middleware('capability:hr.employees.manage')->name('employees.import.store');

        Route::get('/employees/{employee}', [HrEmployeeController::class, 'show'])->name('employees.show');
        Route::get('/employees/{employee}/overview', [HrEmployeeController::class, 'overview'])->name('employees.overview');
        Route::get('/employees/{employee}/edit', [HrEmployeeController::class, 'edit'])->name('employees.edit');
        Route::put('/employees/{employee}', [HrEmployeeController::class, 'update'])->name('employees.update');
        Route::post('/employees/{employee}/archive', [HrEmployeeController::class, 'archive'])->middleware('capability:hr.employees.manage')->name('employees.archive');
        Route::post('/employees/{employee}/restore', [HrEmployeeController::class, 'restore'])->middleware('capability:hr.employees.manage')->name('employees.restore');

        Route::put('/employees/{employee}/personal-detail', [HrEmployeePersonalDetailController::class, 'update'])->name('employees.personal-detail.update');

        Route::post('/employees/{employee}/addresses', [HrEmployeeAddressController::class, 'store'])->name('employees.addresses.store');
        Route::delete('/employees/{employee}/addresses/{address}', [HrEmployeeAddressController::class, 'destroy'])->name('employees.addresses.destroy');

        Route::post('/employees/{employee}/emergency-contacts', [HrEmployeeEmergencyContactController::class, 'store'])->name('employees.emergency-contacts.store');
        Route::delete('/employees/{employee}/emergency-contacts/{contact}', [HrEmployeeEmergencyContactController::class, 'destroy'])->name('employees.emergency-contacts.destroy');
        Route::post('/employees/{employee}/emergency-contacts/{contact}/primary', [HrEmployeeEmergencyContactController::class, 'setPrimary'])->name('employees.emergency-contacts.set-primary');

        Route::post('/employees/{employee}/notes', [HrEmployeeNoteController::class, 'store'])->name('employees.notes.store');
        Route::delete('/employees/{employee}/notes/{note}', [HrEmployeeNoteController::class, 'destroy'])->name('employees.notes.destroy');

        Route::post('/employees/{employee}/employment-records', [HrEmploymentController::class, 'store'])->middleware('capability:hr.employees.assignments.manage')->name('employees.employment-records.store');
        Route::post('/employees/{employee}/employment-records/{employment}/end', [HrEmploymentController::class, 'end'])->middleware('capability:hr.employees.assignments.manage')->name('employees.employment-records.end');
        Route::post('/employees/{employee}/employment-records/{employment}/separate', [HrEmploymentController::class, 'separate'])->middleware('capability:hr.employees.assignments.manage')->name('employees.employment-records.separate');
        Route::post('/employees/{employee}/rehire', [HrEmploymentController::class, 'rehire'])->middleware('capability:hr.employees.assignments.manage')->name('employees.rehire');

        Route::post('/employees/{employee}/employment-records/{employment}/assignments', [HrEmployeeAssignmentController::class, 'store'])->name('employees.assignments.store');
        Route::post('/employees/{employee}/employment-records/{employment}/assignments/{assignment}/end', [HrEmployeeAssignmentController::class, 'end'])->name('employees.assignments.end');
        Route::post('/employees/{employee}/employment-records/{employment}/assignments/{assignment}/primary', [HrEmployeeAssignmentController::class, 'setPrimary'])->name('employees.assignments.set-primary');
        Route::post('/employees/{employee}/employment-records/{employment}/assignments/{assignment}/manager', [HrEmployeeAssignmentController::class, 'setManager'])->name('employees.assignments.set-manager');
        // UAT-C6 (UAT-HR-23): manager picked by name, never a typed id.
        Route::get('/employees/{employee}/employment-records/{employment}/assignments/{assignment}/manager-candidates', [HrEmployeeAssignmentController::class, 'managerCandidates'])->name('employees.assignments.manager-candidates');
        // UAT-C6 (UAT-STF-05): the governed Employee <-> account link in the browser.
        Route::get('/employees/{employee}/account-link/search', [HrEmployeeAccountLinkController::class, 'search'])->middleware('capability:hr.employees.manage')->name('employees.account-link.search');
        Route::post('/employees/{employee}/account-link', [HrEmployeeAccountLinkController::class, 'store'])->middleware('capability:hr.employees.manage')->name('employees.account-link.store');
        Route::post('/employees/{employee}/account-link/remove', [HrEmployeeAccountLinkController::class, 'destroy'])->middleware('capability:hr.employees.manage')->name('employees.account-link.destroy');

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
        // SWC.3 (ADR 0073 section 19.3): the Hostel's default OPF fee head (OPF.2).
        Route::put('/{hostel}/fee-head', [OperationalFeeController::class, 'updateHostelFeeHead'])->name('fee-head.update');
    });

    Route::prefix('app/hostel-rooms')->name('app.hostel-rooms.')->group(function (): void {
        Route::get('/{hostelRoom}', [HostelRoomController::class, 'show'])->name('show');
        Route::patch('/{hostelRoom}', [HostelRoomController::class, 'update'])->name('update');
        Route::post('/{hostelRoom}/beds', [HostelRoomController::class, 'storeBed'])->name('beds.store');
        // SWC.3: the room's override OPF fee head (OPF.2).
        Route::put('/{hostelRoom}/fee-head', [OperationalFeeController::class, 'updateRoomFeeHead'])->name('fee-head.update');
    });

    Route::patch('app/hostel-beds/{hostelBed}', [HostelRoomController::class, 'updateBed'])
        ->name('app.hostel-beds.update');

    Route::prefix('app/hostel-residency')->name('app.hostel-residency.')->group(function (): void {
        Route::get('/', [HostelResidencyController::class, 'index'])->name('index');
        Route::get('/create', [HostelResidencyController::class, 'create'])->name('create');
        Route::get('/search/students', [HostelResidencyController::class, 'searchStudents'])->name('search-students');
        Route::get('/search/beds', [HostelResidencyController::class, 'searchBeds'])->name('search-beds');
        // SWC.3 (ADR 0073 section 19.3): Hostel fee intent and next-year carry-forward (OPF.2).
        // ORG.7D-3: declared whole-School-only operations of the Campus-adopted
        // residency keys (CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS).
        Route::get('/fees', [OperationalFeeController::class, 'hostelFees'])->middleware('capability:hostel.residency.view')->name('fees');
        Route::post('/fees/carry-forward', [OperationalFeeController::class, 'hostelCarryForward'])->middleware('capability:hostel.residency.manage')->name('fees.carry-forward');
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
        // ORG.9C-2 (ADR 0076 §70): write-off, DIRECT_WITH_ASSURANCE (fresh MFA + fresh authority + closed reason).
        Route::get('/write-off', [InventoryStockController::class, 'createWriteOff'])->name('write-off.create');
        Route::post('/write-off', [InventoryStockController::class, 'storeWriteOff'])->name('write-off.store');
        Route::get('/search/items', [InventoryStockController::class, 'searchItems'])->name('search-items');
        Route::get('/search/locations', [InventoryStockController::class, 'searchLocations'])->name('search-locations');
        Route::get('/search/departments', [InventoryStockController::class, 'searchDepartments'])->name('search-departments');
        // ORG.9C-3 (ADR 0076 §71): read-only stock register (INV-016/023) and month view (INV-020).
        Route::get('/register', [InventoryStockController::class, 'register'])->name('register');
        Route::get('/monthly', [InventoryStockController::class, 'monthly'])->name('monthly');
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
        Route::get('/{timetablePeriod}/edit', [TimetablePeriodController::class, 'edit'])->name('edit');
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

    // TCH.3 (ADR 0063 section 11, Tier 2) -- "My Curriculum Delivery", the
    // owned teacher page: only the calling teacher's own classes and the
    // deliveries their TeachingAssignments cover. Authorization lives in
    // the controller (TeacherDeliveryAccess) and, for writes, again inside
    // CurriculumDeliveryService's transaction. No delete route.
    Route::prefix('app/my-curriculum-delivery')->name('app.my-curriculum-delivery.')->group(function (): void {
        Route::get('/', [MyCurriculumDeliveryController::class, 'index'])->name('index');
        Route::post('/', [MyCurriculumDeliveryController::class, 'store'])->name('store');
        Route::patch('/{curriculumDelivery}', [MyCurriculumDeliveryController::class, 'update'])->name('update');
        Route::post('/{curriculumDelivery}/transition', [MyCurriculumDeliveryController::class, 'transition'])->name('transition');
    });

    // TCH.2 (ADR 0063 section 23) -- the administrative TeachingAssignment
    // page: list, create, end. Capability checks live in the controller
    // (AuthorizesCapability) and again in the services. No edit and no
    // delete route: an assignment's owner, class and start date never
    // change, and an assignment is ended, never deleted.
    Route::prefix('app/teaching-assignments')->name('app.teaching-assignments.')->group(function (): void {
        Route::get('/', [TeachingAssignmentController::class, 'index'])->name('index');
        Route::post('/', [TeachingAssignmentController::class, 'store'])->name('store');
        Route::post('/{teachingAssignment}/end', [TeachingAssignmentController::class, 'end'])->name('end');
    });

    // TCH-E (ADR 0063 section 45) -- the administrative ELECTIVE teaching
    // assignment page (Employee x elective Offering, Offering-wide): list,
    // create, end, under the same teaching.assignments.* capabilities
    // (checked in the controller and the service). No edit, no delete.
    Route::prefix('app/elective-teaching-assignments')->name('app.elective-teaching-assignments.')->group(function (): void {
        Route::get('/', [ElectiveTeachingAssignmentController::class, 'index'])->name('index');
        Route::post('/', [ElectiveTeachingAssignmentController::class, 'store'])->name('store');
        Route::post('/{electiveTeachingAssignment}/end', [ElectiveTeachingAssignmentController::class, 'end'])->name('end');
    });

    // HRX.2 (ADR 0065 §23.14) -- Leave administration (configuration,
    // entitlements, requests, year close) and the manager's approvals page.
    // Every page and command is capability-checked in its controller AND again
    // in the Leave services; the approvals page lists only the acting
    // manager's current direct reports (server-side) and answers the private
    // 404 for anything else. No self-service and no Teacher-specific page.
    Route::prefix('app/leave')->name('app.leave.')->group(function (): void {
        Route::get('/configuration', [LeaveAdminController::class, 'configuration'])->name('configuration');
        Route::post('/settings', [LeaveAdminController::class, 'updateSettings'])->name('settings.update');
        Route::post('/year-start-changes', [LeaveAdminController::class, 'scheduleStartChange'])->name('year-start-changes.store');
        Route::post('/years', [LeaveAdminController::class, 'openYear'])->name('years.store');
        Route::post('/types', [LeaveAdminController::class, 'storeType'])->name('types.store');
        Route::post('/types/{leaveType}/status', [LeaveAdminController::class, 'setTypeStatus'])->name('types.status');
        Route::post('/policies', [LeaveAdminController::class, 'storePolicy'])->name('policies.store');
        Route::post('/policies/{leavePolicy}/retire', [LeaveAdminController::class, 'retirePolicy'])->name('policies.retire');
        Route::post('/calendar/weekdays', [LeaveAdminController::class, 'updateWeeklyPattern'])->name('calendar.weekdays');
        Route::post('/calendar/holidays', [LeaveAdminController::class, 'storeHoliday'])->name('calendar.holidays.store');
        Route::post('/calendar/holidays/{staffHoliday}/remove', [LeaveAdminController::class, 'destroyHoliday'])->name('calendar.holidays.remove');

        Route::get('/entitlements', [LeaveAdminController::class, 'entitlements'])->name('entitlements');
        Route::post('/assignments', [LeaveAdminController::class, 'storeAssignment'])->name('assignments.store');
        Route::post('/assignments/{leavePolicyAssignment}/end', [LeaveAdminController::class, 'endAssignment'])->name('assignments.end');
        Route::post('/allocations', [LeaveAdminController::class, 'allocate'])->name('allocations.store');
        Route::post('/adjustments', [LeaveAdminController::class, 'adjust'])->name('adjustments.store');
        Route::post('/allocation-runs', [LeaveAdminController::class, 'executeRun'])->name('allocation-runs.store');

        Route::get('/requests', [LeaveAdminController::class, 'requests'])->name('requests');
        // ORG.9C-1 (ADR 0076 §69), HR-042: month-wise leave taken, read-only (hr.leave.view).
        Route::get('/monthly', [LeaveAdminController::class, 'monthly'])->name('monthly');
        Route::post('/requests', [LeaveAdminController::class, 'submitRequest'])->name('requests.store');
        Route::get('/requests/{leaveRequest}', [LeaveAdminController::class, 'showRequest'])->name('requests.show');
        foreach (['approve', 'reject', 'withdraw', 'cancel'] as $action) {
            Route::post("/requests/{leaveRequest}/{$action}", [LeaveAdminController::class, 'decideRequest'])->defaults('action', $action)->name("requests.{$action}");
        }

        Route::get('/year-close', [LeaveAdminController::class, 'yearClose'])->name('year-close');
        Route::post('/year-close', [LeaveAdminController::class, 'executeYearClose'])->name('year-close.store');

        Route::get('/approvals', [LeaveApprovalsController::class, 'index'])->name('approvals');
        foreach (['approve', 'reject'] as $action) {
            Route::post("/approvals/{leaveRequest}/{$action}", [LeaveApprovalsController::class, 'decide'])->defaults('action', $action)->name("approvals.{$action}");
        }
    });

    // HRX.4 (ADR 0065 §25) -- Staff Self-Service: My Leave, My Attendance
    // (read only), My Payslips. Each page needs its `.self` capability; the
    // services add ActingEmployee ownership. Fixed action paths (never a
    // constrained route parameter).
    Route::prefix('app/my-leave')->name('app.my-leave.')->group(function (): void {
        Route::get('/', [StaffSelfServiceController::class, 'leave'])->name('index');
        Route::post('/requests', [StaffSelfServiceController::class, 'submitLeave'])->name('requests.store');
        Route::get('/requests/{leaveRequest}', [StaffSelfServiceController::class, 'leaveRequest'])->name('requests.show');
        foreach (['withdraw', 'cancel'] as $action) {
            Route::post("/requests/{leaveRequest}/{$action}", [StaffSelfServiceController::class, 'decideLeave'])->defaults('action', $action)->name("requests.{$action}");
        }
    });
    Route::get('/app/my-staff-attendance', [StaffSelfServiceController::class, 'attendance'])->name('app.my-staff-attendance.index');
    Route::get('/app/my-payslips', [StaffSelfServiceController::class, 'payslips'])->name('app.my-payslips.index');
    Route::get('/app/my-payslips/{payrollRun}/{employmentRecord}', [StaffSelfServiceController::class, 'payslip'])->name('app.my-payslips.show');

    // HRX.3 (ADR 0065 §24.15) -- Staff Attendance administration: the daily
    // register (single record, bulk save, correction) and an employment's
    // history. Capability-checked in the controller AND again in the
    // services. No self-service, Teacher or clock page.
    Route::prefix('app/staff-attendance')->name('app.staff-attendance.')->group(function (): void {
        Route::get('/', [StaffAttendanceAdminController::class, 'register'])->name('register');
        Route::post('/register', [StaffAttendanceAdminController::class, 'storeRegister'])->name('register.store');
        Route::get('/history', [StaffAttendanceAdminController::class, 'history'])->name('history');
        // ORG.9C-1 (ADR 0076 §69), HR-003: a month's half-day counts, read-only (hr.staff_attendance.view).
        Route::get('/monthly', [StaffAttendanceAdminController::class, 'monthly'])->name('monthly');
        Route::post('/records', [StaffAttendanceAdminController::class, 'store'])->name('records.store');
        Route::post('/records/{staffAttendanceRecord}/corrections', [StaffAttendanceAdminController::class, 'correct'])->name('records.corrections.store');
    });

    // Phase 0L.2-1 -- Analytics (ADR 0040). One read-only report; the
    // `analytics.view` check, the fail-closed cohort policy, tenancy
    // and audit all live in App\Domain\Analytics\Application\AnalyticsReadGate.
    // No export route (not in this checkpoint) and no API.
    Route::get('/app/analytics/curriculum-coverage', [CurriculumCoverageController::class, 'index'])->name('app.analytics.curriculum-coverage');

    // Phase 0L.4 -- Compliance (ADR 0042): the School audit-log review.
    // Read-only; `school.audit.view`, the ledger read contract and the
    // access audit all live in App\Domain\Compliance\Application\AuditLogReviewService.
    // ORG.9B (ADR 0076 §68): narrowing filters (event type/family, subject,
    // School-local dates); still no export and no API.
    Route::get('/app/compliance/audit-log', [AuditLogController::class, 'index'])->name('app.compliance.audit-log');

    // Phase 0L.6 -- Automation (ADR 0043): one catalog rule type, School
    // opt-in, accountable owner. `automation.view`/`automation.manage` are
    // enforced in the Automation Application services. No manual run.
    Route::get('/app/automation', [AutomationController::class, 'index'])->name('app.automation');

    // V11.5 (ADR 0075 section 10): the read-only Data Health hub. GET only;
    // every correction happens in the owning module's existing page.
    Route::get('/app/data-health', [DataHealthController::class, 'index'])->name('app.data-health.index');
    // No route pattern on {check}: School-context middleware must run first
    // (SchoolContextRouteGuardTest); an unknown code is a 404 in the controller.
    Route::get('/app/data-health/{check}', [DataHealthController::class, 'show'])->name('app.data-health.show');
    Route::post('/app/automation/rules/{ruleType}/enable', [AutomationController::class, 'enable'])->name('app.automation.rules.enable');
    Route::post('/app/automation/rules/{ruleType}/disable', [AutomationController::class, 'disable'])->name('app.automation.rules.disable');
    Route::post('/app/automation/rules/{ruleType}/take-ownership', [AutomationController::class, 'takeOwnership'])->name('app.automation.rules.take-ownership');

    // TCH.5C (ADR 0063 section 36) -- "My Learning Content": the owned
    // teacher surface. Authorization (lms.content.teacher + ActingEmployee +
    // owner/audience + today's TeachingAssignments) lives in
    // TeacherLearningContentAccess and the service's write guard.
    Route::prefix('app/my-learning-content')->name('app.my-learning-content.')->group(function (): void {
        Route::get('/', [MyLearningContentController::class, 'index'])->name('index');
        Route::post('/', [MyLearningContentController::class, 'store'])->name('store');
        Route::patch('/{learningContent}', [MyLearningContentController::class, 'update'])->name('update');
        Route::post('/{learningContent}/publish', [MyLearningContentController::class, 'publish'])->name('publish');
        Route::post('/{learningContent}/archive', [MyLearningContentController::class, 'archive'])->name('archive');
    });

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

    // TCH.5D (ADR 0063 section 37) -- "My Assignments": the owned teacher
    // Assignment surface (the "My Learning Content" pattern). Authorization
    // lives in TeacherAssignmentAccess and the service's write guard.
    Route::prefix('app/my-assignments')->name('app.my-assignments.')->group(function (): void {
        Route::get('/', [MyAssignmentController::class, 'index'])->name('index');
        Route::post('/', [MyAssignmentController::class, 'store'])->name('store');
        Route::patch('/{assignment}', [MyAssignmentController::class, 'update'])->name('update');
        Route::post('/{assignment}/publish', [MyAssignmentController::class, 'publish'])->name('publish');
        Route::post('/{assignment}/close', [MyAssignmentController::class, 'close'])->name('close');
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
    // RES.2 (ADR 0068 §4.1, §9, §19): the per-paper StudentMark surface --
    // session JSON only (bearer tokens carry no MFA assurance, ADR 0049), each
    // route composing its examinations.marks.* capability with `mfa`. Exactly
    // the grid read and the atomic batch write; no list, search, export,
    // report, Student-centric or results route. Development only (RES-L1).
    // RES.5 (ADR 0068 §21.6, §27): the paper id is UUID-constrained like every
    // other marks id -- a malformed id is a 404, never a database cast error.
    // RES.5 (ADR 0068 §27): every marks route is development only until RES-L1
    // (E36) -- `marks-development-only`, enforced again in the services.
    Route::prefix('app/examination-papers/{examinationPaper}/marks')->whereUuid('examinationPaper')->name('app.examination-papers.marks.')
        ->middleware('marks-development-only')->group(function (): void {
            Route::get('/', [StudentMarkController::class, 'index'])
                ->middleware(['capability:examinations.marks.view', 'mfa'])
                ->name('index');
            Route::put('/', [StudentMarkController::class, 'update'])
                ->middleware(['capability:examinations.marks.manage', 'mfa'])
                ->name('update');
            // RES.3 (ADR 0068 §7, §21): the one-way per-paper lock (fresh MFA) and
            // the post-lock correction request. No unlock, reopen or bypass route.
            Route::post('/lock', [StudentMarkCorrectionController::class, 'lock'])
                ->middleware(['capability:examinations.marks.lock', 'mfa'])
                ->name('lock');
            Route::post('/{studentMark}/corrections', [StudentMarkCorrectionController::class, 'store'])
                ->whereUuid('studentMark')
                ->middleware(['capability:examinations.marks.correction.request', 'mfa'])
                ->name('corrections.store');
        });

    // RES.4 (ADR 0068 §25): the owned TEACHER marks surface -- session JSON
    // only (no bearer API), `capability:examinations.marks.teacher` + `mfa` +
    // the development-only block (production refuses it while RES-L2 / E37 and
    // the teacher RES-L0 re-review / E35 are undetermined). Only the read and
    // the batch write of one paper, scoped server-side to the Students the
    // teacher owns on its date; no lock, correction, list or search route. The
    // paper id is a plain uuid (not model-bound): every miss is the same 404.
    // RES.4A (ADR 0068 §26): the teacher's own paper discovery list -- the same
    // block, capability and `mfa`; papers owned on their date, no Student data.
    Route::get('app/my-examination-papers', [TeacherStudentMarkController::class, 'papers'])
        ->middleware(['teacher-marks-development-only', 'marks-development-only', 'capability:examinations.marks.teacher', 'mfa'])
        ->name('app.my-examination-papers.index');
    Route::prefix('app/my-examination-papers/{examinationPaper}/marks')->whereUuid('examinationPaper')
        ->name('app.my-examination-papers.marks.')
        ->middleware(['teacher-marks-development-only', 'marks-development-only', 'capability:examinations.marks.teacher', 'mfa'])
        ->group(function (): void {
            Route::get('/', [TeacherStudentMarkController::class, 'index'])->name('index');
            Route::put('/', [TeacherStudentMarkController::class, 'update'])->name('update');
        });

    // RES.3 (ADR 0068 §7.3, §21): deciding a correction -- someone other than
    // its requester, with a fresh MFA re-verification (controller). Terminal.
    Route::prefix('app/student-mark-corrections/{studentMarkCorrection}')->whereUuid('studentMarkCorrection')
        ->name('app.student-mark-corrections.')->middleware('marks-development-only')->group(function (): void {
            Route::post('/approve', [StudentMarkCorrectionController::class, 'approve'])
                ->middleware(['capability:examinations.marks.correction.approve', 'mfa'])
                ->name('approve');
            Route::post('/reject', [StudentMarkCorrectionController::class, 'reject'])
                ->middleware(['capability:examinations.marks.correction.approve', 'mfa'])
                ->name('reject');
        });

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
        // V11.2 (ADR 0075): before /{attendanceSession}, which would capture it.
        Route::get('/exceptions', [AttendanceExceptionController::class, 'index'])->name('exceptions');
        // ORG.9C-1 (ADR 0076 §69): register status for a date and one Student's
        // records over a range -- read-only, attendance.view under CampusScope.
        Route::get('/registers', [AttendanceOperationsController::class, 'registers'])->name('registers');
        Route::get('/students/{student}', [AttendanceOperationsController::class, 'student'])->name('students.show');
        Route::post('/', [AttendanceController::class, 'store'])->name('store');
        Route::get('/{attendanceSession}', [AttendanceController::class, 'show'])->name('show');
        Route::post('/records/{attendanceRecord}/correct', [AttendanceController::class, 'correct'])->name('correct');
    });

    // TCH.4 (ADR 0063 section 11, Tier 2) -- "My Attendance", the owned
    // teacher pages: only the calling teacher's own registers, classes and
    // rosters. Authorization lives in the controller (TeacherAttendanceAccess)
    // and, for writes, again inside the Attendance services' transactions.
    // PRODUCTION ENABLEMENT is blocked by the open TCH-L1 determination.
    // E33 / TCH-L1 (ADR 0063 section 43): the owned capability and the MFA
    // assurance gate on every My Attendance page and post. MFA never
    // substitutes for ownership -- TeacherAttendanceAccess still decides it.
    Route::prefix('app/my-attendance')->name('app.my-attendance.')->middleware(['capability:attendance.teacher', 'mfa-page'])->group(function (): void {
        Route::get('/', [MyAttendanceController::class, 'index'])->name('index');
        Route::get('/take', [MyAttendanceController::class, 'take'])->name('take');
        Route::post('/', [MyAttendanceController::class, 'store'])->name('store');
        Route::get('/{attendanceSession}', [MyAttendanceController::class, 'show'])->name('show');
        Route::post('/records/{attendanceRecord}/correct', [MyAttendanceController::class, 'correct'])->name('correct');
    });

    Route::prefix('app/timetable-schedule')->name('app.timetable-schedule.')->group(function (): void {
        Route::get('/', [TimetableEntryController::class, 'index'])->name('index');
        Route::get('/create', [TimetableEntryController::class, 'create'])->name('create');
        Route::get('/search/subject-offerings', [TimetableEntryController::class, 'searchSubjectOfferings'])->name('search-subject-offerings');
        Route::get('/search/sections', [TimetableEntryController::class, 'searchSections'])->name('search-sections');
        Route::get('/search/teachers', [TimetableEntryController::class, 'searchTeachers'])->name('search-teachers');
        Route::get('/search/rooms', [TimetableEntryController::class, 'searchRooms'])->name('search-rooms');
        Route::get('/search/periods', [TimetableEntryController::class, 'searchPeriods'])->name('search-periods');
        // ORG.7D-1: the scheduling form's bell-schedule lookup (ADR 0076 §37).
        Route::get('/search/schedule-periods', [TimetableEntryController::class, 'searchSchedulePeriods'])->name('search-schedule-periods');
        Route::post('/', [TimetableEntryController::class, 'store'])->name('store');
        Route::get('/{timetableEntry}/edit', [TimetableEntryController::class, 'edit'])->name('edit');
        Route::patch('/{timetableEntry}', [TimetableEntryController::class, 'update'])->name('update');
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
        Route::post('/runs/{payrollRun}/hrx-inputs/difference-checks', [PayrollRunController::class, 'checkHrxDifferences'])->name('runs.hrx-inputs.difference-checks');
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
