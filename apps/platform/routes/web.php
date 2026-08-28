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
use App\Http\Controllers\App\AdmissionApplicationController;
use App\Http\Controllers\App\ApplicantController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\EnrollmentRolloverController;
use App\Http\Controllers\App\EnrollmentRolloverItemController;
use App\Http\Controllers\App\EnrollmentRolloverMappingController;
use App\Http\Controllers\App\GuardianAccountInvitationController;
use App\Http\Controllers\App\GuardianAccountLinkController;
use App\Http\Controllers\App\GuardianCommunicationPreferenceController;
use App\Http\Controllers\App\GuardianController;
use App\Http\Controllers\App\HostelController;
use App\Http\Controllers\App\HostelResidencyController;
use App\Http\Controllers\App\HostelRoomController;
use App\Http\Controllers\App\LibraryCatalogueController;
use App\Http\Controllers\App\LibraryCirculationController;
use App\Http\Controllers\App\SchoolSettingsController;
use App\Http\Controllers\App\SchoolSetupController;
use App\Http\Controllers\App\SchoolSwitchController;
use App\Http\Controllers\App\StudentAccountLinkController;
use App\Http\Controllers\App\StudentController;
use App\Http\Controllers\App\StudentEnrollmentController;
use App\Http\Controllers\App\StudentGuardianRelationshipController;
use App\Http\Controllers\App\TransportOperationsController;
use App\Http\Controllers\App\TransportRouteController;
use App\Http\Controllers\App\TransportStudentAssignmentController;
use App\Http\Controllers\App\TransportVehicleController;
use App\Http\Controllers\App\VisitorController;
use App\Http\Controllers\App\VisitorVisitController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Identity\InvitationAcceptanceController;
use App\Http\Controllers\SystemStatusController;
use Illuminate\Support\Facades\Route;

// Unauthenticated Phase 0A primitive -- unchanged.
Route::get('/', [SystemStatusController::class, 'index'])->name('system.status');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

// Phase 5D.3 -- the Guardian account-invitation acceptance page.
// Deliberately NOT wrapped in 'guest' (an existing User must be able
// to view/confirm this page while already authenticated -- brief
// §11-13's existing-account branch) nor 'auth' (a brand-new person has
// no account yet). See InvitationAcceptanceController's docblock for
// how tenant context is resolved for an RLS-protected lookup without
// either.
Route::middleware('throttle:guardian-invitation-accept')->group(function (): void {
    Route::get('/invitations/{school}/{token}', [InvitationAcceptanceController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{school}/{token}', [InvitationAcceptanceController::class, 'store'])->name('invitations.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/app', [DashboardController::class, 'index'])->name('app.dashboard');

    Route::post('/app/schools/{school}/activate', [SchoolSwitchController::class, 'store'])
        ->name('app.schools.activate');

    Route::get('/app/settings', [SchoolSettingsController::class, 'show'])
        ->middleware('capability:school.settings.view')
        ->name('app.settings.show');

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
    // Mapping delete / Plan cancellation routes exist -- neither has a
    // sanctioned Application-service operation (Phase 1B.7E).
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

        Route::patch('/{rollover}/items/{item}', [EnrollmentRolloverItemController::class, 'update'])->name('items.update');
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
});
