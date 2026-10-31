<?php

namespace App\Domain\Students\Application\Documents;

use App\Domain\Documents\Application\DocumentContent;
use App\Domain\Documents\Application\DocumentListingService;
use App\Domain\Documents\Application\DocumentMetadata;
use App\Domain\Documents\Application\DocumentReadService;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentDocument;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * SIS.EXP-3: reads a Student's document records -- the Student-centric
 * list and the Student 360 summary. Never a School-wide file directory.
 *
 * - The list needs whole-School `students.view` (Student visibility) AND
 *   `students.documents.view`; the file facts come from Documents'
 *   listing service (studentFacts(): one query, no bytes, its own Highly
 *   Sensitive list audit), never from the `documents` table here.
 * - The summary is counts per category only -- no title, file name or
 *   date -- so the profile (which carries no MFA step-up) never shows
 *   Highly Sensitive metadata; the list page does, behind current MFA.
 */
final class StudentDocumentReader
{
    use AuthorizesCapability;

    public const int PER_PAGE = 20;

    /** Closed sort map: key => [column, direction]. */
    public const array SORTS = [
        'newest' => ['created_at', 'desc'],
        'document_date' => ['document_date', 'desc'],
        'category' => ['category', 'asc'],
        'title' => ['title', 'asc'],
    ];

    public const array STATUSES = ['active', 'archived', 'all'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentDocumentService $records,
        private readonly DocumentReadService $documents,
        private readonly DocumentListingService $listings,
        private readonly DocumentCompliance $compliance,
    ) {}

    /**
     * @param  array{category?: ?string, status?: ?string, sort?: ?string}  $filters  validated by the caller
     * @return array{student: Student, documents: LengthAwarePaginator<int, array<string, mixed>>, compliance: list<array<string, mixed>>}
     */
    public function page(School $school, User $actor, string $studentId, array $filters, int $page): array
    {
        return $this->context->withSchool($school, function () use ($school, $actor, $studentId, $filters, $page): array {
            $student = $this->records->visibleStudent($school, $actor, $studentId);
            $this->authorizeCapabilityFor($actor, DocumentService::STUDENT_DOCUMENTS_VIEW, $school);

            $query = StudentDocument::query()->where('school_id', $school->id)->where('student_id', $student->id);
            if (($filters['category'] ?? '') !== '') {
                $query->where('category', $filters['category']);
            }
            match ($filters['status'] ?? 'active') {
                'archived' => $query->whereNotNull('archived_at'),
                'all' => null,
                default => $query->whereNull('archived_at'),
            };
            [$column, $direction] = self::SORTS[$filters['sort'] ?? 'newest'] ?? self::SORTS['newest'];
            $query->orderByRaw("{$column} {$direction} NULLS LAST")->orderByDesc('created_at')->orderBy('id');

            $records = $query->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
            $facts = $this->listings->studentFacts($school, $student->id, $records->getCollection()->pluck('document_id')->all(), $actor);
            $rows = new LengthAwarePaginator(
                $records->getCollection()->map(fn (StudentDocument $r): array => $this->present($r, $facts[$r->document_id] ?? null))->all(),
                $records->total(),
                $records->perPage(),
                $records->currentPage(),
                ['path' => $records->path(), 'pageName' => 'page'],
            );

            return [
                'student' => $student,
                'documents' => $rows->withQueryString(),
                // SIS 11.5: the Student's derived requirement checklist (advisory).
                'compliance' => $this->compliance->forStudents($school, [$student->id])[$student->id] ?? [],
            ];
        });
    }

    /**
     * One record's file, for download: the Student is re-resolved under
     * whole-School visibility, the record must be THIS Student's, and
     * Documents re-decides `students.documents.view` and audits the opened
     * stream. Archived records stay downloadable to an authorized reader.
     */
    public function file(School $school, User $actor, string $studentId, string $recordId): DocumentContent
    {
        return $this->context->withSchool($school, function () use ($school, $actor, $studentId, $recordId): DocumentContent {
            $student = $this->records->visibleStudent($school, $actor, $studentId);
            $this->authorizeCapabilityFor($actor, DocumentService::STUDENT_DOCUMENTS_VIEW, $school);
            $record = Str::isUuid($recordId)
                ? StudentDocument::query()->where('school_id', $school->id)->where('student_id', $student->id)->find($recordId)
                : null;
            if ($record === null) {
                throw (new ModelNotFoundException)->setModel(StudentDocument::class, [$recordId]);
            }

            return $this->documents->studentContent($school, $student->id, $record->document_id, $actor);
        });
    }

    /**
     * The Student 360 card: counts only, one aggregate query; null (absent)
     * without `students.documents.view`.
     *
     * @return array{active: int, archived: int, categories: list<array{value: string, label: string, count: int}>, link: string}|null
     */
    public function summary(School $school, User $actor, Student $student): ?array
    {
        if (! Gate::forUser($actor)->allows('capability', [DocumentService::STUDENT_DOCUMENTS_VIEW, $school])) {
            return null;
        }

        $rows = $this->context->withSchool($school, fn () => StudentDocument::query()
            ->where('school_id', $school->id)->where('student_id', $student->id)
            ->groupBy('category')
            ->get(['category', DB::raw('count(*) FILTER (WHERE archived_at IS NULL) AS active'), DB::raw('count(*) FILTER (WHERE archived_at IS NOT NULL) AS archived')]));

        $active = [];
        foreach ($rows as $row) {
            $active[$row->category->value] = (int) $row->getAttribute('active');
        }

        return [
            'active' => array_sum($active),
            'archived' => (int) $rows->sum(fn ($row) => (int) $row->getAttribute('archived')),
            'categories' => array_values(array_filter(array_map(fn (StudentDocumentCategory $c): array => [
                'value' => $c->value,
                'label' => $c->label(),
                'count' => $active[$c->value] ?? 0,
            ], StudentDocumentCategory::cases()), fn (array $c): bool => $c['count'] > 0)),
            'link' => "/app/students/{$student->id}/documents",
        ];
    }

    /** @return array<string, mixed> */
    private function present(StudentDocument $record, ?DocumentMetadata $file): array
    {
        return [
            'id' => $record->id,
            'category' => $record->category->value,
            'categoryLabel' => $record->category->label(),
            'title' => $record->title,
            'documentDate' => $record->document_date?->toDateString(),
            'uploadedAt' => $record->created_at->toIso8601String(),
            'version' => $record->version,
            'file' => $file === null ? null : [
                'name' => $file->originalFilename,
                'mimeType' => $file->mimeType,
                'sizeBytes' => $file->sizeBytes,
            ],
            // SIS 11.5: verification (null = recorded before verification existed).
            'verification' => $record->verification_status === null ? null : [
                'status' => $record->verification_status->value,
                'label' => $record->verification_status->label(),
                'reason' => $record->rejection_reason?->value,
                'reasonLabel' => $record->rejection_reason?->label(),
                'at' => $record->verified_at?->toIso8601String(),
            ],
            'fromAdmission' => $record->source_applicant_document_id !== null,
            'archived' => $record->archived_at === null ? null : [
                'at' => $record->archived_at->toIso8601String(),
                'reason' => $record->archive_reason?->value,
                'reasonLabel' => $record->archive_reason?->label(),
            ],
        ];
    }
}
