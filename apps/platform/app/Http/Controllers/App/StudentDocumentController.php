<?php

namespace App\Http\Controllers\App;

use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Application\Exceptions\DocumentTooLargeException;
use App\Domain\Documents\Application\Exceptions\DocumentTypeNotAllowedException;
use App\Domain\Students\Application\Documents\DocumentCompliance;
use App\Domain\Students\Application\Documents\DocumentRejectionReason;
use App\Domain\Students\Application\Documents\DocumentVerificationStatus;
use App\Domain\Students\Application\Documents\StudentDocumentArchiveReason;
use App\Domain\Students\Application\Documents\StudentDocumentCategory;
use App\Domain\Students\Application\Documents\StudentDocumentReader;
use App\Domain\Students\Application\Documents\StudentDocumentService;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Uid\UuidV7;

/**
 * SIS.EXP-3 (OD-SIS-1): a Student's record documents -- staff web only (no
 * API route exists). Thin: the route carries the capability floor
 * (`students.documents.view` / `.manage`, whole School), the reads need
 * current MFA (`mfa-page`; the files are Highly Sensitive), each write
 * re-verifies a fresh MFA code after that floor, and the Students/Documents
 * services decide Student visibility, document authority and the rest.
 */
class StudentDocumentController extends Controller
{
    public function index(Request $request, TenantContext $context, StudentDocumentReader $reader, FreshMfaRequirement $mfa, string $student): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $filters = $request->validate([
            'category' => ['nullable', Rule::in(StudentDocumentCategory::values())],
            'status' => ['nullable', Rule::in(StudentDocumentReader::STATUSES)],
            'sort' => ['nullable', Rule::in(array_keys(StudentDocumentReader::SORTS))],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        ['student' => $model, 'documents' => $documents, 'compliance' => $compliance] = $reader->page($school, $actor, $student, $filters, (int) ($filters['page'] ?? 1));

        return Inertia::render('App/Students/Documents', [
            'student' => [
                'id' => $model->id,
                'name' => trim(implode(' ', array_filter([$model->first_name, $model->middle_name, $model->last_name], fn (?string $p) => $p !== null && $p !== ''))),
                'studentNumber' => $model->student_number,
                'status' => $model->status,
            ],
            'documents' => $documents,
            // SIS 11.5: advisory requirement checklist (never a gate).
            'compliance' => $compliance,
            'complianceSummary' => DocumentCompliance::summary($compliance),
            'filters' => [
                'category' => $filters['category'] ?? '',
                'status' => $filters['status'] ?? 'active',
                'sort' => $filters['sort'] ?? 'newest',
            ],
            'options' => [
                'categories' => StudentDocumentCategory::options(),
                'filterCategories' => StudentDocumentCategory::filterOptions(),
                'archiveReasons' => StudentDocumentArchiveReason::options(),
                'rejectionReasons' => DocumentRejectionReason::options(),
                'requirable' => StudentDocumentCategory::requirableValues(),
            ],
            'canManage' => Gate::forUser($actor)->allows('capability', [DocumentService::STUDENT_DOCUMENTS_MANAGE, $school]),
            'canVerify' => Gate::forUser($actor)->allows('capability', [DocumentService::STUDENT_DOCUMENTS_VERIFY, $school]),
            'hasMfaFactor' => $mfa->hasActiveFactor($actor),
            'formKey' => (string) new UuidV7,
            'upload' => [
                'maxMb' => (int) config('documents.max_file_size_mb'),
                'accept' => '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png',
            ],
        ]);
    }

    public function store(Request $request, TenantContext $context, StudentDocumentService $service, FreshMfaRequirement $mfa, string $student): RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.((int) config('documents.max_file_size_mb') * 1024)],
            'category' => ['required', Rule::in(StudentDocumentCategory::uploadableValues())],
            'title' => ['required', 'string', 'max:255'],
            'document_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.SchoolTimezone::today($school)],
            'form_key' => ['required', 'uuid'],
            // SIS 11.5: a replacement supersedes a current document of the same type.
            'replaces' => ['nullable', 'uuid'],
        ], [
            'file.max' => 'The file can be at most '.config('documents.max_file_size_mb').' MB.',
        ]);
        $proof = $mfa->requireForAction($request, $actor);

        try {
            $service->upload($school, $actor, $student, $validated['file'], $validated, $validated['form_key'], $proof, $validated['replaces'] ?? null);
        } catch (DocumentTypeNotAllowedException) {
            throw ValidationException::withMessages(['file' => 'Upload a PDF, JPEG or PNG file. The file\'s content must match its extension.']);
        } catch (DocumentTooLargeException) {
            throw ValidationException::withMessages(['file' => 'The file can be at most '.config('documents.max_file_size_mb').' MB.']);
        }

        return redirect("/app/students/{$student}/documents")->with('status', 'Document uploaded.');
    }

    public function update(Request $request, TenantContext $context, StudentDocumentService $service, FreshMfaRequirement $mfa, string $student, string $document): RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $validated = $request->validate([
            'category' => ['required', Rule::in(StudentDocumentCategory::uploadableValues())],
            'title' => ['required', 'string', 'max:255'],
            'document_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.SchoolTimezone::today($school)],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $proof = $mfa->requireForAction($request, $actor);

        $service->update($school, $actor, $student, $document, $validated, (int) $validated['version'], $proof);

        return redirect("/app/students/{$student}/documents")->with('status', 'Document details saved.');
    }

    public function archive(Request $request, TenantContext $context, StudentDocumentService $service, FreshMfaRequirement $mfa, string $student, string $document): RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $validated = $request->validate([
            'reason' => ['required', Rule::in(StudentDocumentArchiveReason::choosableValues())],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $proof = $mfa->requireForAction($request, $actor);

        $service->archive($school, $actor, $student, $document, StudentDocumentArchiveReason::from($validated['reason']), (int) $validated['version'], $proof);

        return redirect("/app/students/{$student}/documents")->with('status', 'Document archived.');
    }

    /**
     * SIS 11.5: verify, or reject with a closed reason -- whole-School
     * `students.documents.verify` (route floor; the service re-decides it
     * under the record lock), a fresh MFA code, and the record version seen.
     */
    public function decide(Request $request, TenantContext $context, StudentDocumentService $service, FreshMfaRequirement $mfa, string $student, string $document): RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $validated = $request->validate([
            'decision' => ['required', Rule::in([DocumentVerificationStatus::VERIFIED->value, DocumentVerificationStatus::REJECTED->value])],
            'reason' => ['nullable', 'required_if:decision,rejected', 'prohibited_unless:decision,rejected', Rule::in(DocumentRejectionReason::values())],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $proof = $mfa->requireForAction($request, $actor);

        $decision = DocumentVerificationStatus::from($validated['decision']);
        $service->decide($school, $actor, $student, $document, $decision, isset($validated['reason']) ? DocumentRejectionReason::from($validated['reason']) : null, (int) $validated['version'], $proof);

        return redirect("/app/students/{$student}/documents")->with('status', $decision === DocumentVerificationStatus::VERIFIED ? 'Document verified.' : 'Document rejected.');
    }

    /**
     * Streams the file as an attachment (never inline), with the name built
     * by Symfony's HeaderUtils (escaped) and `nosniff`; every request
     * re-decides authority (reader + Documents), never the page visit.
     */
    public function download(TenantContext $context, StudentDocumentReader $reader, string $student, string $document): StreamedResponse
    {
        $content = $reader->file($context->requireSchool(), $context->actor(), $student, $document);

        return response()->streamDownload(function () use ($content) {
            fpassthru($content->stream);

            if (is_resource($content->stream)) {
                fclose($content->stream);
            }
        }, $content->originalFilename, [
            'Content-Type' => $content->mimeType,
            'Content-Length' => (string) $content->sizeBytes,
            'X-Content-Type-Options' => 'nosniff',
        ], 'attachment');
    }
}
