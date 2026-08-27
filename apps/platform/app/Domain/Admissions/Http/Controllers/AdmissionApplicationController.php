<?php

namespace App\Domain\Admissions\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Application\AdmissionApplicationReadService;
use App\Domain\Admissions\Application\AdmissionApplicationService;
use App\Domain\Admissions\Application\AdmissionConversionResult;
use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Admissions\Application\GuardianConversionInstruction;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Phase 1D.5: the administrative HTTP boundary for AdmissionApplication
 * -- creation, lifecycle transitions, and accepted-application
 * conversion. Thin by construction, mirroring
 * StudentEnrollmentController's exact shape: every mutation delegates
 * to App\Domain\Admissions\Application\AdmissionApplicationService
 * (Phase 1D.2) or App\Domain\Admissions\Application\
 * AdmissionConversionService (Phase 1D.3) -- the sole sanctioned write
 * paths -- and every read delegates to
 * App\Domain\Admissions\Application\AdmissionApplicationReadService
 * (Phase 1D.4). No lifecycle/compatibility/conversion rule is
 * duplicated here.
 *
 * Every domain exception these services can raise
 * (`InvalidAdmissionApplicationTransitionException`,
 * `AdmissionApplicationAlreadyConvertedException`,
 * `IncompatibleConversionSectionException`,
 * `OpenAdmissionApplicationExistsException`,
 * `AdmissionGuardianSelectionRequiredException`,
 * `CrossSchoolAdmissionReferenceException`,
 * `CrossSchoolRelationshipException`,
 * `DuplicateStudentNumberException`,
 * `DuplicateEnrollmentRollNumberException`) already extends a base
 * class implementing `getStatusCode()`/`errorCode()` -- rendered
 * automatically by bootstrap/app.php's generic `/api/*` exception
 * envelope (docs/architecture/API.md "Error format"), exactly like
 * `StudentController::store()`'s `DuplicateStudentNumberException`.
 * NONE of these are caught here. Only the two failure modes with no
 * such contract (`InvalidArgumentException` from
 * `EmailNormalizer`/`PhoneNormalizer`, `UniqueConstraintViolationException`
 * from a raw contact-uniqueness race) are translated to
 * `ValidationException`, mirroring `GuardianContactController::store()`'s
 * identical two-catch-block pattern exactly.
 */
class AdmissionApplicationController extends Controller
{
    use AuthorizesCapability;

    // --- Directory / detail --------------------------------------------

    public function index(Request $request, School $school, AdmissionApplicationReadService $reads): JsonResponse
    {
        $this->authorizeCapability('admissions.view', $school);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['draft', 'submitted', 'accepted', 'rejected', 'withdrawn', 'converted'])],
            'academic_year_id' => ['sometimes', 'uuid'],
            'campus_id' => ['sometimes', 'uuid'],
            'grade_level_id' => ['sometimes', 'uuid'],
            'applicant_name' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = $validated['per_page'] ?? 25;
        $filters = collect($validated)->except('per_page')->all();

        $paginator = $reads->directory($filters, $perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (AdmissionApplication $a) => $this->presentSummary($a))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(School $school, string $admissionApplication, AdmissionApplicationReadService $reads): JsonResponse
    {
        $this->authorizeCapability('admissions.view', $school);

        $model = $reads->detail($admissionApplication);
        abort_if($model === null, 404);

        return response()->json(['data' => $this->presentDetail($model)]);
    }

    // --- Create -------------------------------------------------------

    /**
     * Always begins as `draft` -- no explicit `status` is ever
     * accepted from the caller (`AdmissionApplicationService::create()`
     * has no such parameter at all). Every reference is validated
     * same-School at the HTTP boundary via `Rule::exists(...)->where(
     * 'school_id', ...)`, matching `StudentEnrollmentController::store()`'s
     * identical `section_id` validation -- a foreign-School reference
     * 422s here before the domain service's own
     * `CrossSchoolAdmissionReferenceException` check is ever reached.
     */
    public function store(Request $request, School $school, AdmissionApplicationService $service): JsonResponse
    {
        $this->authorizeCapability('admissions.manage', $school);

        $validated = $request->validate([
            'applicant_id' => ['required', 'uuid', Rule::exists('applicants', 'id')->where('school_id', $school->id)],
            'academic_year_id' => ['required', 'uuid', Rule::exists('academic_years', 'id')->where('school_id', $school->id)],
            'campus_id' => ['required', 'uuid', Rule::exists('campuses', 'id')->where('school_id', $school->id)],
            'grade_level_id' => ['required', 'uuid', Rule::exists('grade_levels', 'id')->where('school_id', $school->id)],
        ]);

        $applicant = Applicant::query()->findOrFail($validated['applicant_id']);
        $academicYear = AcademicYear::query()->findOrFail($validated['academic_year_id']);
        $campus = Campus::query()->findOrFail($validated['campus_id']);
        $gradeLevel = GradeLevel::query()->findOrFail($validated['grade_level_id']);

        $application = $service->create($applicant, $academicYear, $campus, $gradeLevel, $request->user());

        return response()->json(['data' => $this->presentDetail($application->load(['applicant', 'academicYear', 'campus', 'gradeLevel']))], 201);
    }

    // --- Lifecycle ------------------------------------------------------

    /**
     * `draft` -> `submitted`. One explicit action, never a generic
     * `PATCH { status }` (root CLAUDE.md's illegal-transition-bypass
     * concern -- the state machine owns its own transitions).
     */
    public function submit(Request $request, School $school, string $admissionApplication, AdmissionApplicationService $service): JsonResponse
    {
        $this->authorizeCapability('admissions.manage', $school);

        $model = AdmissionApplication::query()->findOrFail($admissionApplication);

        $updated = $service->submit($model, $request->user());

        return response()->json(['data' => $this->presentDetail($updated->load(['applicant', 'academicYear', 'campus', 'gradeLevel']))]);
    }

    /**
     * `submitted` -> `accepted`. Never triggers conversion -- accept
     * and convert remain distinct commands (`ADMISSIONS.md` §7).
     */
    public function accept(Request $request, School $school, string $admissionApplication, AdmissionApplicationService $service): JsonResponse
    {
        $this->authorizeCapability('admissions.manage', $school);

        $model = AdmissionApplication::query()->findOrFail($admissionApplication);

        $validated = $request->validate(['decision_note' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        $updated = $service->accept($model, $validated['decision_note'] ?? null, $request->user());

        return response()->json(['data' => $this->presentDetail($updated->load(['applicant', 'academicYear', 'campus', 'gradeLevel']))]);
    }

    /**
     * `submitted` -> `rejected`. No hard delete -- terminal history is
     * always preserved.
     */
    public function reject(Request $request, School $school, string $admissionApplication, AdmissionApplicationService $service): JsonResponse
    {
        $this->authorizeCapability('admissions.manage', $school);

        $model = AdmissionApplication::query()->findOrFail($admissionApplication);

        $validated = $request->validate(['decision_note' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        $updated = $service->reject($model, $validated['decision_note'] ?? null, $request->user());

        return response()->json(['data' => $this->presentDetail($updated->load(['applicant', 'academicYear', 'campus', 'gradeLevel']))]);
    }

    /**
     * `submitted` -> `withdrawn` or `accepted` -> `withdrawn`. No
     * `decision_note` parameter -- `AdmissionApplicationService::withdraw()`
     * never touches it, preserving an accepted application's decision
     * note unchanged (`ADMISSIONS.md` §6).
     */
    public function withdraw(Request $request, School $school, string $admissionApplication, AdmissionApplicationService $service): JsonResponse
    {
        $this->authorizeCapability('admissions.manage', $school);

        $model = AdmissionApplication::query()->findOrFail($admissionApplication);

        $updated = $service->withdraw($model, $request->user());

        return response()->json(['data' => $this->presentDetail($updated->load(['applicant', 'academicYear', 'campus', 'gradeLevel']))]);
    }

    // --- Conversion -------------------------------------------------------

    /**
     * `accepted` -> `converted`. `guardian` is a small, closed,
     * two-mode request shape mirroring
     * `GuardianConversionInstruction` exactly (`create`/`link_existing`)
     * -- omitting the `guardian` key entirely, or sending it as `null`,
     * is the ONE canonical HTTP representation of "no Guardian"; both
     * map identically to a `null` `GuardianConversionInstruction`
     * (`ADMISSIONS.md` §11's optionality).
     *
     * `section_id`/`guardian.guardian_id` are validated same-School at
     * the HTTP boundary (`Rule::exists(...)->where('school_id', ...)`)
     * -- a foreign-School value 422s here, before any canonical record
     * is created, matching `StudentEnrollmentController::store()`'s
     * identical `section_id` pattern. `AdmissionConversionService`
     * remains the sole authority for Section/AcademicYear/Campus/
     * GradeLevel COMPATIBILITY (not just same-School) -- that algorithm
     * is never duplicated here.
     *
     * Guardian contact input (`guardian.contact_type`/`.contact_value`)
     * is transient HTTP request input only -- passed straight through
     * to the conversion service, never persisted by this controller or
     * by Admissions (`ADMISSIONS.md` §9). No encryption/HMAC/
     * normalization logic lives here; `GuardianContactService` remains
     * the sole authority, exactly like `GuardianContactController::store()`.
     */
    public function convert(Request $request, School $school, string $admissionApplication, AdmissionConversionService $service): JsonResponse
    {
        $this->authorizeCapability('admissions.manage', $school);

        $model = AdmissionApplication::query()->findOrFail($admissionApplication);

        $validated = $request->validate([
            'student_number' => ['required', 'string', 'max:255'],
            'section_id' => ['required', 'uuid', Rule::exists('sections', 'id')->where('school_id', $school->id)],
            'roll_number' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],

            'guardian' => ['sometimes', 'nullable', 'array'],
            'guardian.mode' => ['required_with:guardian', Rule::in(['create', 'link_existing'])],
            'guardian.relationship_type' => ['required_with:guardian', Rule::enum(RelationshipType::class)],
            'guardian.is_legal_guardian' => ['sometimes', 'boolean'],
            'guardian.is_emergency_contact' => ['sometimes', 'boolean'],
            'guardian.is_authorized_pickup' => ['sometimes', 'boolean'],
            // create mode only:
            'guardian.first_name' => ['required_if:guardian.mode,create', 'string', 'max:255'],
            'guardian.middle_name' => ['nullable', 'string', 'max:255'],
            'guardian.last_name' => ['nullable', 'string', 'max:255'],
            'guardian.contact_type' => ['sometimes', 'nullable', Rule::enum(ContactType::class)],
            'guardian.contact_value' => ['required_with:guardian.contact_type', 'nullable', 'string', 'max:255'],
            // link_existing mode only:
            'guardian.guardian_id' => ['required_if:guardian.mode,link_existing', 'uuid', Rule::exists('guardians', 'id')->where('school_id', $school->id)],
        ]);

        $section = Section::query()->findOrFail($validated['section_id']);
        $guardianInstruction = $this->buildGuardianInstruction($validated['guardian'] ?? null);

        try {
            $result = $service->convert(
                $model,
                $validated['student_number'],
                $section,
                $validated['roll_number'],
                $validated['starts_on'],
                $guardianInstruction,
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            // EmailNormalizer/PhoneNormalizer reject malformed contact
            // input with a plain InvalidArgumentException (Phase 1A.3)
            // -- translated to the established validation response
            // shape, exactly like GuardianContactController::store().
            throw ValidationException::withMessages(['guardian.contact_value' => [$e->getMessage()]]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'guardian.contact_value' => ['This contact conflicts with an existing record for this Guardian.'],
            ]);
        }

        return response()->json(['data' => $this->presentConversionResult($result)]);
    }

    private function buildGuardianInstruction(?array $guardian): ?GuardianConversionInstruction
    {
        if ($guardian === null || $guardian === []) {
            return null;
        }

        $relationshipType = RelationshipType::from($guardian['relationship_type']);
        $isLegalGuardian = $guardian['is_legal_guardian'] ?? false;
        $isEmergencyContact = $guardian['is_emergency_contact'] ?? false;
        $isAuthorizedPickup = $guardian['is_authorized_pickup'] ?? false;

        if ($guardian['mode'] === 'create') {
            return GuardianConversionInstruction::create(
                firstName: $guardian['first_name'],
                middleName: $guardian['middle_name'] ?? null,
                lastName: $guardian['last_name'] ?? null,
                relationshipType: $relationshipType,
                contactType: isset($guardian['contact_type']) ? ContactType::from($guardian['contact_type']) : null,
                contactValue: $guardian['contact_value'] ?? null,
                isLegalGuardian: $isLegalGuardian,
                isEmergencyContact: $isEmergencyContact,
                isAuthorizedPickup: $isAuthorizedPickup,
            );
        }

        // link_existing -- same-School already verified by the
        // Rule::exists() validation above; StudentGuardianRelationshipService::link()
        // remains the authoritative cross-School guard regardless
        // (root CLAUDE.md rule 13, never duplicated further here).
        $existingGuardian = Guardian::query()->findOrFail($guardian['guardian_id']);

        return GuardianConversionInstruction::linkExisting(
            existingGuardian: $existingGuardian,
            relationshipType: $relationshipType,
            isLegalGuardian: $isLegalGuardian,
            isEmergencyContact: $isEmergencyContact,
            isAuthorizedPickup: $isAuthorizedPickup,
        );
    }

    // --- Presentation -----------------------------------------------------

    /**
     * List row -- deliberately excludes `decisionNote` (Phase 1D.4
     * policy: list excludes, detail includes).
     *
     * @return array<string, mixed>
     */
    private function presentSummary(AdmissionApplication $application): array
    {
        return [
            'id' => $application->id,
            'status' => $application->status,
            'applicant' => $this->presentApplicantRef($application->applicant),
            'academicYear' => $this->presentRef($application->academicYear),
            'campus' => $this->presentRef($application->campus),
            'gradeLevel' => $this->presentRef($application->gradeLevel),
            'createdAt' => $application->created_at->toIso8601String(),
            'convertedAt' => $application->converted_at?->toIso8601String(),
        ];
    }

    /**
     * `decisionNote` and conversion provenance IDs (bare ids only --
     * never a broader converted-Student projection, which would
     * re-expose Student PII this endpoint has no business returning;
     * the full Student record remains available via its own
     * `/students/{id}` endpoint) are detail-only.
     *
     * @return array<string, mixed>
     */
    private function presentDetail(AdmissionApplication $application): array
    {
        return [
            ...$this->presentSummary($application),
            'decisionNote' => $application->decision_note,
            'convertedStudentId' => $application->converted_student_id,
            'convertedStudentEnrollmentId' => $application->converted_student_enrollment_id,
            'updatedAt' => $application->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentApplicantRef(Applicant $applicant): array
    {
        return [
            'id' => $applicant->id,
            'firstName' => $applicant->first_name,
            'middleName' => $applicant->middle_name,
            'lastName' => $applicant->last_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentConversionResult(AdmissionConversionResult $result): array
    {
        return [
            'application' => $this->presentDetail($result->application->load(['applicant', 'academicYear', 'campus', 'gradeLevel'])),
            'student' => $this->presentStudentSummary($result->student),
            'enrollment' => $this->presentEnrollmentSummary($result->enrollment->load(['academicYear', 'campus', 'gradeLevel', 'section'])),
            'guardian' => $result->guardian === null ? null : $this->presentGuardianSummary($result->guardian),
            'relationship' => $result->relationship === null ? null : $this->presentRelationshipSummary($result->relationship),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStudentSummary(Student $student): array
    {
        return [
            'id' => $student->id,
            'studentNumber' => $student->student_number,
            'firstName' => $student->first_name,
            'middleName' => $student->middle_name,
            'lastName' => $student->last_name,
            'status' => $student->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentEnrollmentSummary(StudentEnrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'academicYear' => $this->presentRef($enrollment->academicYear),
            'campus' => $this->presentRef($enrollment->campus),
            'gradeLevel' => $this->presentRef($enrollment->gradeLevel),
            'section' => $this->presentRef($enrollment->section),
            'rollNumber' => $enrollment->roll_number,
            'status' => $enrollment->status,
            'startsOn' => $enrollment->starts_on->toDateString(),
        ];
    }

    /**
     * Never contact info -- staff already supplied whatever they
     * submitted; echoing it back is unnecessary (root CLAUDE.md's
     * contact-minimization principle, this checkpoint's item 70).
     *
     * @return array<string, mixed>
     */
    private function presentGuardianSummary(Guardian $guardian): array
    {
        return [
            'id' => $guardian->id,
            'firstName' => $guardian->first_name,
            'middleName' => $guardian->middle_name,
            'lastName' => $guardian->last_name,
            'status' => $guardian->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRelationshipSummary(StudentGuardianRelationship $relationship): array
    {
        return [
            'id' => $relationship->id,
            'relationshipType' => $relationship->relationship_type->value,
            'isLegalGuardian' => $relationship->is_legal_guardian,
            'isEmergencyContact' => $relationship->is_emergency_contact,
            'isAuthorizedPickup' => $relationship->is_authorized_pickup,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRef(AcademicYear|Campus|GradeLevel|Section $model): array
    {
        return ['id' => $model->id, 'name' => $model->name, 'code' => $model->code];
    }
}
