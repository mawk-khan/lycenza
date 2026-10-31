<?php

namespace App\Domain\Admissions\Application;

use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\ApplicantDocument;
use App\Domain\Documents\Application\DocumentContent;
use App\Domain\Documents\Application\DocumentReadService;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Application\Exceptions\DocumentArchivedException;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\Students\Application\Documents\DocumentCompliance;
use App\Domain\Students\Application\Documents\DocumentRejectionReason;
use App\Domain\Students\Application\Documents\DocumentVerificationStatus;
use App\Domain\Students\Application\Documents\StudentDocumentArchiveReason;
use App\Domain\Students\Application\Documents\StudentDocumentCategory;
use App\Domain\Students\Application\Documents\StudentDocumentService;
use App\Domain\Students\Application\Exceptions\DocumentVerificationClosedException;
use App\Domain\Students\Application\Exceptions\StudentDocumentArchivedException;
use App\Domain\Students\Application\Exceptions\StudentDocumentChangedException;
use App\Domain\Students\Application\Exceptions\StudentDocumentFormReusedException;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SIS 11.5 (owner decisions 2026-10-31): pre-admission documents -- an
 * Applicant's evidence collected through one of its applications, in the
 * ONE Documents repository (an Applicant-owned Document; no second store).
 *
 * - Authority: `admissions.documents.view` / `.manage`, Campus-adopted with
 *   the application (AdmissionApplicationAccess: out of scope is NOT
 *   FOUND, exactly like another School's). Verifying or rejecting needs
 *   whole-School `students.documents.verify` too. Every write needs a fresh
 *   MFA proof (Highly Sensitive files, rule 95).
 * - A record is bound to the ONE application it was collected through
 *   (database key), so a Campus actor never sees what another Campus's
 *   application of the same Applicant collected.
 * - Only an OPEN application (draft, submitted, accepted) collects
 *   documents; after conversion they are the Student's (moveToStudent()),
 *   and admissions staff gain NO Student-document access from that.
 * - Replacement supersedes; archive and rejection are history, never
 *   deletion. Retention: with the Applicant (Admissions E21.3B/E21.3C).
 */
final class ApplicantDocumentService
{
    use AuthorizesCapability;

    private const array OPEN = ['draft', 'submitted', 'accepted'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AdmissionApplicationAccess $applications,
        private readonly CampusScopeResolver $scopes,
        private readonly DocumentService $documents,
        private readonly DocumentReadService $reads,
        private readonly StudentDocumentService $studentDocuments,
        private readonly DocumentCompliance $compliance,
        private readonly AuditRecorder $audit,
    ) {}

    /** The application when the actor holds `$capability` in its Campus, else NOT FOUND. */
    public function authorizeApplication(School $school, User $actor, string $applicationId, string $capability): AdmissionApplication
    {
        return $this->context->withSchool($school, fn () => $this->applications->application($this->scopes->campusScope($actor, $capability, $school), $applicationId));
    }

    /**
     * The application's documents (current and history) and its compliance.
     * A document that moved to the Student is the Student's now: only its
     * count is shown here (admissions staff gain no Student-document access).
     *
     * @return array{application: AdmissionApplication, documents: list<ApplicantDocument>, movedCount: int, compliance: list<array<string, mixed>>}
     */
    public function page(School $school, User $actor, string $applicationId): array
    {
        $application = $this->authorizeApplication($school, $actor, $applicationId, DocumentService::ADMISSIONS_DOCUMENTS_VIEW);

        return $this->context->withSchool($school, fn () => [
            'application' => $application->loadMissing(['applicant', 'academicYear', 'campus', 'gradeLevel']),
            'documents' => ApplicantDocument::query()->where('admission_application_id', $application->id)->whereNull('moved_to_student_at')->orderByDesc('created_at')->orderByDesc('id')->get()->all(),
            'movedCount' => ApplicantDocument::query()->where('admission_application_id', $application->id)->whereNotNull('moved_to_student_at')->count(),
            'compliance' => in_array($application->status, self::OPEN, true)
                ? $this->compliance->forApplication($school, $application->academic_year_id, $application->campus_id, $application->grade_level_id, $this->currentByCategory($application))
                : [],
        ]);
    }

    /** @param  array{category: string, title: string, document_date?: ?string}  $data */
    public function upload(School $school, User $actor, string $applicationId, UploadedFile $file, array $data, string $formKey, FreshMfaProof $proof, ?string $replacesId = null): ApplicantDocument
    {
        $this->assertProof($proof, $actor);
        $application = $this->authorizeApplication($school, $actor, $applicationId, DocumentService::ADMISSIONS_DOCUMENTS_MANAGE);
        $this->assertOpen($application);
        $category = StudentDocumentCategory::tryFrom($data['category']);
        if ($category === null || ! $category->isRequirable()) {
            throw ValidationException::withMessages(['category' => 'Choose a document type.']);
        }
        $title = StudentDocumentService::normalizeTitle($data['title']);
        if (! Str::isUuid($formKey)) {
            throw new StudentDocumentFormReusedException;
        }
        $fingerprint = hash('sha256', implode("\n", [$category->value, $title, (string) ($data['document_date'] ?? null), hash_file('sha256', (string) $file->getRealPath()) ?: '']));

        return $this->context->withSchool($school, function () use ($school, $actor, $application, $file, $data, $formKey, $category, $title, $fingerprint, $replacesId): ApplicantDocument {
            if (($existing = $this->claimed($formKey)) !== null) {
                return $this->replay($existing, $application, $actor, $fingerprint);
            }
            try {
                $record = null;
                $this->documents->createForApplicant($school, $application->applicant_id, $file, $actor, function (Document $document) use ($school, $actor, $application, $data, $formKey, $category, $title, $fingerprint, $replacesId, &$record): void {
                    if ($replacesId !== null) {
                        $this->supersede($school, $actor, $application, $replacesId, $category);
                    }
                    $record = new ApplicantDocument(['category' => $category, 'title' => $title, 'document_date' => $data['document_date'] ?? null]);
                    $record->forceFill([
                        'school_id' => $school->id,
                        'applicant_id' => $application->applicant_id,
                        'admission_application_id' => $application->id,
                        'document_id' => $document->id,
                        'form_key' => $formKey,
                        'upload_fingerprint' => $fingerprint,
                        'uploaded_by_user_id' => $actor->id,
                        'verification_status' => DocumentVerificationStatus::PENDING,
                    ])->save();
                    $this->audit->school($school, 'applicant_document.uploaded', actor: $actor, subject: $application, metadata: [
                        'applicationId' => $application->id,
                        'applicantId' => $application->applicant_id,
                        'applicantDocumentId' => $record->id,
                        'documentId' => $document->id,
                        'category' => $category->value,
                        'replacesApplicantDocumentId' => $replacesId,
                    ]);
                });

                /** @var ApplicantDocument $record */
                return $record;
            } catch (UniqueConstraintViolationException) {
                return $this->replay($this->claimed($formKey) ?? throw new StudentDocumentFormReusedException, $application, $actor, $fingerprint);
            }
        });
    }

    /** Verify, or reject with a closed reason: whole-School `students.documents.verify` too. */
    public function decide(School $school, User $actor, string $applicationId, string $recordId, DocumentVerificationStatus $decision, ?DocumentRejectionReason $reason, int $expectedVersion, FreshMfaProof $proof): ApplicantDocument
    {
        $this->assertProof($proof, $actor);
        $application = $this->authorizeApplication($school, $actor, $applicationId, DocumentService::ADMISSIONS_DOCUMENTS_VIEW);
        $this->authorizeCapabilityFor($actor, DocumentService::STUDENT_DOCUMENTS_VERIFY, $school);
        $this->assertOpen($application, 'refusal');
        if ($decision === DocumentVerificationStatus::PENDING || ($decision === DocumentVerificationStatus::REJECTED) !== ($reason !== null)) {
            throw new \InvalidArgumentException('A decision is verified, or rejected with a reason.');
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $actor, $application, $recordId, $decision, $reason, $expectedVersion): ApplicantDocument {
            $record = $this->locked($application, $recordId);
            if ($record->version !== $expectedVersion) {
                throw new StudentDocumentChangedException;
            }
            if ($record->verification_status !== DocumentVerificationStatus::PENDING) {
                throw new DocumentVerificationClosedException;
            }
            $record->forceFill([
                'verification_status' => $decision,
                'verified_by_user_id' => $actor->id,
                'verified_at' => now(),
                'rejection_reason' => $reason,
                'version' => $record->version + 1,
            ])->save();
            $this->audit->school($school, $decision === DocumentVerificationStatus::VERIFIED ? 'applicant_document.verified' : 'applicant_document.rejected', actor: $actor, subject: $application, metadata: [
                'applicationId' => $application->id,
                'applicantDocumentId' => $record->id,
                'category' => $record->category->value,
                'reason' => $reason?->value,
            ]);

            return $record;
        }));
    }

    public function archive(School $school, User $actor, string $applicationId, string $recordId, StudentDocumentArchiveReason $reason, int $expectedVersion, FreshMfaProof $proof): ApplicantDocument
    {
        $this->assertProof($proof, $actor);
        $application = $this->authorizeApplication($school, $actor, $applicationId, DocumentService::ADMISSIONS_DOCUMENTS_MANAGE);
        $this->assertOpen($application, 'refusal');

        return $this->context->withSchool($school, function () use ($school, $actor, $application, $recordId, $reason, $expectedVersion): ApplicantDocument {
            $current = ApplicantDocument::query()->where('admission_application_id', $application->id)->find($recordId)
                ?? throw (new ModelNotFoundException)->setModel(ApplicantDocument::class, [$recordId]);
            if ($current->isFinal()) {
                throw new StudentDocumentArchivedException;
            }
            $record = null;
            try {
                $this->documents->archiveForApplicant($school, $application->applicant_id, $current->document_id, function () use ($school, $actor, $application, $recordId, $reason, $expectedVersion, &$record): void {
                    $record = $this->locked($application, $recordId);
                    if ($record->version !== $expectedVersion) {
                        throw new StudentDocumentChangedException;
                    }
                    $this->markArchived($record, $actor, $reason);
                    $this->audit->school($school, 'applicant_document.archived', actor: $actor, subject: $application, metadata: [
                        'applicationId' => $application->id,
                        'applicantDocumentId' => $record->id,
                        'reason' => $reason->value,
                    ]);
                });
            } catch (DocumentArchivedException) {
                throw new StudentDocumentArchivedException;
            }

            /** @var ApplicantDocument $record */
            return $record;
        });
    }

    /** The file of one of the application's documents (re-authorized and audited on every request). */
    public function file(School $school, User $actor, string $applicationId, string $recordId): DocumentContent
    {
        $application = $this->authorizeApplication($school, $actor, $applicationId, DocumentService::ADMISSIONS_DOCUMENTS_VIEW);
        $record = $this->context->withSchool($school, fn () => ApplicantDocument::query()->where('admission_application_id', $application->id)->whereNull('moved_to_student_at')->find($recordId))
            ?? throw (new ModelNotFoundException)->setModel(ApplicantDocument::class, [$recordId]);

        return $this->reads->applicantContent($school, $application->applicant_id, $record->document_id, $actor);
    }

    /**
     * Inside the conversion's transaction: the Applicant's CURRENT documents
     * become the new Student's -- the same Document rows and bytes (Documents
     * re-points the owner), a Student record carrying the verification, and
     * this record marked moved (final). History (archived, rejected-and-
     * replaced) stays on the Applicant. Returns how many moved.
     */
    public function moveToStudent(School $school, AdmissionApplication $application, string $studentId, ?User $actor): int
    {
        $records = ApplicantDocument::query()->where('admission_application_id', $application->id)
            ->whereNull('archived_at')->whereNull('moved_to_student_at')->orderBy('id')->lockForUpdate()->get();
        if ($records->isEmpty()) {
            return 0;
        }

        $this->documents->moveApplicantDocumentsToStudent($school, $application->applicant_id, $studentId, $records->pluck('document_id')->all());
        foreach ($records as $record) {
            $this->studentDocuments->adoptFromApplicant($school, $studentId, [
                'id' => $record->id,
                'document_id' => $record->document_id,
                'category' => $record->category,
                'title' => $record->title,
                'document_date' => $record->document_date,
                'upload_fingerprint' => $record->upload_fingerprint,
                'uploaded_by_user_id' => $record->uploaded_by_user_id,
                'verification_status' => $record->verification_status,
                'verified_by_user_id' => $record->verified_by_user_id,
                'verified_at' => $record->verified_at,
                'rejection_reason' => $record->rejection_reason,
            ]);
            $record->forceFill(['moved_to_student_at' => now(), 'moved_to_student_id' => $studentId, 'version' => $record->version + 1])->save();
        }
        $this->audit->school($school, 'applicant_document.moved_to_student', actor: $actor, subject: $application, metadata: [
            'applicationId' => $application->id,
            'studentId' => $studentId,
            'count' => $records->count(),
        ]);

        return $records->count();
    }

    /**
     * The newest current (not archived, not moved) record per category, for compliance.
     *
     * @return array<string, array{id: string, status: ?string}>
     */
    private function currentByCategory(AdmissionApplication $application): array
    {
        $out = [];
        foreach (ApplicantDocument::query()->where('admission_application_id', $application->id)->whereNull('archived_at')->whereNull('moved_to_student_at')
            ->orderByDesc('created_at')->orderByDesc('id')->get(['id', 'category', 'verification_status']) as $record) {
            $out[$record->category->value] ??= ['id' => $record->id, 'status' => $record->verification_status->value];
        }

        return $out;
    }

    private function supersede(School $school, User $actor, AdmissionApplication $application, string $replacesId, StudentDocumentCategory $category): void
    {
        $old = ApplicantDocument::query()->where('admission_application_id', $application->id)->find($replacesId);
        if ($old === null || $old->isFinal() || $old->category !== $category) {
            throw ValidationException::withMessages(['replaces' => 'A replacement keeps the same document type, and replaces a current document.']);
        }
        $this->documents->archiveForApplicant($school, $application->applicant_id, $old->document_id, function () use ($actor, $application, $replacesId): void {
            $this->markArchived($this->locked($application, $replacesId), $actor, StudentDocumentArchiveReason::SUPERSEDED);
        });
    }

    private function markArchived(ApplicantDocument $record, User $actor, StudentDocumentArchiveReason $reason): void
    {
        $record->forceFill(['archived_at' => now(), 'archived_by_user_id' => $actor->id, 'archive_reason' => $reason, 'version' => $record->version + 1])->save();
    }

    private function locked(AdmissionApplication $application, string $recordId): ApplicantDocument
    {
        $record = ApplicantDocument::query()->where('admission_application_id', $application->id)->lockForUpdate()->find($recordId)
            ?? throw (new ModelNotFoundException)->setModel(ApplicantDocument::class, [$recordId]);
        if ($record->isFinal()) {
            throw new StudentDocumentArchivedException;
        }

        return $record;
    }

    /** Documents are collected, decided and archived only while the application is open; afterwards they are history. */
    private function assertOpen(AdmissionApplication $application, string $field = 'file'): void
    {
        if (! in_array($application->status, self::OPEN, true)) {
            throw ValidationException::withMessages([$field => 'Documents are collected and reviewed only while the application is open (draft, submitted or accepted).']);
        }
    }

    /** @phpstan-impure */
    private function claimed(string $formKey): ?ApplicantDocument
    {
        return ApplicantDocument::query()->where('form_key', $formKey)->first();
    }

    private function replay(ApplicantDocument $existing, AdmissionApplication $application, User $actor, string $fingerprint): ApplicantDocument
    {
        if ($existing->admission_application_id !== $application->id || $existing->uploaded_by_user_id !== $actor->id
            || ! hash_equals($existing->upload_fingerprint, $fingerprint)) {
            throw new StudentDocumentFormReusedException;
        }

        return $existing;
    }

    private function assertProof(FreshMfaProof $proof, User $actor): void
    {
        if (! $proof->isFor($actor->id)) {
            throw new \LogicException('A fresh MFA proof belongs to the acting user only.');
        }
    }
}
