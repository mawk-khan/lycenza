<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Section 22 (access switching): a user belonging to multiple Schools
 * selects the active one. Requires a real, active membership -- "no
 * access via manually altered IDs" is enforced by re-querying
 * membership here, never trusting a client-supplied school_id alone.
 * Storing the selection resets tenant-sensitive session state
 * (ResolveSchoolContext re-derives TenantContext from this on every
 * subsequent request, it is never cached across the switch).
 */
class SchoolSwitchController extends Controller
{
    public function store(Request $request, School $school, AuditRecorder $audit): RedirectResponse
    {
        $user = $request->user();

        $membership = SchoolMembership::query()
            ->where('user_id', $user->id)
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->first();

        if ($membership === null || ! $school->isActive()) {
            throw ValidationException::withMessages([
                'school' => 'You do not have active access to that School.',
            ]);
        }

        $request->session()->put('active_school_id', $school->id);
        $request->session()->regenerate();

        $audit->platform('school_context.activated', actor: $user, subject: $school);

        return redirect('/app');
    }
}
