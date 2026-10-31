<?php

namespace App\Http\Controllers\App;

use App\Domain\Admissions\Application\ApplicantDocumentService;
use App\Domain\Admissions\Infrastructure\ApplicantDocument;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Application\Exceptions\DocumentTooLargeException;
use App\Domain\Documents\Application\Exceptions\DocumentTypeNotAllowedException;
use App\Domain\Students\Application\Documents\DocumentCompliance;
use App\Domain\Students\Application\Documents\DocumentRejectionReason;
use App\Domain\Students\Application\Documents\DocumentVerificationStatus;
use App\Domain\Students\Application\Documents\StudentDocumentArchiveReason;
use App\Domain\Students\Application\Documents\StudentDocumentCategory;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScopeResolver;
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
 * SIS 11.5: pre-admission documents of one application -- staff web only
 * (no API route). Thin: each action resolves the actor's Campus scope for
 * `admissions.documents.view` / `.manage` (ORG.7D-3: the application's own
 * Campus), the route adds current MFA for reads (Highly Sensitive files),
 * each write re-verifies a fresh MFA code, and ApplicantDocumentService
 * decides the application (out of scope = NOT FOUND), its state and the
 * whole-School `students.documents.verify` for a decision.
 */
class ApplicantDocumentController extends Controller
{
    use AuthorizesCampusScope;

    public function index(TenantContext $context, ApplicantDocumentService $service, CampusScopeResolver $scopes, FreshMfaRequirement $mfa, string $admissionApplication): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $this->authorizeCampusScope(DocumentService::ADMISSIONS_DOCUMENTS_VIEW, $school);

        ['application' => $application, 'documents' => $documents, 'movedCount' => $movedCount, 'compliance' => $compliance] = $service->page($school, $actor, $admissionApplication);
        $open = in_array($application->status, ['draft', 'submitted', 'accepted'], true);
        $applicant = $application->applicant;

        return Inertia::render('App/Admissions/Documents', [
            'application' => [
                'id' => $application->id,
                'status' => $application->status,
                'applicantName' => trim(implode(' ', array_filter([$applicant?->first_name, $applicant?->middle_name, $applicant?->last_name], fn (?string $p) => $p !== null && $p !== ''))),
                'applyingFor' => implode(' · ', array_filter([$application->academicYear?->name, $application->campus?->name, $application->gradeLevel?->name])),
                'open' => $open,
            ],
            'documents' => array_map(fn (ApplicantDocument $d) => $this->present($d), $documents),
            'movedCount' => $movedCount,
            'compliance' => $compliance,
            'complianceSummary' => DocumentCompliance::summary($compliance),
            'options' => [
                'categories' => StudentDocumentCategory::requirableOptions(),
                'archiveReasons' => StudentDocumentArchiveReason::options(),
                'rejectionReasons' => DocumentRejectionReason::options(),
            ],
            'canManage' => $open && $scopes->campusScope($actor, DocumentService::ADMISSIONS_DOCUMENTS_MANAGE, $school)->covers($application->campus_id),
            'canVerify' => $open && Gate::forUser($actor)->allows('capability', [DocumentService::STUDENT_DOCUMENTS_VERIFY, $school]),
            'hasMfaFactor' => $mfa->hasActiveFactor($actor),
            'formKey' => (string) new UuidV7,
            'upload' => [
                'maxMb' => (int) config('documents.max_file_size_mb'),
                'accept' => '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png',
            ],
        ]);
    }

    public function store(Request $request, TenantContext $context, ApplicantDocumentService $service, FreshMfaRequirement $mfa, string $admissionApplication): RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $this->authorizeCampusScope(DocumentService::ADMISSIONS_DOCUMENTS_MANAGE, $school);
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.((int) config('documents.max_file_size_mb') * 1024)],
            'category' => ['required', Rule::in(StudentDocumentCategory::requirableValues())],
            'title' => ['required', 'string', 'max:255'],
            'document_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.SchoolTimezone::today($school)],
            'form_key' => ['required', 'uuid'],
            'replaces' => ['nullable', 'uuid'],
        ], [
            'file.max' => 'The file can be at most '.config('documents.max_file_size_mb').' MB.',
        ]);
        $proof = $mfa->requireForAction($request, $actor);

        try {
            $service->upload($school, $actor, $admissionApplication, $validated['file'], $validated, $validated['form_key'], $proof, $validated['replaces'] ?? null);
        } catch (DocumentTypeNotAllowedException) {
            throw ValidationException::withMessages(['file' => 'Upload a PDF, JPEG or PNG file. The file\'s content must match its extension.']);
        } catch (DocumentTooLargeException) {
            throw ValidationException::withMessages(['file' => 'The file can be at most '.config('documents.max_file_size_mb').' MB.']);
        }

        return redirect("/app/admissions/{$admissionApplication}/documents")->with('status', 'Document uploaded.');
    }

    public function decide(Request $request, TenantContext $context, ApplicantDocumentService $service, FreshMfaRequirement $mfa, string $admissionApplication, string $document): RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $this->authorizeCampusScope(DocumentService::ADMISSIONS_DOCUMENTS_VIEW, $school);
        $validated = $request->validate([
            'decision' => ['required', Rule::in([DocumentVerificationStatus::VERIFIED->value, DocumentVerificationStatus::REJECTED->value])],
            'reason' => ['nullable', 'required_if:decision,rejected', 'prohibited_unless:decision,rejected', Rule::in(DocumentRejectionReason::values())],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $proof = $mfa->requireForAction($request, $actor);

        $decision = DocumentVerificationStatus::from($validated['decision']);
        $service->decide($school, $actor, $admissionApplication, $document, $decision, isset($validated['reason']) ? DocumentRejectionReason::from($validated['reason']) : null, (int) $validated['version'], $proof);

        return redirect("/app/admissions/{$admissionApplication}/documents")->with('status', $decision === DocumentVerificationStatus::VERIFIED ? 'Document verified.' : 'Document rejected.');
    }

    public function archive(Request $request, TenantContext $context, ApplicantDocumentService $service, FreshMfaRequirement $mfa, string $admissionApplication, string $document): RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $this->authorizeCampusScope(DocumentService::ADMISSIONS_DOCUMENTS_MANAGE, $school);
        $validated = $request->validate([
            'reason' => ['required', Rule::in(StudentDocumentArchiveReason::choosableValues())],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $proof = $mfa->requireForAction($request, $actor);

        $service->archive($school, $actor, $admissionApplication, $document, StudentDocumentArchiveReason::from($validated['reason']), (int) $validated['version'], $proof);

        return redirect("/app/admissions/{$admissionApplication}/documents")->with('status', 'Document archived.');
    }

    /** An attachment (never inline), re-authorized and audited on every request. */
    public function download(TenantContext $context, ApplicantDocumentService $service, string $admissionApplication, string $document): StreamedResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCampusScope(DocumentService::ADMISSIONS_DOCUMENTS_VIEW, $school);
        $content = $service->file($school, $context->actor(), $admissionApplication, $document);

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

    /** @return array<string, mixed> */
    private function present(ApplicantDocument $d): array
    {
        return [
            'id' => $d->id,
            'category' => $d->category->value,
            'categoryLabel' => $d->category->label(),
            'title' => $d->title,
            'documentDate' => $d->document_date?->toDateString(),
            'uploadedAt' => $d->created_at->toIso8601String(),
            'version' => $d->version,
            'verification' => [
                'status' => $d->verification_status->value,
                'label' => $d->verification_status->label(),
                'reasonLabel' => $d->rejection_reason?->label(),
                'at' => $d->verified_at?->toIso8601String(),
            ],
            'archived' => $d->archived_at === null ? null : ['at' => $d->archived_at->toIso8601String(), 'reasonLabel' => $d->archive_reason?->label()],
        ];
    }
}
