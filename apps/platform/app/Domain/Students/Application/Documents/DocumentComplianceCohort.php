<?php

namespace App\Domain\Students\Application\Documents;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Application\StudentSearch;
use App\Domain\Students\Infrastructure\StudentDocumentRequirement;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SIS 11.5 (owner decision 2026-10-31): the cohort document checklist --
 * per Student, the derived state of each requirement of ONE academic year.
 *
 * - **Authority:** whole-School `students.view` AND `students.documents.view`
 *   (the route adds current MFA). A Campus reader never sees it; out of
 *   reach is a refusal, never a narrower list.
 * - **States only:** never a title, file name, date, document id or
 *   download -- this is not a document directory, and there is no search
 *   of document content. The name/number search is the shared
 *   StudentSearch rule.
 * - **Cohort:** Students with a placement in the year that was not
 *   cancelled; Campus, Grade and Section filter their latest such
 *   placement. A `category`/`status` filter narrows, in SQL, with the SAME
 *   applicability and current-document rule as DocumentCompliance.
 * - **Operational, browser-only:** no percentage, trend, export or print
 *   (ADR 0040; SIS 11.4 governs exports and this is not one of them).
 * - **Bounded:** a constant number of queries per page.
 */
final class DocumentComplianceCohort
{
    use AuthorizesCapability;

    public const int PER_PAGE = 25;

    public const array SORTS = ['name' => 'Student name', 'student_number' => 'Student number'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentAccess $students,
        private readonly DocumentCompliance $compliance,
    ) {}

    /** Whole-School `students.view` and `students.documents.view`, or a refusal. */
    public function authorize(School $school, User $actor): void
    {
        $this->authorizeCapabilityFor($actor, DocumentService::STUDENT_DOCUMENTS_VIEW, $school);
        if (! $this->students->scopeOf($actor, 'students.view', $school)->isWholeSchool()) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  array{academic_year_id?: ?string, campus_id?: ?string, grade_level_id?: ?string, section_id?: ?string, category?: ?string, status?: ?string, q?: ?string, sort?: ?string}  $filters  validated by the caller
     * @return array{year: ?array{id: string, name: string}, rows: LengthAwarePaginator<array-key, array<string, mixed>>, options: array<string, array<int, array<string, mixed>>>}
     */
    public function page(School $school, User $actor, array $filters, int $page): array
    {
        $this->authorize($school, $actor);

        return $this->context->withSchool($school, function () use ($school, $filters, $page): array {
            $years = AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'status']);
            $year = ($filters['academic_year_id'] ?? '') !== ''
                ? $years->firstWhere('id', $filters['academic_year_id'])
                : ($years->firstWhere('status', 'active') ?? $years->first());

            /** @var array<string, array<int, array<string, mixed>>> $options */
            $options = [
                'academic_year_id' => $years->map(fn ($y) => ['value' => $y->id, 'label' => $y->name])->values()->all(),
                'campus_id' => Campus::query()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all(),
                'grade_level_id' => GradeLevel::query()->orderBy('sequence')->get(['id', 'name'])->map(fn ($g) => ['value' => $g->id, 'label' => $g->name])->all(),
                'section_id' => $year === null ? [] : Section::query()->where('academic_year_id', $year->id)->orderBy('name')->get(['id', 'name', 'campus_id', 'grade_level_id'])
                    ->map(fn ($s) => ['value' => $s->id, 'label' => $s->name, 'campus_id' => $s->campus_id, 'grade_level_id' => $s->grade_level_id])->all(),
                'category' => StudentDocumentCategory::requirableOptions(),
                'status' => array_map(fn (string $v, string $l) => ['value' => $v, 'label' => $l], array_keys(DocumentCompliance::STATE_LABELS), DocumentCompliance::STATE_LABELS),
            ];

            if ($year === null) {
                return ['year' => null, 'rows' => new LengthAwarePaginator([], 0, self::PER_PAGE, 1), 'options' => $options];
            }

            $query = $this->query($school->id, $year->id, $filters);
            match ($filters['sort'] ?? 'name') {
                'student_number' => $query->orderBy('c.student_number')->orderBy('c.student_id'),
                default => $query->orderBy('c.last_name')->orderBy('c.first_name')->orderBy('c.student_id'),
            };
            $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
            $items = collect($paginator->items());
            $ids = $items->pluck('student_id')->map(fn ($id) => (string) $id)->all();
            $checklists = $this->compliance->forStudents($school, $ids, $year->id);
            $names = $this->placementNames($items->pluck('campus_id')->all(), $items->pluck('grade_level_id')->all(), $items->pluck('section_id')->all());

            $rows = $items->map(function ($r) use ($checklists, $names): array {
                $items = array_map(fn (array $i) => ['category' => $i['category'], 'label' => $i['label'], 'required' => $i['required'], 'state' => $i['state'], 'stateLabel' => $i['stateLabel']], $checklists[(string) $r->student_id] ?? []);

                return [
                    'student' => [
                        'id' => (string) $r->student_id,
                        'displayName' => trim(implode(' ', array_filter([$r->first_name, $r->middle_name, $r->last_name], fn ($p) => $p !== null && $p !== ''))),
                        'studentNumber' => $r->student_number,
                    ],
                    'placement' => implode(' · ', array_filter([$names['campus'][$r->campus_id] ?? null, $names['grade'][$r->grade_level_id] ?? null, $names['section'][$r->section_id] ?? null])),
                    'items' => $items,
                    'summary' => DocumentCompliance::summary($items),
                ];
            })->all();

            /** @var LengthAwarePaginator<array-key, array<string, mixed>> $paged */
            $paged = (new LengthAwarePaginator($rows, $paginator->total(), $paginator->perPage(), $paginator->currentPage(), ['path' => $paginator->path(), 'pageName' => 'page']))->withQueryString();

            return [
                'year' => ['id' => $year->id, 'name' => $year->name],
                'rows' => $paged,
                'options' => $options,
            ];
        });
    }

    /** @param  array<string, ?string>  $filters */
    private function query(string $schoolId, string $yearId, array $filters): Builder
    {
        $latest = DB::table('student_enrollments as e')->join('students as s', 's.id', '=', 'e.student_id')
            ->where('e.school_id', $schoolId)->where('e.academic_year_id', $yearId)->where('e.status', '!=', 'cancelled')
            ->selectRaw('DISTINCT ON (e.student_id) e.student_id, e.campus_id, e.grade_level_id, e.section_id, s.first_name, s.middle_name, s.last_name, s.student_number')
            ->orderByRaw('e.student_id, e.starts_on DESC, e.id DESC');

        $query = DB::query()->fromSub($latest, 'c');
        foreach (['campus_id', 'grade_level_id', 'section_id'] as $column) {
            $value = (string) ($filters[$column] ?? '');
            if ($value !== '') {
                // A foreign or unknown id matches nothing.
                Str::isUuid($value) ? $query->where("c.{$column}", $value) : $query->whereRaw('false');
            }
        }
        if (($filters['q'] ?? '') !== '') {
            StudentSearch::anyField($query, (string) $filters['q'], 'c');
        }

        $category = ($filters['category'] ?? '') !== '' ? [(string) $filters['category']] : null;
        $state = ($filters['status'] ?? '') !== '' ? (string) $filters['status'] : null;
        if ($category !== null || $state !== null) {
            $categories = $category ?? StudentDocumentCategory::requirableValues();
            $query->where(function (Builder $any) use ($categories, $schoolId, $yearId, $state): void {
                foreach ($categories as $c) {
                    $any->orWhere(function (Builder $one) use ($c, $schoolId, $yearId, $state): void {
                        $one->whereRaw(self::APPLICABLE, [$schoolId, StudentDocumentRequirement::STUDENT, $c, $yearId]);
                        if ($state !== null) {
                            $one->whereRaw(self::STATE, [$schoolId, $c, $state]);
                        }
                    });
                }
            });
        }

        return $query;
    }

    /** DocumentCompliance's applicability rule, for one category, in SQL. */
    private const string APPLICABLE = <<<'SQL'
        EXISTS (SELECT 1 FROM student_document_requirements r
            JOIN student_enrollments pe ON pe.student_id = c.student_id AND pe.school_id = r.school_id
                AND pe.academic_year_id = r.academic_year_id AND pe.status <> 'cancelled'
                AND (r.campus_id IS NULL OR r.campus_id = pe.campus_id)
                AND (r.grade_level_id IS NULL OR r.grade_level_id = pe.grade_level_id)
                AND (pe.ends_on IS NULL OR pe.ends_on >= r.effective_from)
            WHERE r.school_id = ? AND r.subject = ? AND r.retired_at IS NULL AND r.category = ? AND r.academic_year_id = ?)
        SQL;

    /** DocumentCompliance's current-document state, for one category, in SQL. */
    private const string STATE = <<<'SQL'
        COALESCE((SELECT CASE WHEN d.verification_status IN ('verified', 'rejected') THEN d.verification_status ELSE 'pending' END
            FROM student_documents d
            WHERE d.school_id = ? AND d.student_id = c.student_id AND d.category = ? AND d.archived_at IS NULL
            ORDER BY d.created_at DESC, d.id DESC LIMIT 1), 'missing') = ?
        SQL;

    /**
     * @param  list<?string>  $campusIds
     * @param  list<?string>  $gradeIds
     * @param  list<?string>  $sectionIds
     * @return array{campus: array<string, string>, grade: array<string, string>, section: array<string, string>}
     */
    private function placementNames(array $campusIds, array $gradeIds, array $sectionIds): array
    {
        $ids = fn (array $v) => array_values(array_unique(array_filter($v)));

        return [
            'campus' => Campus::query()->whereIn('id', $ids($campusIds))->pluck('name', 'id')->all(),
            'grade' => GradeLevel::query()->whereIn('id', $ids($gradeIds))->pluck('name', 'id')->all(),
            'section' => Section::query()->whereIn('id', $ids($sectionIds))->pluck('name', 'id')->all(),
        ];
    }
}
