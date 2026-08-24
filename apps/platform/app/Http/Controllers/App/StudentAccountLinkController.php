<?php

namespace App\Http\Controllers\App;

use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Identity\Application\Exceptions\AccountLinkException;
use App\Domain\Identity\Infrastructure\StudentGuardianAccountLink;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\SchoolMembership;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5B.2 -- the Student account-link admin actions, matching
 * App\Http\Controllers\App\StudentController's own session-authenticated
 * Inertia+JSON admin-UI convention. Every write delegates to
 * App\Domain\Identity\Application\AccountLinkService -- Identity owns
 * this, not Communications (docs/communication-hub/
 * PHASE-5B-2-STUDENT-GUARDIAN-ACCOUNT-LINK-INAPP.md). Reuses
 * `students.manage` -- no new capability key (root CLAUDE.md rule 24:
 * a capability check, never a bespoke one invented per feature when an
 * existing one already covers "manage this Student's identity data").
 */
class StudentAccountLinkController extends Controller
{
    use AuthorizesCapability;

    /**
     * Same-School, `active` SchoolMembership candidates, excluding
     * any already carrying a different active persona link -- never
     * cross-School data, never every User loaded into the browser
     * (brief §30).
     */
    public function search(Request $request, TenantContext $context, string $student): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('students.manage', $school);
        Student::query()->findOrFail($student);

        $q = trim((string) $request->string('q'));

        // Deliberately a plain id-list exclusion, not an Eloquent
        // relation on SchoolMembership (root CLAUDE.md rule 4 -- a
        // central model must not reach into a Domain module's
        // Eloquent models).
        $alreadyLinkedMembershipIds = StudentGuardianAccountLink::query()
            ->where('school_id', $school->id)
            ->active()
            ->pluck('school_membership_id');

        $memberships = SchoolMembership::query()
            ->where('school_id', $school->id)
            ->active()
            ->whereNotIn('id', $alreadyLinkedMembershipIds)
            ->whereHas('user', fn ($query) => $q === '' ? $query : $query->where('name', 'ilike', "%{$q}%"))
            ->with('user:id,name')
            ->orderBy('user_id')
            ->limit(20)
            ->get();

        return response()->json([
            'candidates' => $memberships->map(fn (SchoolMembership $m) => [
                'schoolMembershipId' => $m->id,
                'name' => $m->user->name,
            ])->values(),
        ]);
    }

    public function store(Request $request, TenantContext $context, AccountLinkService $service, string $student): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('students.manage', $school);

        $model = Student::query()->findOrFail($student);

        $validated = $request->validate([
            'school_membership_id' => ['required', 'string'],
        ]);

        $membership = SchoolMembership::query()->find($validated['school_membership_id']);

        if ($membership === null) {
            throw ValidationException::withMessages(['school_membership_id' => ['That School OS account could not be found.']]);
        }

        try {
            $service->linkStudent($school, $model, $membership, $context->actor());
        } catch (AccountLinkException $e) {
            throw ValidationException::withMessages(['school_membership_id' => [$e->getMessage()]]);
        }

        return redirect("/app/students/{$model->id}");
    }

    public function destroy(TenantContext $context, AccountLinkService $service, string $student): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('students.manage', $school);

        $model = Student::query()->findOrFail($student);

        try {
            $service->unlinkStudent($school, $model, $context->actor());
        } catch (AccountLinkException $e) {
            throw ValidationException::withMessages(['school_membership_id' => [$e->getMessage()]]);
        }

        return redirect("/app/students/{$model->id}");
    }
}
