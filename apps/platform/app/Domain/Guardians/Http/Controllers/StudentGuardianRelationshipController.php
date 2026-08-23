<?php

namespace App\Domain\Guardians\Http\Controllers;

use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 1A.5: the administrative HTTP boundary for the Student<->Guardian
 * relationship -- delegates every write to
 * App\Domain\Guardians\Application\StudentGuardianRelationshipService
 * (Phase 1A.4), the ONLY sanctioned mutation path. Never
 * `Student::guardians()->attach()`/`sync()`/`syncWithoutDetaching()` --
 * see StudentGuardianRelationshipService's own docblock for why that
 * path is unsafe (Phase 1A.3's regression test proves it fails closed
 * rather than being made safe).
 *
 * Every mutation action requires BOTH `students.manage` AND
 * `guardians.manage` (Phase 1A.4's accepted design -- the operation
 * mutates both domain identities' relationship at once), enforced twice:
 * the `capability:` route middleware in routes/api.php, and the
 * `$this->authorizeCapability()` calls below (defense in depth, matching
 * every other mutation controller in this codebase).
 */
class StudentGuardianRelationshipController extends Controller
{
    use AuthorizesCapability;

    public function index(School $school, string $student): JsonResponse
    {
        $this->authorizeCapability('students.view', $school);

        $studentModel = Student::query()->findOrFail($student);

        $relationships = $studentModel->guardianRelationships()->with('guardian')->get();

        return response()->json(['data' => $relationships->map(fn (StudentGuardianRelationship $r) => $this->present($r))->all()]);
    }

    /**
     * `guardian_id` resolves via `Rule::exists(...)->where('school_id', ...)`
     * -- an invalid OR cross-School id fails validation with the same
     * generic "the selected guardian id is invalid" message either way
     * (this checkpoint's brief, section 23/25: never distinguish
     * "doesn't exist" from "belongs to another School"). Same-School
     * membership is proven twice: here, and again inside
     * StudentGuardianRelationshipService::link() itself
     * (CrossSchoolRelationshipException) -- defense in depth, not
     * redundant dead code, since the service is also callable directly
     * by future non-HTTP callers.
     */
    public function store(Request $request, School $school, string $student, StudentGuardianRelationshipService $service): JsonResponse
    {
        $this->authorizeCapability('students.manage', $school);
        $this->authorizeCapability('guardians.manage', $school);

        $studentModel = Student::query()->findOrFail($student);

        $validated = $request->validate([
            'guardian_id' => ['required', 'uuid', Rule::exists('guardians', 'id')->where('school_id', $school->id)],
            'relationship_type' => ['required', Rule::enum(RelationshipType::class)],
            'is_legal_guardian' => ['sometimes', 'boolean'],
            'is_emergency_contact' => ['sometimes', 'boolean'],
            'is_authorized_pickup' => ['sometimes', 'boolean'],
        ]);

        $guardian = Guardian::query()->findOrFail($validated['guardian_id']);
        $attributes = collect($validated)->only(['is_legal_guardian', 'is_emergency_contact', 'is_authorized_pickup'])->all();

        $relationship = $service->link(
            $studentModel,
            $guardian,
            RelationshipType::from($validated['relationship_type']),
            $attributes,
            $request->user(),
        );

        return response()->json(['data' => $this->present($relationship)], 201);
    }

    public function update(Request $request, School $school, string $relationship, StudentGuardianRelationshipService $service): JsonResponse
    {
        $this->authorizeCapability('students.manage', $school);
        $this->authorizeCapability('guardians.manage', $school);

        $model = StudentGuardianRelationship::query()->findOrFail($relationship);

        $validated = $request->validate([
            'relationship_type' => ['sometimes', Rule::enum(RelationshipType::class)],
            'is_legal_guardian' => ['sometimes', 'boolean'],
            'is_emergency_contact' => ['sometimes', 'boolean'],
            'is_authorized_pickup' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['relationship_type'])) {
            $validated['relationship_type'] = RelationshipType::from($validated['relationship_type']);
        }

        $updated = $service->update($model, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function setPrimary(Request $request, School $school, string $relationship, StudentGuardianRelationshipService $service): JsonResponse
    {
        $this->authorizeCapability('students.manage', $school);
        $this->authorizeCapability('guardians.manage', $school);

        $model = StudentGuardianRelationship::query()->findOrFail($relationship);

        $updated = $service->setPrimary($model, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function destroy(Request $request, School $school, string $relationship, StudentGuardianRelationshipService $service): JsonResponse
    {
        $this->authorizeCapability('students.manage', $school);
        $this->authorizeCapability('guardians.manage', $school);

        $model = StudentGuardianRelationship::query()->findOrFail($relationship);

        $service->unlink($model, $request->user());

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StudentGuardianRelationship $relationship): array
    {
        return [
            'id' => $relationship->id,
            'studentId' => $relationship->student_id,
            'guardian' => [
                'id' => $relationship->guardian->id,
                'firstName' => $relationship->guardian->first_name,
                'middleName' => $relationship->guardian->middle_name,
                'lastName' => $relationship->guardian->last_name,
                'status' => $relationship->guardian->status,
            ],
            'relationshipType' => $relationship->relationship_type->value,
            'isPrimary' => $relationship->is_primary,
            'isLegalGuardian' => $relationship->is_legal_guardian,
            'isEmergencyContact' => $relationship->is_emergency_contact,
            'isAuthorizedPickup' => $relationship->is_authorized_pickup,
        ];
    }
}
