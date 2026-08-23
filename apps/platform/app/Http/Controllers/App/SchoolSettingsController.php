<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0B primitive proving capability-gated read vs. write and
 * server-side denial (section 39) -- NOT the future School Admin UI.
 * Only name/timezone/default_locale are editable; nothing else about
 * a School is exposed here. Two equivalent, valid authorization
 * patterns are demonstrated deliberately: show() is protected by the
 * `capability:` route middleware (routes/web.php,
 * App\Http\Middleware\EnsureCapability); update() uses the
 * AuthorizesCapability controller trait instead -- the pattern to
 * prefer when a route-level check isn't granular enough (e.g.
 * authorizing against a specific resource instance resolved inside the
 * action). Future modules may use either, consistently within a
 * controller.
 */
class SchoolSettingsController extends Controller
{
    use AuthorizesCapability;

    public function show(TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();

        return Inertia::render('App/SchoolSettings', [
            'school' => [
                'id' => $school->id,
                'name' => $school->name,
                'timezone' => $school->timezone,
                'defaultLocale' => $school->default_locale,
            ],
            'canManage' => $capabilities->canInSchool($context->actor(), 'school.settings.manage', $school),
        ]);
    }

    public function update(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $school = $context->requireSchool();

        $this->authorizeCapability('school.settings.manage', $school);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'max:64'],
            'default_locale' => ['required', 'string', 'max:8'],
        ]);

        $before = $school->only(['name', 'timezone', 'default_locale']);
        $school->update($validated);

        $audit->school($school, 'school.settings.updated', subject: $school, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return redirect('/app/settings');
    }
}
