<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Application\Concerns\AdministersReferenceData;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearClosedForStructureException;
use App\Domain\AcademicStructure\Events\SectionCreated;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Campuses\Application\ActiveCampus;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SWC.2 (ADR 0073 section 4.2): Section create/update, moved from the API
 * controller so the web pages and `/api/v1` share it. A Section belongs to
 * exactly one Academic Year (rule 66); its code is unique per (Year, Campus,
 * Grade Level); capacity is advisory; deactivation is reversible `status`.
 *
 * Correction (SWC.2): update() now normalizes the code and checks the same
 * composite uniqueness as create(). Before, a duplicate code on PATCH reached
 * the database constraint as a 500.
 */
class SectionAdministrationService
{
    use AdministersReferenceData;

    public const string CAPABILITY_MANAGE = 'academics.structure.manage';

    private const string DUPLICATE_CODE = 'A Section with this code already exists for this Grade, Campus, and Academic Year.';

    public const string INACTIVE_CAMPUS = 'This Section is on an inactive Campus and cannot be reactivated.';

    /** SIS 11.3: a closed year's Section capacity is history (sections_capacity_closed_year_guard). */
    public const string CLOSED_YEAR_CAPACITY = 'This Section belongs to a closed academic year, so its capacity is history and cannot be changed.';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly ActiveCampus $activeCampus,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function create(School $school, string $academicYearId, array $input, User $actor): Section
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $academicYearId, $input, $actor): Section {
            $year = AcademicYear::query()->findOrFail($academicYearId);
            $validated = $this->validateInput($input, [
                'campus_id' => ['required', 'string'],
                'grade_level_id' => ['required', 'string'],
                'name' => ['required', 'string', 'max:255'],
                'code' => ['required', 'string', 'max:32'],
                'capacity' => ['nullable', 'integer', 'min:1'],
            ]);

            // Another School's Campus/Grade is invisible under SchoolScope:
            // the same 404 as a missing one, never a raw FK violation.
            Campus::query()->findOrFail($validated['campus_id']);
            GradeLevel::query()->findOrFail($validated['grade_level_id']);

            $assertUnique = fn () => $this->assertCodeAvailable($year->id, $validated['campus_id'], $validated['grade_level_id'], $validated['code'], null);
            $assertUnique();

            return $this->writeUnique(fn (): Section => DB::transaction(function () use ($school, $year, $validated, $actor): Section {
                // SIS 5.2: a closed year is historical -- it keeps its Sections
                // but takes no new one. Read FOR SHARE so a concurrent close
                // waits or is seen. A draft or active year still takes one.
                $status = AcademicYear::query()->whereKey($year->id)->sharedLock()->value('status');
                if ($status === 'closed') {
                    throw new AcademicYearClosedForStructureException($year->name, 'Section');
                }

                // ORG.7D-0 R5: no new Section on an inactive Campus.
                $this->activeCampus->require($school, $validated['campus_id']);

                $section = Section::query()->create([
                    ...$validated,
                    'school_id' => $school->id,
                    'academic_year_id' => $year->id,
                ]);

                $this->audit->school($school, 'section.created', actor: $actor, subject: $section, metadata: [
                    'academicYearId' => $year->id,
                    'name' => $section->name,
                ]);

                event(new SectionCreated($school->id, $section->id, $year->id, $section->grade_level_id, $section->name));

                return $section;
            }), $assertUnique);
        });
    }

    /** @param  array<string, mixed>  $input */
    public function update(School $school, string $sectionId, array $input, User $actor): Section
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $sectionId, $input, $actor): Section {
            $section = Section::query()->findOrFail($sectionId);
            $validated = $this->validateInput($input, [
                'name' => ['sometimes', 'string', 'max:255'],
                'code' => ['sometimes', 'string', 'max:32'],
                'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'status' => ['sometimes', 'in:active,inactive'],
            ]);

            $assertUnique = fn () => array_key_exists('code', $validated)
                ? $this->assertCodeAvailable($section->academic_year_id, $section->campus_id, $section->grade_level_id, $validated['code'], $section->id)
                : null;
            $assertUnique();

            $before = $section->only(array_keys($validated));

            $this->writeUnique(fn () => DB::transaction(function () use ($school, $section, $validated, $before, $actor): void {
                // SIS 11.3 (OD-SIS-6): capacity is advisory -- it may be set below
                // the current strength -- but a closed year's is history. The
                // year is read FOR SHARE so a concurrent close waits or is seen.
                if (array_key_exists('capacity', $validated) && $validated['capacity'] !== $section->capacity
                    && AcademicYear::query()->whereKey($section->academic_year_id)->sharedLock()->value('status') === 'closed') {
                    throw ValidationException::withMessages(['capacity' => [self::CLOSED_YEAR_CAPACITY]]);
                }

                // ORG.7D-1 D1-2 (ADR 0076 §37): reactivating puts the Section
                // back into operation, so its Campus must be active. Data
                // validity only; an already-active Section is unaffected.
                if (($validated['status'] ?? null) === 'active' && $section->status !== 'active'
                    && $this->activeCampus->find($school, $section->campus_id) === null) {
                    throw ValidationException::withMessages(['status' => [self::INACTIVE_CAMPUS]]);
                }

                $section->update($validated);

                $this->audit->school($school, 'section.updated', actor: $actor, subject: $section, metadata: [
                    'before' => $before,
                    'after' => $validated,
                ]);
            }), $assertUnique);

            return $section->refresh();
        });
    }

    private function assertCodeAvailable(string $yearId, string $campusId, string $gradeLevelId, string $code, ?string $ignoreId): void
    {
        $taken = Section::query()
            ->where('academic_year_id', $yearId)
            ->where('campus_id', $campusId)
            ->where('grade_level_id', $gradeLevelId)
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => [self::DUPLICATE_CODE]]);
        }
    }
}
