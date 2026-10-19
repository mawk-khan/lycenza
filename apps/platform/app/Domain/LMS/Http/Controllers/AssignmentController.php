<?php

namespace App\Domain\LMS\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\LMS\Application\AssignmentService;
use App\Domain\LMS\Application\LmsAdministrationAccess;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 0I.3 -- the Assignment administrative API. Exactly SIX
 * operations: list/create nested under the owning SubjectOffering,
 * show/update flat, publish/close as dedicated action routes --
 * structurally identical to
 * App\Domain\LMS\Http\Controllers\LearningContentController.
 *
 * Deliberately NO delete route (status-based retirement only, rule 73)
 * and NO Submission route of any kind -- Submission remains a future,
 * separately-gated checkpoint (ADR 0039 §4).
 *
 * Thin controller (CLAUDE.md rule 3): validates/resolves input, calls
 * `AssignmentService`, presents the response. Every write goes through
 * the service -- this controller never touches the model directly
 * (proven by Tests\Feature\LMS\AssignmentArchitectureGuardTest).
 *
 * Data-minimization note: an Assignment stores no Student, Guardian,
 * Employee or teacher identity at all, which is precisely why it is
 * classified Confidential rather than Sensitive
 * (docs/security/DATA-CLASSIFICATION.md). Nothing in this controller
 * may introduce a mark/score/grade field either.
 */
class AssignmentController extends Controller
{
    use AuthorizesCampusScope;

    public function index(Request $request, School $school, string $subjectOffering, LmsAdministrationAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('lms.assignments.view', $school);

        $offering = $access->offering($scope, $subjectOffering);

        $assignments = $access->assignments($scope)
            ->where('subject_offering_id', $offering->id)
            ->orderBy('due_on')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $assignments->map(fn (Assignment $a) => $this->present($a))->all(),
        ]);
    }

    public function store(Request $request, School $school, string $subjectOffering, AssignmentService $service, LmsAdministrationAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('lms.assignments.manage', $school);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'due_on' => ['nullable', 'date'],
        ]);

        $assignment = $service->create($school, $access->offering($scope, $subjectOffering)->id, $validated, $request->user());

        return response()->json(['data' => $this->present($assignment)], 201);
    }

    public function show(Request $request, School $school, string $assignment, LmsAdministrationAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('lms.assignments.view', $school);

        $model = $access->assignment($scope, $assignment);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $assignment, AssignmentService $service, LmsAdministrationAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('lms.assignments.manage', $school);

        $model = $access->assignment($scope, $assignment);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'due_on' => ['sometimes', 'nullable', 'date'],
        ]);

        $updated = $service->update($school, $model, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function publish(Request $request, School $school, string $assignment, AssignmentService $service, LmsAdministrationAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('lms.assignments.manage', $school);

        $model = $access->assignment($scope, $assignment);

        $updated = $service->publish($school, $model, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function close(Request $request, School $school, string $assignment, AssignmentService $service, LmsAdministrationAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('lms.assignments.manage', $school);

        $model = $access->assignment($scope, $assignment);

        $updated = $service->close($school, $model, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Assignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'subjectOfferingId' => $assignment->subject_offering_id,
            'title' => $assignment->title,
            'instructions' => $assignment->instructions,
            'dueOn' => $assignment->due_on?->toDateString(),
            'status' => $assignment->status,
        ];
    }
}
