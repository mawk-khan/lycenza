<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\SchoolAuditSubjectHistoryEntry;
use App\Support\Audit\SchoolAuditSubjectHistoryReader;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/**
 * V11.2 (ADR 0075): a Student's operational history -- a READ PROJECTION
 * over the School audit ledger through SchoolAuditSubjectHistoryReader
 * (ADR 0042's audit read contract), modelled on the HR Employee Activity
 * Timeline (Phase 8A.11). No table, no write, no second event store.
 *
 * - CLOSED allowlist (CATEGORIES): only these event types, and for each
 *   only the listed metadata keys, are ever read. An unknown event type is
 *   invisible.
 * - Never wider than the source (ADR 0042 section 4): every category is
 *   gated by the capability that already shows the same facts elsewhere --
 *   the Student record (`students.view`), enrollment history
 *   (`enrollments.view`), Guardian links (`guardians.view`), Admissions
 *   (`admissions.view`). A category the actor cannot see is not queried.
 * - No actor: who made a change is audit evidence for the School audit-log
 *   review (`school.audit.view`, itself audited), not repeated here.
 * - Honest about gaps: only what the ledger recorded is shown. Effective
 *   dates appear only where the event recorded one (an enrollment's
 *   `endsOn`); a status change has no effective date in the Student
 *   contract (PHASE-1E-0 section 11), so none is invented.
 * - Not access-audited, like the HR timeline: a curated, authorization-
 *   filtered view of history the viewer may already see at its source.
 */
class StudentActivityHistoryService
{
    public const int LIMIT = 200;

    /**
     * category => [required capability, [event type => returned metadata keys]].
     *
     * @var array<string, array{0: string, 1: array<string, list<string>>}>
     */
    public const array CATEGORIES = [
        'record' => ['students.view', [
            'student.created' => [],
            'student.updated' => ['changedFields'],
            'student.status_changed' => ['previousStatus', 'newStatus'],
        ]],
        'placement' => ['enrollments.view', [
            'student_enrollment.created' => ['academicYearId', 'sectionId'],
            'student_enrollment.transferred' => ['fromSectionId', 'toSectionId', 'endsOn'],
            'student_enrollment.completed' => ['academicYearId', 'sectionId', 'endsOn'],
            'student_enrollment.withdrawn' => ['academicYearId', 'sectionId', 'endsOn'],
            'student_enrollment.cancelled' => ['academicYearId', 'sectionId', 'endsOn'],
        ]],
        'guardian' => ['guardians.view', [
            'student_guardian.linked' => ['guardianId', 'relationshipType'],
            'student_guardian.updated' => ['guardianId'],
            'student_guardian.primary_changed' => ['guardianId'],
            'student_guardian.unlinked' => ['guardianId'],
        ]],
        'admission' => ['admissions.view', [
            'admission_application.converted' => [],
        ]],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly CapabilityResolver $capabilities,
        private readonly SchoolAuditSubjectHistoryReader $reader,
    ) {}

    /**
     * @return array{student: Student, entries: list<array<string, mixed>>, truncated: bool, categories: list<string>}|null
     *                                                                                                                      null when the Student is not in this School
     */
    public function forStudent(School $school, string $studentId, User $actor): ?array
    {
        // ORG.7F-1 (ADR 0076 §45): history is a declared whole-School-only
        // operation -- the ordinary `capability` Gate, which a Campus-scoped
        // students.view grant never satisfies.
        Gate::forUser($actor)->authorize('capability', ['students.view', $school]);

        $student = $this->context->withSchool($school, fn () => Student::query()->where('school_id', $school->id)->find($studentId));
        if ($student === null) {
            return null;
        }

        $visible = [];
        $allowlist = [];
        foreach (self::CATEGORIES as $category => [$capability, $events]) {
            if ($this->capabilities->canInSchool($actor, $capability, $school)) {
                $visible[] = $category;
                $allowlist += $events;
            }
        }

        $history = $this->reader->history($school, Student::class, $student->id, 'studentId', $allowlist, self::LIMIT);

        $names = $this->referenceNames($school, $history->entries);

        return [
            'student' => $student,
            'entries' => array_map(fn (SchoolAuditSubjectHistoryEntry $e) => $this->present($e, $names), $history->entries),
            'truncated' => $history->truncated,
            'categories' => $visible,
        ];
    }

    /**
     * Section and AcademicYear names for the ids the entries carry, in one
     * query each (School-scoped). A missing row keeps its id unnamed.
     *
     * @param  list<SchoolAuditSubjectHistoryEntry>  $entries
     * @return array{sections: array<string, string>, years: array<string, string>}
     */
    private function referenceNames(School $school, array $entries): array
    {
        $sectionIds = [];
        $yearIds = [];
        foreach ($entries as $entry) {
            foreach (['sectionId', 'fromSectionId', 'toSectionId'] as $key) {
                if (is_string($entry->details[$key] ?? null)) {
                    $sectionIds[] = $entry->details[$key];
                }
            }
            if (is_string($entry->details['academicYearId'] ?? null)) {
                $yearIds[] = $entry->details['academicYearId'];
            }
        }

        return $this->context->withSchool($school, fn () => [
            'sections' => $sectionIds === [] ? [] : Section::query()->where('school_id', $school->id)
                ->whereIn('id', array_unique($sectionIds))->pluck('name', 'id')->all(),
            'years' => $yearIds === [] ? [] : AcademicYear::query()->where('school_id', $school->id)
                ->whereIn('id', array_unique($yearIds))->pluck('name', 'id')->all(),
        ]);
    }

    /**
     * @param  array{sections: array<string, string>, years: array<string, string>}  $names
     * @return array<string, mixed>
     */
    private function present(SchoolAuditSubjectHistoryEntry $entry, array $names): array
    {
        $d = $entry->details;
        $section = fn (?string $key) => is_string($d[$key] ?? null) ? ($names['sections'][$d[$key]] ?? null) : null;

        return [
            'id' => $entry->id,
            'recordedAt' => $entry->occurredAt,
            'eventType' => $entry->eventType,
            'category' => $this->categoryOf($entry->eventType),
            // Business-effective date, only where the event recorded one.
            'effectiveOn' => is_string($d['endsOn'] ?? null) ? $d['endsOn'] : null,
            'academicYear' => is_string($d['academicYearId'] ?? null) ? ($names['years'][$d['academicYearId']] ?? null) : null,
            'section' => $section('sectionId'),
            'fromSection' => $section('fromSectionId'),
            'toSection' => $section('toSectionId'),
            'previousStatus' => is_string($d['previousStatus'] ?? null) ? $d['previousStatus'] : null,
            'newStatus' => is_string($d['newStatus'] ?? null) ? $d['newStatus'] : null,
            'changedFields' => is_array($d['changedFields'] ?? null) ? array_values(array_filter($d['changedFields'], 'is_string')) : [],
            'guardianId' => is_string($d['guardianId'] ?? null) ? $d['guardianId'] : null,
            'relationshipType' => is_string($d['relationshipType'] ?? null) ? $d['relationshipType'] : null,
        ];
    }

    private function categoryOf(string $eventType): string
    {
        foreach (self::CATEGORIES as $category => [, $events]) {
            if (array_key_exists($eventType, $events)) {
                return $category;
            }
        }

        return 'record';
    }
}
