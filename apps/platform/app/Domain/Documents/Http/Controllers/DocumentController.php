<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentListingQuery;
use App\Domain\Documents\Application\DocumentListingService;
use App\Domain\Documents\Application\DocumentMetadata;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentReadService;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Infrastructure\Document;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 0E.5 -- the Documents module's HTTP/API transport (ADR 0012,
 * docs/modules/DOCUMENTS.md). A thin adapter over the already-
 * authoritative 0E.2/0E.3/0E.4 Application services
 * (DocumentService/DocumentReadService/DocumentListingService) -- this
 * controller performs NO authorization, storage I/O, or audit writes
 * of its own; every one of those already lives in the service layer
 * and stays there (CLAUDE.md rule 3).
 *
 * Only the Employee, (Phase 0I.2) LearningContent, and (Phase 0I.3)
 * Assignment owner types are exposed here, exactly matching the
 * Application layer's own activation boundary -- there is no
 * Student/Guardian/Submission route.
 *
 * `{employee}`/`{document}` are always raw route-parameter strings,
 * never implicit Eloquent route-model binding -- the same pattern
 * `App\Domain\HR\Http\Controllers\EmployeeProfileController`/
 * `EmployeeSensitiveDocumentController` already established. A
 * malformed (non-UUID) id is rejected as the same tenant-safe 404 a
 * nonexistent/cross-School id already produces, via the identical
 * `Str::isUuid()` guard those controllers use -- otherwise Eloquent's
 * `find()` against a UUID-typed column raises a raw Postgres `invalid
 * input syntax for type uuid` (a 500), confirmed to be a real risk
 * here for the exact same reason the HR checkpoint documented it.
 *
 * 404 vs 403 policy (docs/modules/DOCUMENTS.md "404 vs 403"): every
 * Documents domain exception now carries its own `getStatusCode()`
 * (App\Domain\Documents\Application\Exceptions\DocumentException,
 * Phase 0E.5), read generically by bootstrap/app.php's existing
 * exception-render() closure -- this controller has no try/catch of
 * its own. `DocumentNotFoundException`/`DocumentOwnerNotFoundException`/
 * `DocumentOwnerTypeNotSupportedException` all map to 404
 * (non-enumerating); a capability failure inside a service
 * (`Illuminate\Auth\Access\AuthorizationException`) maps to the
 * existing global 403. This preserves 0E.3/0E.4's own established
 * confidentiality boundary exactly -- it is not redesigned here.
 */
class DocumentController extends Controller
{
    public function storeForEmployee(Request $request, School $school, string $employee): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $validated = $request->validate([
            'file' => ['required', 'file'],
            'classification_tier' => ['required', 'string'],
        ]);

        $document = app(DocumentService::class)->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee), $validated['classification_tier'], $validated['file']),
            $request->user(),
        );

        return response()->json(['data' => $this->presentCreated($document)], 201);
    }

    public function indexForEmployee(Request $request, School $school, string $employee): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $page = app(DocumentListingService::class)->list(
            $school, DocumentOwner::employee($employee), $request->user(), $this->listingQuery($request),
        );

        return $this->paginatedResponse($page);
    }

    /**
     * Phase 0I.2 -- the LearningContent owner-type counterpart of
     * `storeForEmployee()`. `classification_tier` is NOT accepted from
     * the request: a LearningContent-owned Document has exactly one
     * valid tier (`internal`, DocumentService's own docblock), so
     * exposing the field here would only ever let a caller supply the
     * one already-correct value or trigger a guaranteed 422 -- fixing
     * it removes an entire class of caller error rather than validating
     * around it.
     */
    public function storeForLearningContent(Request $request, School $school, string $learningContent): JsonResponse
    {
        abort_if(! Str::isUuid($learningContent), 404);

        $validated = $request->validate([
            'file' => ['required', 'file'],
        ]);

        $document = app(DocumentService::class)->create(
            $school,
            new CreateDocumentData(DocumentOwner::learningContent($learningContent), 'internal', $validated['file']),
            $request->user(),
        );

        return response()->json(['data' => $this->presentCreated($document)], 201);
    }

    public function indexForLearningContent(Request $request, School $school, string $learningContent): JsonResponse
    {
        abort_if(! Str::isUuid($learningContent), 404);

        $page = app(DocumentListingService::class)->list(
            $school, DocumentOwner::learningContent($learningContent), $request->user(), $this->listingQuery($request),
        );

        return $this->paginatedResponse($page);
    }

    /**
     * Phase 0I.3 -- the Assignment owner-type counterpart of
     * `storeForLearningContent()`. `classification_tier` is fixed to
     * `internal` server-side for the identical reason.
     */
    public function storeForAssignment(Request $request, School $school, string $assignment): JsonResponse
    {
        abort_if(! Str::isUuid($assignment), 404);

        $validated = $request->validate([
            'file' => ['required', 'file'],
        ]);

        $document = app(DocumentService::class)->create(
            $school,
            new CreateDocumentData(DocumentOwner::assignment($assignment), 'internal', $validated['file']),
            $request->user(),
        );

        return response()->json(['data' => $this->presentCreated($document)], 201);
    }

    public function indexForAssignment(Request $request, School $school, string $assignment): JsonResponse
    {
        abort_if(! Str::isUuid($assignment), 404);

        $page = app(DocumentListingService::class)->list(
            $school, DocumentOwner::assignment($assignment), $request->user(), $this->listingQuery($request),
        );

        return $this->paginatedResponse($page);
    }

    public function sensitiveIndexForEmployee(Request $request, School $school, string $employee): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $page = app(DocumentListingService::class)->listSensitive(
            $school, DocumentOwner::employee($employee), $request->user(), $this->listingQuery($request),
        );

        return $this->paginatedResponse($page);
    }

    public function show(Request $request, School $school, string $document): JsonResponse
    {
        abort_if(! Str::isUuid($document), 404);

        $metadata = app(DocumentReadService::class)->metadata($school, $document, $request->user());

        return response()->json(['data' => $metadata->toArray()]);
    }

    /**
     * Streams the Document's bytes via `response()->streamDownload()`
     * -- Laravel/Symfony's own framework-safe helper for exactly this
     * (never the Storage facade in this controller, gate 67): it builds the
     * `Content-Disposition` header through Symfony's
     * `HeaderUtils::makeDisposition()`, which percent-encodes/escapes
     * the filename (handles spaces, quotes, Unicode, and any control
     * character) rather than concatenating it into a raw header
     * string. `DocumentReadService::content()` has already opened the
     * stream and performed all authorization/audit before this method
     * ever runs -- a storage failure at open time throws
     * `DocumentContentUnavailableException` BEFORE any response
     * exists, so it always renders as a clean JSON error, never a 200
     * with a broken body. A failure that occurs mid-stream, after
     * headers have already been sent, cannot be converted into a JSON
     * error by this or any HTTP layer -- documented as a bounded,
     * accepted residual in docs/modules/DOCUMENTS.md, not hidden.
     *
     * `Content-Length` is set from the persisted `size_bytes` recorded
     * at upload time against the exact same object -- never
     * recomputed by buffering.
     */
    public function content(Request $request, School $school, string $document): StreamedResponse
    {
        abort_if(! Str::isUuid($document), 404);

        $content = app(DocumentReadService::class)->content($school, $document, $request->user());

        return response()->streamDownload(function () use ($content) {
            fpassthru($content->stream);

            if (is_resource($content->stream)) {
                fclose($content->stream);
            }
        }, $content->originalFilename, [
            'Content-Type' => $content->mimeType,
            'Content-Length' => (string) $content->sizeBytes,
        ], 'attachment');
    }

    /**
     * Resolves the Document tenant-safely (school-scoped `find()`,
     * identical to `DocumentReadService::resolveAuthorizedDocument()`'s
     * own pattern) BEFORE calling `DocumentService::archive()` -- never
     * `Document::findOrFail($documentId)` unscoped. 204, not a body,
     * to avoid ever needing a post-archive metadata read (which would
     * otherwise risk an unintended Highly Sensitive metadata-read
     * audit merely to build a response, gate 33/57).
     */
    public function archive(Request $request, School $school, string $document): Response
    {
        abort_if(! Str::isUuid($document), 404);

        $model = app(TenantContext::class)->withSchool(
            $school,
            fn () => Document::query()->where('school_id', $school->id)->find($document),
        );

        abort_if($model === null, 404);

        app(DocumentService::class)->archive($school, $model, $request->user());

        return response()->noContent();
    }

    private function listingQuery(Request $request): DocumentListingQuery
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return new DocumentListingQuery(
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? DocumentListingQuery::DEFAULT_PER_PAGE),
        );
    }

    private function paginatedResponse(LengthAwarePaginator $page): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (DocumentMetadata $entry) => $entry->toArray(), $page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Maps the raw `Document` model `DocumentService::create()`
     * returns into the same safe `DocumentMetadata` shape every other
     * endpoint returns -- deliberately NOT a call to
     * `DocumentReadService::metadata()`, which would risk an
     * unintended second `document.sensitive_metadata_viewed` audit
     * event for a Highly Sensitive upload merely to serialize the
     * create response (gate 16/17). Mirrors the identical private
     * `toMetadata()` mapping `DocumentReadService`/
     * `DocumentListingService` already each carry.
     */
    private function presentCreated(Document $document): array
    {
        return (new DocumentMetadata(
            documentId: $document->id,
            ownerType: $document->owner_type,
            ownerId: $document->employee_id ?? $document->student_id ?? $document->guardian_id ?? $document->learning_content_id ?? $document->assignment_id,
            classificationTier: $document->classification_tier,
            status: $document->status,
            originalFilename: $document->original_filename,
            mimeType: $document->mime_type,
            sizeBytes: $document->size_bytes,
            uploadedAt: $document->uploaded_at->toIso8601String(),
        ))->toArray();
    }
}
