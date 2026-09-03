<?php

namespace App\Domain\Examinations\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Examinations\Application\Exceptions\DuplicateExaminationCodeException;
use App\Domain\Examinations\Application\Exceptions\ExaminationDateOrderException;
use App\Domain\Examinations\Application\Exceptions\ExaminationDateOutsideAcademicYearException;
use App\Domain\Examinations\Infrastructure\Examination;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0H.4A -- the ONE sanctioned write path for `examinations`.
 * Neither the API controller nor the Inertia controller writes the model
 * directly (proven by
 * Tests\Feature\Examinations\ExaminationArchitectureGuardTest).
 *
 * A dedicated Application service is required here, unlike Phase 0H.3A's
 * SyllabusUnit which correctly used a thin controller. CLAUDE.md rule 76
 * names the trigger literally -- "a dedicated Application service is
 * required once real invariants exist (date-range/overlap validation,
 * a multi-step state machine)". An Examination's window must lie inside
 * its parent AcademicYear's own range, which requires a parent lookup
 * and therefore cannot be expressed as a database CHECK. The nearest
 * structural sibling settles it: `AcademicTermService` exists for
 * exactly this shape (a dated subdivision of an AcademicYear with
 * in-range validation).
 *
 * SERVER-DERIVED CONTEXT. Callers supply only the facts a user actually
 * chooses -- `code`, `name`, `starts_on`, `ends_on` and optionally
 * `status`. `school_id` comes from the tenant context and
 * `academic_year_id` from the trusted nested route's resolved parent;
 * neither is ever accepted from request data (CLAUDE.md rules 19/68),
 * and `academic_year_id` is absent from the model's `$fillable` so no
 * mass-assignment path can reach it. An Examination's AcademicYear is
 * fixed at creation and can never be reassigned.
 *
 * NO ACTIVE-YEAR REQUIREMENT. Unlike Curriculum Delivery -- which
 * records what HAS happened and therefore demands an active year --
 * defining a future examination inside a `draft` or otherwise non-active
 * AcademicYear is legitimate planning work and is deliberately allowed.
 *
 * NO OVERLAP CHECK, NO LOCK. Overlapping examination windows are
 * permitted (a School may run "Grade 10 Board Prep" and "Grade 6 Unit
 * Test" in the same weeks), so there is no multi-row invariant here --
 * hence no `TenantLock`, no advisory lock, no `lockForUpdate()`, and no
 * concurrency test. The only race is a duplicate code, settled by
 * `examinations_year_code_ci_unique` alone.
 */
class ExaminationService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{code: string, name: string, starts_on: string, ends_on: string, status?: string}  $attributes
     */
    public function create(School $school, AcademicYear $year, array $attributes, User $actor): Examination
    {
        return $this->context->withSchool($school, function () use ($school, $year, $attributes, $actor) {
            $startsOn = $attributes['starts_on'];
            $endsOn = $attributes['ends_on'];

            $this->assertDateOrder($startsOn, $endsOn);
            $this->assertWithinYear($year, $startsOn, $endsOn);

            return DB::transaction(function () use ($school, $year, $attributes, $startsOn, $endsOn, $actor) {
                $examination = new Examination;
                // `academic_year_id` is force-filled from the resolved
                // route parent, never mass-assigned from input.
                $examination->forceFill([
                    'school_id' => $school->id,
                    'academic_year_id' => $year->id,
                    'code' => $attributes['code'],
                    'name' => $attributes['name'],
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'status' => $attributes['status'] ?? Examination::STATUS_ACTIVE,
                ]);

                $this->save($examination, $attributes['code']);

                // Bounded metadata: ids, code and dates only. `name` is
                // deliberately excluded from audit VALUES throughout
                // this service -- an audit row must not become a second
                // copy of School-authored content
                // (docs/modules/ACADEMICS.md §14's rule, carried
                // forward).
                $this->audit->school($school, 'examinations.examination.created', actor: $actor, subject: $examination, metadata: [
                    'examinationId' => $examination->id,
                    'academicYearId' => $examination->academic_year_id,
                    'code' => $examination->code,
                    'startsOn' => $examination->starts_on->toDateString(),
                    'endsOn' => $examination->ends_on->toDateString(),
                ]);

                return $examination;
            });
        });
    }

    /**
     * Ordinary update. `status` is an ordinary field here -- there is no
     * guarded transition and no lifecycle route, exactly like every
     * other Academic Structure reference entity. `school_id` and
     * `academic_year_id` can never be changed: neither is an accepted
     * attribute and the parent is fixed at creation.
     *
     * @param  array<string, string>  $attributes
     */
    public function update(School $school, Examination $examination, array $attributes, User $actor): Examination
    {
        return $this->context->withSchool($school, function () use ($school, $examination, $attributes, $actor) {
            $startsOn = $attributes['starts_on'] ?? $examination->starts_on->toDateString();
            $endsOn = $attributes['ends_on'] ?? $examination->ends_on->toDateString();

            // Re-run every applicable invariant: an existing row is not
            // a back door around them.
            $this->assertDateOrder($startsOn, $endsOn);

            $year = AcademicYear::query()
                ->where('school_id', $school->id)
                ->findOrFail($examination->academic_year_id);

            $this->assertWithinYear($year, $startsOn, $endsOn);

            $before = [
                'code' => $examination->code,
                'starts_on' => $examination->starts_on->toDateString(),
                'ends_on' => $examination->ends_on->toDateString(),
                'status' => $examination->status,
            ];

            return DB::transaction(function () use ($school, $examination, $attributes, $startsOn, $endsOn, $before, $actor) {
                $examination->forceFill([
                    'code' => $attributes['code'] ?? $examination->code,
                    'name' => $attributes['name'] ?? $examination->name,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'status' => $attributes['status'] ?? $examination->status,
                ]);

                $this->save($examination, $examination->code);

                $after = [
                    'code' => $examination->code,
                    'starts_on' => $examination->starts_on->toDateString(),
                    'ends_on' => $examination->ends_on->toDateString(),
                    'status' => $examination->status,
                ];

                $this->audit->school($school, 'examinations.examination.updated', actor: $actor, subject: $examination, metadata: [
                    'examinationId' => $examination->id,
                    // Field NAMES only for the full change set -- `name`
                    // may appear here...
                    'changedFields' => array_keys($attributes),
                    // ...but its VALUE never does. Only the four
                    // bounded, non-content fields carry before/after
                    // values.
                    'before' => $before,
                    'after' => $after,
                ]);

                return $examination->refresh();
            });
        });
    }

    /**
     * Persists, translating ONLY the specific named unique-index
     * violation into a duplicate-code domain error. Any other unique
     * violation is genuinely unexpected and must stay an unhandled
     * failure rather than being mislabelled -- if
     * `examinations_year_code_ci_unique` were ever renamed, this would
     * stop translating loudly instead of silently misreporting.
     */
    private function save(Examination $examination, string $code): void
    {
        try {
            $examination->save();
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'examinations_year_code_ci_unique')) {
                throw $e;
            }

            throw new DuplicateExaminationCodeException($code);
        }
    }

    private function assertDateOrder(string $startsOn, string $endsOn): void
    {
        if ($endsOn < $startsOn) {
            throw new ExaminationDateOrderException($startsOn, $endsOn);
        }
    }

    /**
     * Both bounds inclusive, mirroring
     * `AcademicTermService::assertWithinYear()` exactly. The
     * AcademicYear's own status is deliberately NOT consulted: a future
     * examination in a draft year is legitimate planning.
     */
    private function assertWithinYear(AcademicYear $year, string $startsOn, string $endsOn): void
    {
        if ($startsOn < $year->starts_on->toDateString()) {
            throw new ExaminationDateOutsideAcademicYearException('start', $startsOn);
        }

        if ($endsOn > $year->ends_on->toDateString()) {
            throw new ExaminationDateOutsideAcademicYearException('end', $endsOn);
        }
    }
}
