<?php

namespace App\Domain\Students\Application\Documents;

use App\Domain\Students\Infrastructure\StudentDocumentRequirement;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * SIS 11.5: Student document COMPLIANCE, derived -- never stored.
 *
 * A School's rule (StudentDocumentRequirement, subject `student`, not
 * retired) applies to a Student who has a placement in the rule's academic
 * year matching its Campus and Grade (when set) that was not cancelled and
 * did not end before the rule's `effective_from`: a requirement added later
 * never makes a Student who had already left look non-compliant, and a
 * retired one stops applying at once (its evidence stays).
 *
 * Per applicable category (several rules for one category merge; Required
 * wins): the CURRENT document is the newest non-archived one of that
 * category; its state is Verified, Rejected, or Pending verification (also
 * for a document uploaded before verification existed, "not reviewed");
 * none is Missing. Read-only, authorization-neutral (callers authorize),
 * and bounded: three queries for any batch of Students. The Applicant side
 * receives its current documents from Admissions (rule 4).
 */
final class DocumentCompliance
{
    public const string MISSING = 'missing';

    public const string PENDING = 'pending';

    public const string VERIFIED = 'verified';

    public const string REJECTED = 'rejected';

    public const array STATE_LABELS = [
        self::MISSING => 'Missing',
        self::PENDING => 'Pending verification',
        self::VERIFIED => 'Verified',
        self::REJECTED => 'Rejected — replacement needed',
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<string>  $studentIds
     * @param  ?string  $academicYearId  only that year's rules (the cohort checklist); null = every year's
     * @return array<string, list<array{category: string, label: string, required: bool, state: string, stateLabel: string, documentId: ?string}>> by Student id
     */
    public function forStudents(School $school, array $studentIds, ?string $academicYearId = null): array
    {
        if ($studentIds === []) {
            return [];
        }

        return $this->context->withSchool($school, function () use ($school, $studentIds, $academicYearId): array {
            $rules = StudentDocumentRequirement::query()->where('school_id', $school->id)
                ->where('subject', StudentDocumentRequirement::STUDENT)->whereNull('retired_at')
                ->when($academicYearId !== null, fn ($q) => $q->where('academic_year_id', $academicYearId))->get();
            if ($rules->isEmpty()) {
                return array_fill_keys($studentIds, []);
            }

            $placements = DB::table('student_enrollments')->where('school_id', $school->id)
                ->whereIn('student_id', $studentIds)->whereIn('academic_year_id', $rules->pluck('academic_year_id')->unique()->all())
                ->where('status', '!=', 'cancelled')
                ->get(['student_id', 'academic_year_id', 'campus_id', 'grade_level_id', 'ends_on'])
                ->groupBy('student_id');

            $current = $this->currentDocuments('student_documents', 'student_id', $school->id, $studentIds);

            $out = [];
            foreach ($studentIds as $id) {
                $applicable = [];
                foreach ($rules as $rule) {
                    foreach ($placements->get($id, collect()) as $p) {
                        if ($p->academic_year_id === $rule->academic_year_id
                            && ($rule->campus_id === null || $rule->campus_id === $p->campus_id)
                            && ($rule->grade_level_id === null || $rule->grade_level_id === $p->grade_level_id)
                            && ($p->ends_on === null || substr((string) $p->ends_on, 0, 10) >= $rule->effective_from->toDateString())) {
                            $key = $rule->category->value;
                            $applicable[$key] = ($applicable[$key] ?? false) || $rule->required;
                        }
                    }
                }
                $out[$id] = self::rows($applicable, $current[$id] ?? []);
            }

            return $out;
        });
    }

    /**
     * The Applicant side: rules with subject `applicant` for the application's
     * own year, Campus and Grade, over the current documents Admissions
     * passes in (collected through THAT application; Students never reads
     * Admissions' tables).
     *
     * @param  array<string, array{id: string, status: ?string}>  $current  category => newest current document
     * @return list<array{category: string, label: string, required: bool, state: string, stateLabel: string, documentId: ?string}>
     */
    public function forApplication(School $school, string $academicYearId, string $campusId, string $gradeLevelId, array $current): array
    {
        return $this->context->withSchool($school, function () use ($school, $academicYearId, $campusId, $gradeLevelId, $current): array {
            $applicable = [];
            foreach (StudentDocumentRequirement::query()->where('school_id', $school->id)->where('subject', StudentDocumentRequirement::APPLICANT)
                ->whereNull('retired_at')->where('academic_year_id', $academicYearId)
                ->where(fn ($q) => $q->whereNull('campus_id')->orWhere('campus_id', $campusId))
                ->where(fn ($q) => $q->whereNull('grade_level_id')->orWhere('grade_level_id', $gradeLevelId))->get() as $rule) {
                $key = $rule->category->value;
                $applicable[$key] = ($applicable[$key] ?? false) || $rule->required;
            }

            return self::rows($applicable, $current);
        });
    }

    /**
     * The newest current document per subject and category, in ONE query.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, array{id: string, status: ?string}>>
     */
    private function currentDocuments(string $table, string $column, string $schoolId, array $ids): array
    {
        $rows = DB::table($table)->where('school_id', $schoolId)->whereIn($column, $ids)->whereNull('archived_at')
            ->whereIn('category', StudentDocumentCategory::requirableValues())
            ->selectRaw("DISTINCT ON ({$column}, category) {$column} AS subject_id, category, id, verification_status")
            ->orderByRaw("{$column}, category, created_at DESC, id DESC")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->subject_id][(string) $r->category] = ['id' => (string) $r->id, 'status' => $r->verification_status];
        }

        return $out;
    }

    /**
     * @param  array<string, bool>  $applicable  category => required
     * @param  array<string, array{id: string, status: ?string}>  $documents
     * @return list<array{category: string, label: string, required: bool, state: string, stateLabel: string, documentId: ?string}>
     */
    private static function rows(array $applicable, array $documents): array
    {
        $rows = [];
        foreach (StudentDocumentCategory::requirable() as $category) {
            if (! array_key_exists($category->value, $applicable)) {
                continue;
            }
            $doc = $documents[$category->value] ?? null;
            $state = match ($doc['status'] ?? false) {
                false => self::MISSING,
                'verified' => self::VERIFIED,
                'rejected' => self::REJECTED,
                default => self::PENDING,
            };
            $rows[] = [
                'category' => $category->value,
                'label' => $category->label(),
                'required' => $applicable[$category->value],
                'state' => $state,
                'stateLabel' => self::STATE_LABELS[$state],
                'documentId' => $doc['id'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * "5 required, 4 verified, 1 missing" -- a single subject's operational summary.
     *
     * @param  list<array{required: bool, state: string}>  $rows
     * @return array{required: int, verified: int, missing: int, pending: int, rejected: int}
     */
    public static function summary(array $rows): array
    {
        $required = array_values(array_filter($rows, fn (array $r) => $r['required']));
        $count = fn (string $s) => count(array_filter($required, fn (array $r) => $r['state'] === $s));

        return ['required' => count($required), 'verified' => $count(self::VERIFIED), 'missing' => $count(self::MISSING), 'pending' => $count(self::PENDING), 'rejected' => $count(self::REJECTED)];
    }
}
