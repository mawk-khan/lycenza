<?php

use App\Domain\Communications\Http\Controllers\AnnouncementController;
use App\Domain\Communications\Http\Controllers\CommunicationAttachmentController;
use App\Domain\Communications\Http\Controllers\CommunicationChannelPolicyController;
use App\Domain\Communications\Http\Controllers\CommunicationHubController;
use App\Domain\Communications\Http\Controllers\CommunicationInboxController;
use App\Domain\Communications\Http\Controllers\CommunicationPreferenceController;
use App\Domain\Communications\Http\Controllers\CommunicationTemplateController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\SchoolSettingsController;
use App\Http\Controllers\App\SchoolSetupController;
use App\Http\Controllers\App\SchoolSwitchController;
use App\Http\Controllers\Auth\LoginController;
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
        });

        // Phase 5A.7 §10: registered BEFORE the '/{thread}' wildcard
        // below, same reasoning as 'announcements'/'templates' above.
        Route::get('/participants/search', [CommunicationHubController::class, 'searchParticipants'])->name('participants.search');

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
});
