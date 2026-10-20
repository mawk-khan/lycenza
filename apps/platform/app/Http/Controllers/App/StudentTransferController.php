<?php

namespace App\Http\Controllers\App;

use App\Domain\Students\Application\CampusStudentTransferService;
use App\Domain\Students\Application\Exceptions\DuplicateEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\StudentTransferUnavailableException;
use App\Domain\Students\Application\StudentAccess;
use App\Http\Controllers\Controller;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ORG.7F-3 (ADR 0076 §47): the Campus transfer of a Student's CURRENT
 * placement to a Section on another Campus -- enrollments.manage over BOTH
 * Campuses (CampusStudentTransferService). The actor names the Student, never
 * an Enrollment row; destinations come from the purpose-limited
 * StudentAccess::transferDestinations() lookup. The Enrollment-based transfer
 * pages (any Section, any date) stay a declared whole-School operation.
 */
class StudentTransferController extends Controller
{
    use AuthorizesCampusScope;

    public function create(TenantContext $context, StudentAccess $access, string $student): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('enrollments.manage', $school);
        $model = $access->student($school, $scope, $student);
        $placement = $access->currentPlacements($school, $scope, [$model->id])->get($model->id);
        abort_if($placement === null, 404);

        return Inertia::render('App/Students/Transfer', [
            'student' => [
                'id' => $model->id,
                'studentNumber' => $model->student_number,
                'firstName' => $model->first_name,
                'middleName' => $model->middle_name,
                'lastName' => $model->last_name,
            ],
            'currentPlacement' => [
                'campus' => $placement->campus?->name,
                'gradeLevel' => $placement->gradeLevel?->name,
                'section' => $placement->section?->name,
                'rollNumber' => $placement->roll_number,
            ],
            'destinations' => $access->transferDestinations($school, $scope, $placement),
        ]);
    }

    public function store(Request $request, TenantContext $context, CampusStudentTransferService $transfers, string $student): RedirectResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('enrollments.manage', $school);

        $validated = $request->validate([
            'target_section_id' => ['required', 'uuid'],
            'roll_number' => ['required', 'string', 'max:255'],
        ]);

        try {
            $transfers->transfer($school, $scope, $student, $validated['target_section_id'], $validated['roll_number'], $context->actor());
        } catch (StudentTransferUnavailableException $e) {
            throw ValidationException::withMessages(['target_section_id' => [$e->getMessage()]]);
        } catch (DuplicateEnrollmentRollNumberException|InvalidEnrollmentRollNumberException $e) {
            throw ValidationException::withMessages(['roll_number' => [$e->getMessage()]]);
        }

        return redirect("/app/students/{$student}/overview");
    }
}
