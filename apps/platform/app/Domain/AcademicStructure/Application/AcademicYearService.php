<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Application\Calendar\AcademicYearActivationBlocker;
use App\Domain\AcademicStructure\Application\Calendar\AcademicYearDateDependency;
use App\Domain\AcademicStructure\Application\Concerns\AdministersReferenceData;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicCalendarChangedException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicCalendarDatesFixedException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearActivationBlockedException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearDependentsOutsideException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearNotStartedException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearOverlapException;
use App\Domain\AcademicStructure\Application\Exceptions\ConcurrentActivationConflictException;
use App\Domain\AcademicStructure\Application\Exceptions\InvalidAcademicYearTransitionException;
use App\Domain\AcademicStructure\Events\AcademicYearActivated;
use App\Domain\AcademicStructure\Events\AcademicYearClosed;
use App\Domain\AcademicStructure\Events\AcademicYearCreated;
use App\Domain\AcademicStructure\Infrastructure\AcademicTerm;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The only sanctioned write path for Academic Year lifecycle (Phase 0D
 * sections 13-18) -- never write AcademicYear directly from a
 * controller. Every method: validate -> write state -> audit -> emit
 * domain event, inside one transaction (ADR 0025), following the exact
 * pattern App\Support\Settings\SchoolSettingsService established.
 */
class AcademicYearService
{
    use AdministersReferenceData;

    public const string CAPABILITY_MANAGE = 'academics.years.manage';

    /**
     * @param  iterable<AcademicYearDateDependency>  $dependencies
     * @param  iterable<AcademicYearActivationBlocker>  $activationBlockers
     */
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        #[Tag(AcademicYearDateDependency::TAG)] private readonly iterable $dependencies = [],
        #[Tag(AcademicYearActivationBlocker::TAG)] private readonly iterable $activationBlockers = [],
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
    /**
     * SWC.2 (ADR 0073 section 4.2): the validating entry point shared by the
     * API and the web form -- the case-insensitive unique code (rule 74) is
     * checked before create(), and a duplicate that races past it surfaces
     * as the same field error, never a 500.
     *
     * @param  array<string, mixed>  $input
     */
    public function createFromInput(School $school, array $input, User $actor): AcademicYear
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('academic_years')->where('school_id', $school->id)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
        ];
        $validated = $this->validateInput($input, $rules);

        return $this->writeUnique(fn (): AcademicYear => $this->create($school, $validated, $actor), fn () => $this->validateInput($input, $rules));
    }

    /**
     * SWC.2: the label/code edit moved from the API controller. Dates and
     * status never change here -- they have their own invariant-checked
     * paths (creation overlap check; activate()/close()).
     *
     * @param  array<string, mixed>  $input
     */
    public function rename(School $school, string $academicYearId, array $input, User $actor): AcademicYear
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $academicYearId, $input, $actor): AcademicYear {
            $year = AcademicYear::query()->findOrFail($academicYearId);
            $rules = [
                'name' => ['sometimes', 'string', 'max:255'],
                'code' => ['sometimes', 'string', 'max:32', Rule::unique('academic_years')->where('school_id', $school->id)->ignore($year->id)],
            ];
            $validated = $this->validateInput($input, $rules);
            $before = $year->only(array_keys($validated));

            $this->writeUnique(fn () => DB::transaction(function () use ($school, $year, $validated, $before, $actor): void {
                $year->update($validated);

                $this->audit->school($school, 'academic_year.updated', actor: $actor, subject: $year, metadata: [
                    'before' => $before,
                    'after' => $validated,
                ]);
            }), fn () => $this->validateInput($input, $rules));

            return $year->refresh();
        });
    }

    /**
     * SIS 5.1 (MC-3, ADR 0077): correct a DRAFT year's start and end dates --
     * nothing else (name, code and status have their own paths). Under the
     * year's FOR UPDATE lock, which activation and every dependent writer's
     * FOR SHARE read wait for:
     * - the year must still be draft and unchanged since the form was opened
     *   (`expected_updated_at`; an activation in between is refused, never
     *   re-dated afterwards -- the database refuses it too);
     * - creation's rules: start before end, no overlap with another year;
     * - every Term and every dependent record (placements, teaching
     *   assignments, examinations, LMS due dates, fee instalments and
     *   concessions) must stay inside the new dates. Nothing is moved,
     *   truncated or ended: the operator corrects them first.
     *
     * @param  array<string, mixed>  $input
     */
    public function correctDraftDates(School $school, string $academicYearId, array $input, User $actor): AcademicYear
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);
        $validated = $this->validateInput($input, [
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after:starts_on'],
            'expected_updated_at' => ['required', 'string', 'max:64'],
        ]);

        return $this->context->withSchool($school, fn (): AcademicYear => DB::transaction(function () use ($school, $academicYearId, $validated, $actor): AcademicYear {
            $year = AcademicYear::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($academicYearId);

            if ($year->status !== 'draft') {
                throw new AcademicCalendarDatesFixedException($year->status);
            }
            if ($year->updated_at?->toIso8601String() !== $validated['expected_updated_at']) {
                throw new AcademicCalendarChangedException;
            }

            $before = ['starts_on' => $year->starts_on->toDateString(), 'ends_on' => $year->ends_on->toDateString()];
            $after = ['starts_on' => $validated['starts_on'], 'ends_on' => $validated['ends_on']];
            if ($before === $after) {
                return $year;
            }

            $this->assertNoOverlap($school, $after['starts_on'], $after['ends_on'], $year->id);

            $termsOutside = AcademicTerm::query()->where('academic_year_id', $year->id)
                ->where(fn ($q) => $q->where('starts_on', '<', $after['starts_on'])->orWhere('ends_on', '>', $after['ends_on']))
                ->count();
            if ($termsOutside > 0) {
                throw AcademicYearDependentsOutsideException::terms($termsOutside);
            }
            foreach ($this->dependencies as $dependency) {
                $outside = $dependency->recordsOutside($school, $year->id, $after['starts_on'], $after['ends_on']);
                if ($outside > 0) {
                    throw AcademicYearDependentsOutsideException::records($dependency->kind(), $outside);
                }
            }

            $year->update($after);

            $this->audit->school($school, 'academic_year.dates_corrected', actor: $actor, subject: $year, metadata: [
                'changed' => array_keys(array_diff_assoc($after, $before)),
                'before' => $before,
                'after' => $after,
            ]);

            return $year->refresh();
        }));
    }

    public function activate(AcademicYear $year, ?User $actor = null): AcademicYear
    {
        if ($year->status !== 'draft') {
            throw new InvalidAcademicYearTransitionException($year->status, 'active');
        }

        // UAT-C1 (UAT-YR-07): 0 days early. Checked before any write, against
        // the School's own calendar date (never UTC or server time), so a
        // refused activation leaves both Years and every placement untouched.
        if ($year->starts_on->toDateString() > self::schoolToday($year->school)) {
            throw new AcademicYearNotStartedException($year->starts_on->toDateString());
        }

        return $this->context->withSchool($year->school, function () use ($year, $actor) {
            try {
                return DB::transaction(function () use ($year, $actor) {
                    // SIS 5.1: a draft year's dates can now be corrected, so the
                    // checks above are repeated on the locked row -- a
                    // correction that committed first (moving the start date
                    // later) is seen; one still running waits for this.
                    $locked = AcademicYear::query()->whereKey($year->id)->lockForUpdate()->firstOrFail();
                    if ($locked->status !== 'draft') {
                        throw new InvalidAcademicYearTransitionException($locked->status, 'active');
                    }
                    if ($locked->starts_on->toDateString() > self::schoolToday($year->school)) {
                        throw new AcademicYearNotStartedException($locked->starts_on->toDateString());
                    }
                    // SIS 5.2: never cut a multi-transaction rollover into this
                    // year in half (RolloverExecutionActivationBlocker).
                    foreach ($this->activationBlockers as $blocker) {
                        $reason = $blocker->blocksActivation($locked);
                        if ($reason !== null) {
                            throw new AcademicYearActivationBlockedException($reason);
                        }
                    }

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

    /**
     * Whether a draft Year's start date has arrived in its School's own
     * calendar, so activate() would accept it (UAT-YR-07). The page uses it
     * to withhold the Activate action; activate() remains the authority.
     */
    public static function hasStarted(AcademicYear $year): bool
    {
        return $year->starts_on->toDateString() <= self::schoolToday($year->school);
    }

    private static function schoolToday(School $school): string
    {
        return CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString();
    }

    private function assertNoOverlap(School $school, string $startsOn, string $endsOn, ?string $exceptYearId = null): void
    {
        $overlaps = AcademicYear::query()
            ->where('school_id', $school->id)
            ->when($exceptYearId !== null, fn ($q) => $q->whereKeyNot($exceptYearId))
            ->where('starts_on', '<', $endsOn)
            ->where('ends_on', '>', $startsOn)
            ->exists();

        if ($overlaps) {
            throw new AcademicYearOverlapException;
        }
    }
}
