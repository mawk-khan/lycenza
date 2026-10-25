<?php

namespace App\Domain\Communications\Application\Events;

use App\Domain\Communications\Infrastructure\CommunicationEvent;
use App\Domain\Guardians\Application\GuardianStudentScope;
use App\Domain\HR\Application\CurrentStaffMembers;
use App\Domain\Identity\Application\Portal\ActingGuardian;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

/**
 * ORG.9D-3 (ADR 0076 §75): who sees which School calendar Events -- read,
 * never written, and resolved for the VIEWER when they read, never
 * snapshotted per person (a former member of an audience stops seeing it):
 *
 * - Staff surface (`communications.events.view`, ORG.9D-3R1 §76 --
 *   consumption only, never `communications.view`): School-wide Events (an
 *   active member of this School holding it, re-checked here) and Staff
 *   Events only while the viewer is CURRENT staff (HR's CurrentStaffMembers
 *   -- never a role; the capability alone never makes anyone staff).
 * - Guardian portal (`portal.communications.view`, development only until
 *   E46): School-wide Events and Grade/Section Events whose Grade or Section
 *   currently holds one of the ActingGuardian's eligible Students
 *   (GuardianStudentScope -> current placement). Never a Staff Event.
 * - Management (`communications.announce`): every Event of the School, any
 *   state, for the people who prepare and publish them.
 *
 * Audience membership is checked ONCE per read for the viewer (a few
 * bounded reads), then applied to the Event rows in SQL -- never one
 * resolver call per Event, never a materialized audience.
 * A viewer sees only published Events and Events cancelled after they
 * were published (marked cancelled, so nothing silently disappears).
 * "Upcoming" = not yet ended (all-day: last day >= the School's today;
 * timed: end >= now); "past" = ended.
 */
final class CommunicationEventCalendar
{
    public const UPCOMING = 'upcoming';

    public const PAST = 'past';

    private const LIMIT = 200;

    public function __construct(
        private readonly TenantContext $context,
        private readonly CurrentStaffMembers $staff,
        private readonly GuardianStudentScope $guardians,
        private readonly StudentEnrollmentReadService $placements,
    ) {}

    /** @return list<array<string, mixed>> */
    public function forStaff(School $school, User $viewer, string $when, ?string $month = null): array
    {
        // ORG.9D-3R1: the service authorizes its own reader (module entitlement
        // included), never trusting the route alone.
        Gate::forUser($viewer)->authorize('capability', ['communications.events.view', $school]);
        $isStaff = $this->staff->includes($school, $viewer);

        return $this->context->withSchool($school, fn (): array => $this->list($school, $when, $month, function (Builder $q) use ($isStaff): void {
            $q->where(fn (Builder $a) => $a->where('audience_type', 'school_wide')
                ->when($isStaff, fn (Builder $s) => $s->orWhere('audience_type', 'staff')));
        }));
    }

    /** @return list<array<string, mixed>> */
    public function forGuardian(School $school, ActingGuardian $guardian, string $when, ?string $month = null): array
    {
        $studentIds = array_column($this->guardians->eligibleStudents($school, $guardian->guardianId), 'id');
        $placements = $this->placements->currentForMany($school, $studentIds);
        $grades = $placements->pluck('grade_level_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();
        $sections = $placements->pluck('section_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();

        return $this->context->withSchool($school, fn (): array => $this->list($school, $when, $month, function (Builder $q) use ($grades, $sections): void {
            $q->where(fn (Builder $a) => $a->where('audience_type', 'school_wide')
                ->orWhere(fn (Builder $g) => $g->where('audience_type', 'grade')->whereIn('grade_level_id', $grades))
                ->orWhere(fn (Builder $s) => $s->where('audience_type', 'section')->whereIn('section_id', $sections)));
        }));
    }

    /**
     * Every Event of the School for its preparers and approvers.
     *
     * @param  array{status?: ?string, when?: ?string, audience?: ?string, month?: ?string}  $filters
     * @return LengthAwarePaginator<int, array<array-key, mixed>>
     */
    public function manage(School $school, array $filters, int $page = 1): LengthAwarePaginator
    {
        return $this->context->withSchool($school, function () use ($school, $filters, $page) {
            $query = CommunicationEvent::query()->with(['gradeLevel:id,name', 'section:id,name,grade_level_id', 'section.gradeLevel:id,name', 'createdBy:id,name'])
                ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
                ->when($filters['audience'] ?? null, fn (Builder $q, string $audience) => $q->where('audience_type', $audience));
            if (($filters['when'] ?? null) !== null) {
                $this->when($query, $school, (string) $filters['when']);
            }
            if (($filters['month'] ?? null) !== null) {
                $this->month($query, $school, (string) $filters['month']);
            }
            $paginator = $this->ordered($query, $school, ($filters['when'] ?? null) === self::PAST)->paginate(25, page: $page);

            return $paginator->through(fn (CommunicationEvent $e) => $this->present($e, $school));
        });
    }

    /** One Event, presented. @return array<string, mixed> */
    public function present(CommunicationEvent $event, School $school): array
    {
        $tz = SchoolTimezone::resolve($school);
        $local = fn (?CarbonImmutable $at) => $at?->setTimezone($tz)->format('Y-m-d\TH:i');

        return [
            'id' => $event->id,
            'title' => $event->title,
            'body' => $event->body,
            'location' => $event->location,
            'dateMode' => $event->date_mode,
            'startsOn' => $event->starts_on?->format('Y-m-d'),
            'endsOn' => $event->ends_on?->format('Y-m-d'),
            'startsAt' => $local($event->starts_at),
            'endsAt' => $local($event->ends_at),
            'audienceType' => $event->audience_type,
            'gradeLevelId' => $event->grade_level_id,
            'sectionId' => $event->section_id,
            'audienceLabel' => match ($event->audience_type) {
                'school_wide' => 'Whole School',
                'staff' => 'Staff',
                'grade' => 'Guardians of '.($event->gradeLevel->name ?? 'a Grade'),
                'section' => 'Guardians of '.trim(($event->section->gradeLevel->name ?? '').' '.($event->section->name ?? 'a Section')),
                default => $event->audience_type,
            },
            'status' => $event->status,
            'cancelled' => $event->status === 'cancelled',
            'ended' => $event->isAllDay()
                ? $event->ends_on?->format('Y-m-d') < SchoolTimezone::today($school)
                : $event->ends_at?->lessThan(CarbonImmutable::now()),
            'createdByName' => $event->relationLoaded('createdBy') ? $event->createdBy?->name : null,
        ];
    }

    /**
     * @param  callable(Builder<CommunicationEvent>): void  $audience
     * @return list<array<string, mixed>>
     */
    private function list(School $school, string $when, ?string $month, callable $audience): array
    {
        $query = CommunicationEvent::query()->with(['gradeLevel:id,name', 'section:id,name,grade_level_id', 'section.gradeLevel:id,name'])
            ->whereNotNull('published_at'); // published, or cancelled after publication
        $audience($query);
        $this->when($query, $school, $when);
        if ($month !== null) {
            $this->month($query, $school, $month);
        }

        return $this->ordered($query, $school, $when === self::PAST)->limit(self::LIMIT)->get()
            ->map(fn (CommunicationEvent $e) => $this->present($e, $school))->values()->all();
    }

    /** @param  Builder<CommunicationEvent>  $query */
    private function when(Builder $query, School $school, string $when): void
    {
        $today = SchoolTimezone::today($school);
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:sP');
        $past = $when === self::PAST;
        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $d) => $d->where('date_mode', CommunicationEvent::ALL_DAY)->where('ends_on', $past ? '<' : '>=', $today))
            ->orWhere(fn (Builder $t) => $t->where('date_mode', CommunicationEvent::TIMED)->where('ends_at', $past ? '<' : '>=', $now)));
    }

    /** Events overlapping one School-local month (`Y-m`). @param  Builder<CommunicationEvent>  $query */
    private function month(Builder $query, School $school, string $month): void
    {
        $tz = SchoolTimezone::resolve($school);
        $first = CarbonImmutable::createFromFormat('!Y-m', $month, $tz) ?: CarbonImmutable::now($tz)->startOfMonth();
        $next = $first->addMonth();
        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $d) => $d->where('date_mode', CommunicationEvent::ALL_DAY)
                ->where('starts_on', '<', $next->format('Y-m-d'))->where('ends_on', '>=', $first->format('Y-m-d')))
            ->orWhere(fn (Builder $t) => $t->where('date_mode', CommunicationEvent::TIMED)
                ->where('starts_at', '<', $next->utc()->format('Y-m-d H:i:sP'))->where('ends_at', '>', $first->utc()->format('Y-m-d H:i:sP'))));
    }

    /**
     * Chronological by start in School-local terms (an all-day Event starts
     * at the School's local midnight); past lists newest first.
     *
     * @param  Builder<CommunicationEvent>  $query
     * @return Builder<CommunicationEvent>
     */
    private function ordered(Builder $query, School $school, bool $newestFirst): Builder
    {
        $direction = $newestFirst ? 'desc' : 'asc';

        return $query->orderByRaw("COALESCE(starts_at, (starts_on::timestamp AT TIME ZONE ?)) {$direction}", [SchoolTimezone::resolve($school)->getName()])
            ->orderBy('id');
    }
}
