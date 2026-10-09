<?php

namespace App\Domain\Attendance\Application\Portal;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Guardians\Application\GuardianStudentScope;
use App\Domain\Identity\Application\Portal\ActingGuardian;
use App\Domain\Identity\Application\Portal\GuardianPortalAccessDeniedException;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Portal\PortalAvailability;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * POR.2 (ADR 0070 §25): one linked Student's minimized Attendance, for an
 * already-resolved ActingGuardian. Attendance owns the data; the portal only
 * reads it through here. Never a staff service with its capability bypassed.
 *
 * - Authority: GuardianStudentScope (live; legal guardian + active Student +
 *   active Guardian, this School). The data query EMBEDS the scope's
 *   predicate, so authorization and read are one statement. Anything else
 *   is the same 404 (unknown, another Guardian's, another School's, revoked,
 *   non-legal-guardian, inactive Student).
 * - History window: ONLY the School's ACTIVE AcademicYear (the one
 *   database-enforced current year), never after the School's local today,
 *   at most MAX_DAYS per request (default the last DEFAULT_DAYS). No earlier
 *   year, no relationship-start inference from `created_at`, no unlimited
 *   history. A School with no active year shows nothing. Not a legal access
 *   period: POR-L1 decides production conditions.
 * - Projection: date, the period's start/end time and the status
 *   (present|absent|late|excused). Never the teacher, submitter, subject,
 *   section, enrollment, correction flag or audit history, and never a
 *   classmate or sibling row.
 * - One `attendance.guardian.viewed` audit per successful read (ids, window,
 *   count; never a status). Denied reads write nothing (the existing
 *   convention: only successful protected reads are audited).
 *
 * MFA is the route's (`mfa-page`); PortalAvailability is re-asserted here.
 */
final class GuardianAttendanceReadService
{
    public const AUDIT_EVENT = 'attendance.guardian.viewed';

    public const CAPABILITY = 'portal.attendance.view';

    public const MAX_DAYS = 62;

    public const DEFAULT_DAYS = 30;

    public function __construct(
        private readonly TenantContext $context,
        private readonly GuardianStudentScope $scope,
        private readonly AuditRecorder $audit,
        private readonly CapabilityResolver $capabilities,
    ) {}

    /** @return list<array{id: string, name: string}> */
    public function students(School $school, ActingGuardian $guardian, User $actor): array
    {
        PortalAvailability::assertAvailable();
        $this->assertSelf($school, $guardian, $actor);

        return $this->scope->eligibleStudents($school, $guardian->guardianId);
    }

    /**
     * @return array{
     *     student: array{id: string, name: string},
     *     academicYear: array{id: string, name: string, startsOn: string, endsOn: string}|null,
     *     window: array{from: string, to: string}|null,
     *     records: list<array{date: string, periodStart: string, periodEnd: string, status: string}>
     * }
     */
    public function history(School $school, ActingGuardian $guardian, User $actor, string $studentId, ?string $from = null, ?string $to = null): array
    {
        PortalAvailability::assertAvailable();

        $this->assertSelf($school, $guardian, $actor);

        return $this->context->withSchool($school, function () use ($school, $guardian, $actor, $studentId, $from, $to): array {
            $student = collect($this->scope->eligibleStudents($school, $guardian->guardianId))->firstWhere('id', $studentId)
                ?? throw (new ModelNotFoundException)->setModel(Student::class);

            $year = AcademicYear::query()->where('school_id', $school->id)->where('status', 'active')->first();
            $window = $year === null ? null : $this->window($school, $year, $from, $to);

            $records = $window === null ? [] : DB::table('attendance_records as ar')
                ->join('attendance_sessions as s', fn ($j) => $j->on('s.id', '=', 'ar.attendance_session_id')->on('s.school_id', '=', 'ar.school_id'))
                ->join('student_enrollments as se', fn ($j) => $j->on('se.id', '=', 'ar.student_enrollment_id')->on('se.school_id', '=', 'ar.school_id'))
                ->where('ar.school_id', $school->id)
                ->where('ar.academic_year_id', $year->id)
                ->where('se.student_id', $studentId)
                ->whereIn('se.student_id', $this->scope->eligibleStudentIdsQuery($school, $guardian->guardianId))
                ->whereBetween('s.attendance_date', [$window['from'], $window['to']])
                ->orderByDesc('s.attendance_date')->orderBy('s.period_start_time')->orderBy('ar.id')
                ->get(['s.attendance_date', 's.period_start_time', 's.period_end_time', 'ar.status'])
                ->map(fn ($r): array => [
                    'date' => CarbonImmutable::parse($r->attendance_date)->toDateString(),
                    'periodStart' => substr((string) $r->period_start_time, 0, 5),
                    'periodEnd' => substr((string) $r->period_end_time, 0, 5),
                    'status' => (string) $r->status,
                ])->values()->all();

            $this->audit->school($school, self::AUDIT_EVENT, actor: $actor, metadata: [
                'guardianId' => $guardian->guardianId,
                'accountLinkId' => $guardian->accountLinkId,
                'studentId' => $studentId,
                'academicYearId' => $year?->id,
                'from' => $window['from'] ?? null,
                'to' => $window['to'] ?? null,
                'recordCount' => count($records),
                'surface' => 'guardian_portal',
            ]);

            return [
                'student' => $student,
                'academicYear' => $year === null ? null : [
                    'id' => $year->id, 'name' => $year->name,
                    'startsOn' => $year->starts_on->toDateString(), 'endsOn' => $year->ends_on->toDateString(),
                ],
                'window' => $window,
                'records' => $records,
            ];
        });
    }

    /**
     * Clamped to [year start, min(year end, School-local today)], at most
     * MAX_DAYS, default the last DEFAULT_DAYS. Null when nothing is in range.
     *
     * @return array{from: string, to: string}|null
     */
    private function window(School $school, AcademicYear $year, ?string $from, ?string $to): ?array
    {
        // Every date is a calendar day in the School's own timezone (never mixed with UTC).
        $tz = $school->timezone ?: 'UTC';
        $today = CarbonImmutable::now($tz)->startOfDay();
        $first = CarbonImmutable::parse($year->starts_on->toDateString(), $tz);
        $last = CarbonImmutable::parse($year->ends_on->toDateString(), $tz);
        $last = $last->lessThan($today) ? $last : $today;

        $end = $to !== null ? CarbonImmutable::parse($to, $tz) : $last;
        $end = $end->greaterThan($last) ? $last : $end;
        $start = $from !== null ? CarbonImmutable::parse($from, $tz) : $end->subDays(self::DEFAULT_DAYS - 1);
        $start = $start->lessThan($first) ? $first : $start;
        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        if ($end->lessThan($first) || $start->greaterThan($end)) {
            return null;
        }

        return ['from' => $start->toDateString(), 'to' => $end->toDateString()];
    }

    /**
     * POR.5 (ADR 0070 §28.4, rule 6): the ActingGuardian must be this User's
     * in this School, and the capability is re-checked here, not only by the
     * route -- as the Fees and conversation services already do.
     */
    private function assertSelf(School $school, ActingGuardian $guardian, User $actor): void
    {
        if ($guardian->userId !== $actor->id || $guardian->schoolId !== $school->id) {
            throw (new ModelNotFoundException)->setModel(Student::class);
        }

        if (! $this->capabilities->canInSchool($actor, self::CAPABILITY, $school)) {
            throw new GuardianPortalAccessDeniedException;
        }
    }
}
