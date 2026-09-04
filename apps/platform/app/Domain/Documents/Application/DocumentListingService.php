<?php

namespace App\Domain\Documents\Application;

use App\Domain\Documents\Application\Exceptions\DocumentOwnerNotFoundException;
use App\Domain\Documents\Application\Exceptions\DocumentOwnerTypeNotSupportedException;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Phase 0E.4 -- owner-scoped Document discovery (ADR 0012,
 * docs/modules/DOCUMENTS.md). Deliberately a THIRD, separate class from
 * `DocumentService` (mutation) and `DocumentReadService` (known-id
 * direct access) -- listing is neither of those. Never implemented as
 * a loop calling `DocumentReadService::metadata()` per row (that would
 * mean N+1 queries, N authorization checks, and -- for Highly
 * Sensitive rows -- N audit events for what must be one logical list
 * operation); every operation here is a single bounded SQL query with
 * the classification predicate applied BEFORE `paginate()`, so a
 * hidden tier can never influence `total()`/page count/ordering.
 *
 * Two explicit, separately-authorized operations, mirroring the exact
 * split `App\Domain\HR\Application\EmployeeProfileWorkspaceService`
 * (ordinary) / `EmployeeSensitiveDocumentReadService` (Highly
 * Sensitive) already established -- never one operation that mixes
 * tiers according to actor privilege:
 *
 * - `list()`: `public`/`internal`/`sensitive` only, requires
 *   `hr.employees.documents.view` for the Employee owner type.
 *   `highly_sensitive` rows are excluded by the SQL `WHERE` clause
 *   itself, never filtered in PHP after the fact -- they never reach
 *   `total()`, never occupy a page slot, never affect ordering.
 * - `listSensitive()`: `highly_sensitive` only, requires
 *   `hr.employees.sensitive.view`. These two capabilities are treated
 *   as fully independent (never assumed to imply each other) --
 *   exactly like `DocumentReadService`'s/`DocumentService`'s own
 *   two-tier split.
 *
 * Archived Documents are never excluded from either operation --
 * matching the exact precedent both HR read services already
 * established (neither filters by `status`) and 0E.3's own direct-read
 * archived-access policy. No status filter exists on
 * `DocumentListingQuery` because no precedent for one exists.
 *
 * Only the Employee owner type is activated, for the identical
 * evidence-based reason 0E.2/0E.3 deferred Student/Guardian (see
 * `DocumentService`'s own docblock) -- `DocumentOwnerTypeNotSupportedException`
 * is thrown before any query, exactly like the write/read paths.
 */
class DocumentListingService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function list(School $school, DocumentOwner $owner, User $actor, DocumentListingQuery $query): LengthAwarePaginator
    {
        return $this->context->withSchool($school, function () use ($school, $owner, $actor, $query) {
            $ownerColumn = $this->resolveAndAuthorizeOwner($school, $owner, $actor, sensitive: false);

            $sqlQuery = Document::query()
                ->where('school_id', $school->id)
                ->where($ownerColumn['column'], $ownerColumn['id'])
                ->whereIn('classification_tier', ['public', 'internal', 'sensitive'])
                ->orderByDesc('uploaded_at')
                ->orderByDesc('id');

            return $this->paginate($sqlQuery, $query);
        });
    }

    public function listSensitive(School $school, DocumentOwner $owner, User $actor, DocumentListingQuery $query): LengthAwarePaginator
    {
        return $this->context->withSchool($school, function () use ($school, $owner, $actor, $query) {
            $ownerColumn = $this->resolveAndAuthorizeOwner($school, $owner, $actor, sensitive: true);

            $sqlQuery = Document::query()
                ->where('school_id', $school->id)
                ->where($ownerColumn['column'], $ownerColumn['id'])
                ->where('classification_tier', 'highly_sensitive')
                ->orderByDesc('uploaded_at')
                ->orderByDesc('id');

            $page = $this->paginate($sqlQuery, $query);

            // Phase 8A.10 precedent (EmployeeSensitiveDocumentReadService):
            // audit exactly once per successful call that actually returns
            // Highly Sensitive metadata -- never once per row, and never
            // for a call that returns zero rows (nothing sensitive was
            // actually exposed, so nothing to audit).
            if ($page->items() !== []) {
                $this->audit->school($school, 'document.sensitive_list_viewed', actor: $actor, subject: $ownerColumn['ownerModel'], metadata: [
                    'ownerType' => $owner->type,
                    'ownerId' => $owner->id,
                    'count' => count($page->items()),
                ]);
            }

            return $page;
        });
    }

    /**
     * @return array{column: string, id: string, ownerModel: Employee}
     */
    private function resolveAndAuthorizeOwner(School $school, DocumentOwner $owner, User $actor, bool $sensitive): array
    {
        return match ($owner->type) {
            'employee' => $this->resolveAndAuthorizeEmployeeOwner($school, $owner->id, $actor, $sensitive),
            'learning_content' => $this->resolveAndAuthorizeLearningContentOwner($school, $owner->id, $actor, $sensitive),
            'student', 'guardian' => throw new DocumentOwnerTypeNotSupportedException($owner->type),
            default => throw new DocumentOwnerTypeNotSupportedException($owner->type),
        };
    }

    /**
     * @return array{column: string, id: string, ownerModel: Employee}
     */
    private function resolveAndAuthorizeEmployeeOwner(School $school, string $employeeId, User $actor, bool $sensitive): array
    {
        $employee = Employee::query()->where('school_id', $school->id)->find($employeeId);

        if ($employee === null) {
            throw new DocumentOwnerNotFoundException('employee', $employeeId);
        }

        $this->authorizeCapabilityFor($actor, $sensitive ? 'hr.employees.sensitive.view' : 'hr.employees.documents.view', $school);

        return ['column' => 'employee_id', 'id' => $employee->id, 'ownerModel' => $employee];
    }

    /**
     * A LearningContent-owned Document only ever carries the single
     * `internal` tier (DocumentService's own docblock) -- there is
     * nothing for `listSensitive()` to ever return for this owner type,
     * so that path is rejected the same way an unsupported owner type
     * is, rather than silently returning an always-empty page.
     *
     * @return array{column: string, id: string, ownerModel: LearningContent}
     */
    private function resolveAndAuthorizeLearningContentOwner(School $school, string $learningContentId, User $actor, bool $sensitive): array
    {
        if ($sensitive) {
            throw new DocumentOwnerTypeNotSupportedException('learning_content');
        }

        $content = LearningContent::query()->where('school_id', $school->id)->find($learningContentId);

        if ($content === null) {
            throw new DocumentOwnerNotFoundException('learning_content', $learningContentId);
        }

        $this->authorizeCapabilityFor($actor, 'lms.content.view', $school);

        return ['column' => 'learning_content_id', 'id' => $content->id, 'ownerModel' => $content];
    }

    /**
     * @param  Builder<Document>  $sqlQuery
     */
    private function paginate(Builder $sqlQuery, DocumentListingQuery $query): LengthAwarePaginator
    {
        $paginator = $sqlQuery->paginate($query->perPage, ['*'], 'page', $query->page);

        return new LengthAwarePaginator(
            $paginator->getCollection()->map(fn (Document $d) => $this->toMetadata($d))->all(),
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
        );
    }

    private function toMetadata(Document $document): DocumentMetadata
    {
        return new DocumentMetadata(
            documentId: $document->id,
            ownerType: $document->owner_type,
            ownerId: $document->employee_id ?? $document->student_id ?? $document->guardian_id ?? $document->learning_content_id,
            classificationTier: $document->classification_tier,
            status: $document->status,
            originalFilename: $document->original_filename,
            mimeType: $document->mime_type,
            sizeBytes: $document->size_bytes,
            uploadedAt: $document->uploaded_at->toIso8601String(),
        );
    }
}
