<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearOverlapException;
use App\Domain\AcademicStructure\Application\Exceptions\ConcurrentActivationConflictException;
use App\Domain\AcademicStructure\Application\Exceptions\InvalidAcademicYearTransitionException;
use App\Domain\AcademicStructure\Events\AcademicYearActivated;
use App\Domain\AcademicStructure\Events\AcademicYearClosed;
use App\Domain\AcademicStructure\Events\AcademicYearCreated;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for Academic Year lifecycle (Phase 0D
 * sections 13-18) -- never write AcademicYear directly from a
 * controller. Every method: validate -> write state -> audit -> emit
 * domain event, inside one transaction (ADR 0025), following the exact
 * pattern App\Support\Settings\SchoolSettingsService established.
 */
class AcademicYearService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{name: string, code: string, starts_on: string, ends_on: string}  $attributes
     */
    public function create(School $school, array $attributes, ?User $actor = null): AcademicYear
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $attributes, $actor) {
            $this->assertNoOverlap($school, $attributes['starts_on'], $attributes['ends_on']);

            $year = AcademicYear::query()->create([
                'school_id' => $school->id,
                'name' => $attributes['name'],
                'code' => $attributes['code'],
                'starts_on' => $attributes['starts_on'],
                'ends_on' => $attributes['ends_on'],
                'status' => 'draft',
            ]);

            $this->audit->school($school, 'academic_year.created', actor: $actor, subject: $year, metadata: [
                'name' => $year->name,
                'code' => $year->code,
            ]);

            event(new AcademicYearCreated($school->id, $year->id, $year->name, $year->code));

            return $year;
        }));
    }

    /**
     * Section 16/17/80: the database's partial unique index
     * (`academic_years_one_active_per_school`) is the actual
     * concurrency guarantee -- this method's job is to (a) demote
     * whatever was previously active in the SAME transaction as the new
     * activation, so the common (non-racing) case leaves exactly one
     * active row, and (b) translate the database's own rejection of a
     * genuine race into a clean domain exception rather than a raw SQL
     * error.
     */
    public function activate(AcademicYear $year, ?User $actor = null): AcademicYear
    {
        if ($year->status !== 'draft') {
            throw new InvalidAcademicYearTransitionException($year->status, 'active');
        }

        return $this->context->withSchool($year->school, function () use ($year, $actor) {
            try {
                return DB::transaction(function () use ($year, $actor) {
                    $previousActive = AcademicYear::query()
                        ->where('school_id', $year->school_id)
                        ->where('status', 'active')
                        ->where('id', '!=', $year->id)
                        ->lockForUpdate()
                        ->first();

                    if ($previousActive !== null) {
                        $previousActive->update(['status' => 'closed']);
                    }

                    // A conditional UPDATE (not a blind $year->update()) so
                    // a stale in-memory $year that lost a same-row race
                    // (someone else already activated/transitioned THIS
                    // exact row between read and write) is caught here
                    // rather than silently double-processed.
                    $affected = AcademicYear::query()
                        ->where('id', $year->id)
                        ->where('status', 'draft')
                        ->update(['status' => 'active']);

                    if ($affected === 0) {
                        throw new InvalidAcademicYearTransitionException($year->fresh()->status ?? 'unknown', 'active');
                    }

                    $this->audit->school($year->school, 'academic_year.activated', actor: $actor, subject: $year, metadata: [
                        'previousActiveAcademicYearId' => $previousActive?->id,
                    ]);

                    event(new AcademicYearActivated($year->school_id, $year->id, $previousActive?->id));

                    return $year->refresh();
                });
            } catch (UniqueConstraintViolationException) {
                throw new ConcurrentActivationConflictException;
            }
        });
    }

    public function close(AcademicYear $year, ?User $actor = null): AcademicYear
    {
        if ($year->status !== 'active') {
            throw new InvalidAcademicYearTransitionException($year->status, 'closed');
        }

        return $this->context->withSchool($year->school, fn () => DB::transaction(function () use ($year, $actor) {
            $year->update(['status' => 'closed']);

            $this->audit->school($year->school, 'academic_year.closed', actor: $actor, subject: $year);

            event(new AcademicYearClosed($year->school_id, $year->id));

            return $year->refresh();
        }));
    }

    private function assertNoOverlap(School $school, string $startsOn, string $endsOn): void
    {
        $overlaps = AcademicYear::query()
            ->where('school_id', $school->id)
            ->where('starts_on', '<', $endsOn)
            ->where('ends_on', '>', $startsOn)
            ->exists();

        if ($overlaps) {
            throw new AcademicYearOverlapException;
        }
    }
}
