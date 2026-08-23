<?php

use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\GuardianController;
use App\Http\Controllers\App\SchoolSettingsController;
use App\Http\Controllers\App\SchoolSetupController;
use App\Http\Controllers\App\SchoolSwitchController;
use App\Http\Controllers\App\StudentController;
use App\Http\Controllers\App\StudentGuardianRelationshipController;
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
    });

    Route::prefix('app/contacts')->name('app.contacts.')->group(function (): void {
        Route::post('/{contact}/primary', [GuardianController::class, 'setPrimaryContact'])->name('primary');
        Route::post('/{contact}/deactivate', [GuardianController::class, 'deactivateContact'])->name('deactivate');
    });
});
