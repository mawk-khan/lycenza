<?php

namespace App\Http\Controllers\App\LMS;

use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\LMS\Application\AssignmentService;
use App\Domain\LMS\Application\Exceptions\LmsException;
use App\Domain\LMS\Application\TeacherAssignmentAccess;
use App\Domain\LMS\Application\TeacherAssignmentReadService;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TCH.5D (ADR 0063 section 37) -- "My Assignments": the session-
 * authenticated OWNED (Tier 2) Assignment page, the "My Learning Content"
 * pattern. It shows only what the teacher may read (filtered in SQL) and the
 * classes they teach today, and writes only through AssignmentService with
 * the teacher guard. A row they may not read is 404 before any validation;
 * one they may read but do not own is 403.
 *
 * Staff-authored Assignments only: no Submission inbox, Student list, mark
 * or feedback (Submission is cancelled, ADR 0039). No attachment UI
 * (attachments stay on the shared Documents API).
 */
class MyAssignmentController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, TeacherAssignmentAccess $access, TeacherAssignmentReadService $reads): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(TeacherAssignmentAccess::CAPABILITY, $school);

        $validated = $request->validate(['subject_offering_id' => ['sometimes', 'uuid']]);

        try {
            $scope = $access->scope($request->user(), $school);
        } catch (ActingEmployeeUnavailableException) {
            $scope = null;
        }

        $contexts = $scope === null ? [] : $reads->contexts($school, $scope);
        $selected = $validated['subject_offering_id'] ?? null;
        if ($selected !== null && ! in_array($selected, array_column($contexts, 'subjectOfferingId'), true)) {
            $selected = null;
        }

        return Inertia::render('App/LMS/Assignments/Mine', [
            'contexts' => $contexts,
            'filters' => ['subjectOfferingId' => $selected ?? ''],
            'assignments' => $scope === null ? [] : $reads->list($school, $scope, $selected, 200)->items(),
            'canAuthor' => $scope !== null,
        ]);
    }

    public function store(Request $request, TenantContext $context, TeacherAssignmentAccess $access, AssignmentService $service): RedirectResponse
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'subject_offering_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'audience_section_ids' => ['required', 'array', 'min:1', 'max:50'],
            'audience_section_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $assignment = $this->guarded(fn () => $service->createOwned($school, $validated['subject_offering_id'], [
            'title' => $validated['title'],
            'instructions' => $validated['instructions'] ?? null,
            'due_on' => $validated['due_on'] ?? null,
        ], array_values($validated['audience_section_ids']), $request->user(), $access->guard($request->user())), 'audience_section_ids');

        return $this->back($assignment, 'Assignment created.');
    }

    public function update(Request $request, TenantContext $context, TeacherAssignmentAccess $access, AssignmentService $service, string $assignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $model = $this->visible($access, $request->user(), $assignment);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $updated = $this->guarded(fn () => $service->update($school, $model, $validated, $request->user(), $access->guard($request->user())), 'title');

        return $this->back($updated, 'Assignment updated.');
    }

    public function publish(Request $request, TenantContext $context, TeacherAssignmentAccess $access, AssignmentService $service, string $assignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $updated = $this->guarded(fn () => $service->publish($school, $this->visible($access, $request->user(), $assignment), $request->user(), $access->guard($request->user())), 'status');

        return $this->back($updated, 'Assignment published.');
    }

    public function close(Request $request, TenantContext $context, TeacherAssignmentAccess $access, AssignmentService $service, string $assignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $updated = $this->guarded(fn () => $service->close($school, $this->visible($access, $request->user(), $assignment), $request->user(), $access->guard($request->user())), 'status');

        return $this->back($updated, 'Assignment closed.');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function guarded(callable $write, string $field): mixed
    {
        try {
            return $write();
        } catch (ActingEmployeeUnavailableException) {
            abort(403);
        } catch (LmsException $e) {
            if ($e->getStatusCode() === 403) {
                abort(403, $e->getMessage());
            }

            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }

    /** A fresh 404 (or 403 for an ineligible actor) before any validation of a row the teacher may not read. */
    private function visible(TeacherAssignmentAccess $access, User $actor, string $assignment): Assignment
    {
        abort_unless(Str::isUuid($assignment), 404);

        try {
            return $access->visible($actor, app(TenantContext::class)->requireSchool(), $assignment);
        } catch (ActingEmployeeUnavailableException) {
            abort(403);
        }
    }

    private function back(Assignment $assignment, string $status): RedirectResponse
    {
        return redirect()
            ->route('app.my-assignments.index', ['subject_offering_id' => $assignment->subject_offering_id])
            ->with('status', $status);
    }
}
