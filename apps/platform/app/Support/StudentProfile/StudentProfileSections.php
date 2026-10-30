<?php

namespace App\Support\StudentProfile;

use App\Domain\Attendance\Application\AttendanceOperationsReadService;
use App\Domain\Guardians\Application\StudentSiblingReader;
use App\Domain\Hostel\Application\HostelStudentSummaryReader;
use App\Domain\Library\Application\LibraryStudentSummaryReader;
use App\Domain\Payments\Application\Collections\OutstandingFeeRow;
use App\Domain\Payments\Application\Collections\OutstandingFeesReadService;
use App\Domain\Students\Application\Certificates\StudentCertificateReader;
use App\Domain\Students\Application\Documents\StudentDocumentReader;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Application\SubjectOfferingRosterReadService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Transport\Application\TransportStudentSummaryReader;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * SIS.EXP-1: the Student 360 sections of the whole-School staff Student
 * profile -- an aggregation and presentation surface, never an owner.
 *
 * Every cross-domain section is read through the OWNING domain's read path
 * under that domain's OWN capability and CampusScope, resolved fresh here
 * (module entitlement is already part of capability resolution, rule 100):
 *
 * | Section    | Owner and read path                                        | Gate (scope)                                         |
 * |------------|------------------------------------------------------------|------------------------------------------------------|
 * | family     | Guardians StudentSiblingReader + StudentAccess             | `students.view` (siblings filtered by that scope)    |
 * | subjects   | Students SubjectOfferingRosterReadService                  | whole-School `academics.subjects.view` + placement   |
 * | attendance | Attendance AttendanceOperationsReadService::studentHistory | `attendance.view` scope reaching the Student         |
 * | fees       | Payments OutstandingFeesReadService (narrowed to the id)   | `finance.charges.view` ∩ `finance.payments.view`     |
 * | transport  | Transport TransportStudentSummaryReader                    | `transport.assignments.view` scope                   |
 * | hostel     | Hostel HostelStudentSummaryReader                          | `hostel.residency.view` scope                        |
 * | library    | Library LibraryStudentSummaryReader                        | `library.circulation.view` scope                     |
 *
 * - A section the viewer may not read is ABSENT from the payload (never
 *   sent and hidden in Vue, never a "you may not see" message). An
 *   authorized section with nothing to show is present and empty.
 * - `students.view` alone opens no other domain. A Campus-scoped domain
 *   grant shows only that domain's in-scope records (marked `campus`).
 * - No business write: nothing here changes any record, and no domain
 *   depends on this class (StudentProfileArchitectureTest). The owners'
 *   own read audits still apply (Attendance records
 *   `attendance.student_history_viewed`, Payments
 *   `fee_outstanding_list.viewed`), exactly as on their own pages.
 * - Bounded: each section is a constant number of queries, independent of
 *   how much data the Student or the School has.
 */
final class StudentProfileSections
{
    /** At most this many recent attendance records are shown (no rate, no tally: ADR 0040, rule 104). */
    public const int RECENT_ATTENDANCE = 5;

    public const int ATTENDANCE_DAYS = 30;

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly CampusScopeResolver $scopes,
        private readonly StudentAccess $students,
        private readonly StudentSiblingReader $siblings,
        private readonly SubjectOfferingRosterReadService $subjects,
        private readonly AttendanceOperationsReadService $attendance,
        private readonly OutstandingFeesReadService $fees,
        private readonly TransportStudentSummaryReader $transport,
        private readonly HostelStudentSummaryReader $hostel,
        private readonly LibraryStudentSummaryReader $library,
        private readonly StudentDocumentReader $documents,
        private readonly StudentCertificateReader $certificates,
    ) {}

    /**
     * @param  array<string, string>  $guardianNames  the profile's own Guardian id => display name
     * @return array<string, array<string, mixed>>
     */
    public function for(School $school, User $actor, Student $student, ?StudentEnrollment $current, array $guardianNames): array
    {
        $sections = [];

        $family = $this->family($school, $actor, $student, $guardianNames);
        if ($family !== null) {
            $sections['family'] = $family;
        }
        if (($subjects = $this->subjectsSection($school, $actor, $current)) !== null) {
            $sections['subjects'] = $subjects;
        }
        if (($attendance = $this->attendanceSection($school, $actor, $student)) !== null) {
            $sections['attendance'] = $attendance;
        }
        if (($fees = $this->feesSection($school, $actor, $student)) !== null) {
            $sections['fees'] = $fees;
        }

        $transport = $this->scopes->campusScope($actor, 'transport.assignments.view', $school);
        if (! $transport->isNone()) {
            $sections['transport'] = [
                'scope' => $this->label($transport),
                'assignments' => $this->transport->currentFor($transport, $student->id),
            ];
        }
        $hostel = $this->scopes->campusScope($actor, 'hostel.residency.view', $school);
        if (! $hostel->isNone()) {
            $sections['hostel'] = [
                'scope' => $this->label($hostel),
                'residencies' => $this->hostel->currentFor($hostel, $student->id),
            ];
        }
        $library = $this->scopes->campusScope($actor, 'library.circulation.view', $school);
        if (! $library->isNone()) {
            $sections['library'] = [
                'scope' => $this->label($library),
                ...$this->library->openLoansFor($library, $student->id),
                'link' => '/app/library/circulation?'.http_build_query(['student' => $student->student_number]),
            ];
        }

        // SIS.EXP-3: counts per category only; the list (titles, files) is
        // its own page behind current MFA.
        if (($documents = $this->documents->summary($school, $actor, $student)) !== null) {
            $sections['documents'] = $documents;
        }
        // SIS.EXP-4: certificate history (safe metadata; one query).
        if (($certificates = $this->certificates->historyFor($school, $actor, $student)) !== null) {
            $sections['certificates'] = ['items' => $certificates, 'link' => "/app/students/{$student->id}/certificates"];
        }

        return $sections;
    }

    /**
     * Siblings through a shared active Guardian, shown only when the viewer
     * may view THAT Student too (the viewer's own `students.view` scope);
     * the shared Guardian names are the profile's own Guardian block.
     *
     * @param  array<string, string>  $guardianNames
     * @return array<string, mixed>|null
     */
    private function family(School $school, User $actor, Student $student, array $guardianNames): ?array
    {
        $scope = $this->scopes->campusScope($actor, 'students.view', $school);
        if ($scope->isNone()) {
            return null;
        }
        // SIS.HARDEN-1 (R-6): the viewer's scope first, then the display
        // limit (one more row only says "more" about Students they may see).
        $visible = $this->students->students($school, $scope)->whereIn('students.id', $this->siblings->siblingIds($student))
            ->orderBy('first_name')->orderBy('last_name')->orderBy('students.id')->limit(StudentSiblingReader::LIMIT + 1)->get();
        $truncated = $visible->count() > StudentSiblingReader::LIMIT;
        $visible = $visible->take(StudentSiblingReader::LIMIT);
        if ($visible->isEmpty()) {
            return ['siblings' => [], 'truncated' => false];
        }
        $shared = $this->siblings->sharedGuardians($student, $visible->pluck('id')->map(fn ($id) => (string) $id)->all());
        $placementScope = $this->scopes->campusScope($actor, 'enrollments.view', $school);
        $placements = $this->students->currentPlacements($school, $placementScope, $visible->pluck('id')->map(fn ($id) => (string) $id)->all());

        return [
            'siblings' => $visible->map(fn (Student $s): array => [
                'id' => $s->id,
                'name' => trim(implode(' ', array_filter([$s->first_name, $s->middle_name, $s->last_name], fn (?string $p) => $p !== null && $p !== ''))),
                'studentNumber' => $s->student_number,
                'status' => $s->status,
                'currentPlacement' => ($p = $placements->get($s->id)) === null ? null : [
                    'gradeLevel' => $p->gradeLevel->name,
                    'section' => $p->section->name,
                    'campus' => $p->campus->name,
                ],
                'sharedGuardians' => array_values(array_filter(array_map(fn (string $g) => $guardianNames[$g] ?? null, $shared[(string) $s->id]))),
            ])->values()->all(),
            'truncated' => $truncated,
        ];
    }

    /** @return array<string, mixed>|null */
    private function subjectsSection(School $school, User $actor, ?StudentEnrollment $current): ?array
    {
        if ($current === null
            || ! $this->capabilities->canInSchool($actor, 'academics.subjects.view', $school)
            || ! $this->scopes->campusScope($actor, 'enrollments.view', $school)->isWholeSchool()) {
            return null;
        }

        return ['items' => $this->subjects->currentOfferingsFor($current)];
    }

    /** @return array<string, mixed>|null */
    private function attendanceSection(School $school, User $actor, Student $student): ?array
    {
        if ($this->scopes->campusScope($actor, AttendanceOperationsReadService::CAPABILITY, $school)->isNone()) {
            return null;
        }
        $to = SchoolTimezone::today($school);
        $from = CarbonImmutable::parse($to)->subDays(self::ATTENDANCE_DAYS - 1)->toDateString();

        try {
            // Attendance's own read: its scope, its audit, its 404 for a
            // Student outside the viewer's Attendance scope (then: absent).
            $history = $this->attendance->studentHistory($school, $student->id, $from, $to, 1, $actor);
        } catch (ModelNotFoundException) {
            return null;
        }

        return [
            'from' => $from,
            'to' => $to,
            'recent' => array_map(fn (array $r): array => [
                'date' => $r['date'],
                'classLabel' => $r['classLabel'],
                'subjectName' => $r['subjectName'],
                'periodName' => $r['periodName'],
                'status' => $r['status'],
                'corrected' => $r['corrected'],
            ], array_slice($history['rows']->items(), 0, self::RECENT_ATTENDANCE)),
            'link' => "/app/attendance/students/{$student->id}",
        ];
    }

    /** @return array<string, mixed>|null */
    private function feesSection(School $school, User $actor, Student $student): ?array
    {
        $scope = $this->scopes->campusScope($actor, 'finance.charges.view', $school)
            ->intersect($this->scopes->campusScope($actor, 'finance.payments.view', $school));
        if ($scope->isNone()) {
            return null;
        }

        $list = $this->fees->list($school, $actor, OutstandingFeesReadService::ALL, null, [$student->id]);
        /** @var OutstandingFeeRow|null $row */
        $row = $list->rows[0] ?? null;

        return [
            'scope' => $this->label($scope),
            'hasCharges' => $row !== null,
            'billed' => $row?->billed->amount(),
            'concession' => $row?->concession->amount(),
            'paid' => $row?->paid->amount(),
            'outstanding' => $row?->outstanding->amount(),
            'overdue' => $row?->overdue->amount(),
            'openCharges' => $row->openCharges ?? 0,
            'nextDueDate' => $row?->nextDueDate,
            'earliestOverdueDueDate' => $row?->earliestOverdueDueDate,
            'currency' => $row?->outstanding->currency(),
            // The fee statement is a whole-School read of both capabilities;
            // its controller re-checks (never authority from this link).
            'statementLink' => $scope->isWholeSchool() ? "/app/finance/fee-statements/{$student->id}" : null,
        ];
    }

    private function label(CampusScope $scope): string
    {
        return $scope->isWholeSchool() ? 'school' : 'campus';
    }
}
