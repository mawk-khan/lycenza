<?php

namespace App\Domain\Students\Application\Certificates;

use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Application\Exceptions\DocumentArchivedException;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\Students\Application\Documents\StudentDocumentArchiveReason;
use App\Domain\Students\Application\Documents\StudentDocumentCategory;
use App\Domain\Students\Application\Documents\StudentDocumentService;
use App\Domain\Students\Application\Exceptions\StudentCertificateAlreadyIssuedException;
use App\Domain\Students\Application\Exceptions\StudentCertificateChangedException;
use App\Domain\Students\Application\Exceptions\StudentCertificateFormReusedException;
use App\Domain\Students\Application\Exceptions\StudentCertificateNotEligibleException;
use App\Domain\Students\Application\Exceptions\StudentCertificateNotIssuedException;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentCertificate;
use App\Domain\Students\Infrastructure\StudentDocument;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * SIS.EXP-4 (OD-SIS-2): the one writer of Leaving/Transfer certificates.
 *
 * Every write needs, independently: the legal production gate
 * (StudentCertificateAvailability), a FreshMfaProof of the actor, whole-School
 * `students.view` (else NOT FOUND) and `students.certificates.issue` -- the
 * latter RE-READ fresh under the lock (holdsInSchoolNow), never the cache.
 *
 * Issue (one transaction):
 * 1. lock the Student (FOR NO KEY UPDATE: enrollment, transfer, rollover
 *    and status writers lock it too, so a new placement or a return cannot
 *    land underneath; withdraw/complete/cancel only end an active placement,
 *    which eligibility already refuses as `still_placed`);
 * 2. re-read authority; re-evaluate eligibility; refuse when the facts differ
 *    from what the issuer previewed (the preview fingerprint);
 * 3. allocate the next serial of the School + type counter (row lock; rolled
 *    back with the transaction, so a failed issue consumes nothing);
 * 4. render the PDF and store it through Documents'
 *    createCertificateForStudent(), whose `$within` writes the certificate,
 *    its `issued_certificate` Student document record and the audit -- a
 *    failure leaves no row and Documents' compensation removes the bytes (a
 *    failure only at the final commit leaves an unreferenced object, which the
 *    canonical orphan run removes).
 *
 * Idempotency: the issue form key is unique per School and bound to the
 * request (Student, type, preview, replaced certificate, person). A retry
 * -- sequential, or concurrent and waiting on the Student lock -- replays
 * the first certificate; anything else is refused.
 *
 * Known trade-off (review LOW): rendering and storing the PDF happen while
 * the Student lock and the School + type counter lock are held, because the
 * number is printed on the PDF. Issuance in one School is therefore
 * serialized for the render (~0.1 s) and the object write. Finance never
 * takes part: dues do not block issuance (OD-SIS-2.5).
 *
 * Void: ISSUED -> VOIDED once, with a closed reason; the row and its file
 * stay (the Student document record is archived as `certificate_voided`).
 * Reissue: void as `replaced_by_corrected_certificate` and issue the linked
 * replacement (a NEW serial and snapshot) in the same transaction.
 *
 * Audit metadata: ids, the type, serial, closed codes and the MFA time --
 * never a snapshot value.
 */
final class StudentCertificateService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentDocumentService $studentDocuments,
        private readonly StudentCertificateEligibility $eligibility,
        private readonly StudentCertificateRenderer $renderer,
        private readonly DocumentService $documents,
        private readonly CapabilityResolver $capabilities,
        private readonly AuditRecorder $audit,
    ) {}

    public function issue(School $school, User $actor, string $studentId, StudentCertificateType $type, string $previewFingerprint, string $formKey, FreshMfaProof $proof): StudentCertificate
    {
        return $this->write($school, $actor, $studentId, $type, $previewFingerprint, $formKey, $proof, null);
    }

    public function reissue(School $school, User $actor, string $studentId, string $certificateId, string $previewFingerprint, string $formKey, FreshMfaProof $proof): StudentCertificate
    {
        $prior = $this->context->withSchool($school, fn () => StudentCertificate::query()->where('school_id', $school->id)->where('student_id', $studentId)->find($certificateId))
            ?? throw (new ModelNotFoundException)->setModel(StudentCertificate::class, [$certificateId]);

        return $this->write($school, $actor, $studentId, $prior->certificate_type, $previewFingerprint, $formKey, $proof, $prior->id);
    }

    public function void(School $school, User $actor, string $studentId, string $certificateId, StudentCertificateVoidReason $reason, FreshMfaProof $proof): StudentCertificate
    {
        if (! in_array($reason, StudentCertificateVoidReason::plainVoid(), true)) {
            throw new LogicException('A replacement voids through reissue().');
        }

        return $this->context->withSchool($school, function () use ($school, $actor, $studentId, $certificateId, $reason, $proof): StudentCertificate {
            $this->preconditions($school, $actor, $proof);
            $student = $this->studentDocuments->visibleStudent($school, $actor, $studentId);

            return DB::transaction(function () use ($school, $actor, $student, $certificateId, $reason, $proof): StudentCertificate {
                $this->requireCurrentAuthority($school, $actor);

                return $this->voidLocked($school, $actor, $student, $certificateId, $reason, $proof, null);
            });
        });
    }

    /**
     * The preview: exactly the facts an issue would freeze (no number is
     * consumed, nothing is written), with the fingerprint the issue must echo.
     *
     * @return array{eligible: bool, refusal: ?string, types: list<StudentCertificateType>, sourceEnrollmentId: ?string, snapshot: ?array<string, ?string>, fingerprints: array<string, string>, printable: bool}
     */
    public function preview(School $school, User $actor, Student $student, ?string $supersedesId = null): array
    {
        return $this->context->withSchool($school, function () use ($school, $student, $supersedesId): array {
            $result = $this->eligibility->evaluate($school, $student);
            $fingerprints = [];
            $printable = true;
            if ($result['eligible']) {
                foreach ($result['types'] as $type) {
                    $fingerprints[$type->value] = StudentCertificateEligibility::fingerprint($result['snapshot'], $type, (string) $result['sourceEnrollmentId'], $supersedesId);
                }
                try {
                    StudentCertificateRenderer::assertPrintable($result['snapshot']);
                } catch (Throwable) {
                    $printable = false;
                }
            }

            return $result + ['fingerprints' => $fingerprints, 'printable' => $printable];
        });
    }

    private function write(School $school, User $actor, string $studentId, StudentCertificateType $type, string $previewFingerprint, string $formKey, FreshMfaProof $proof, ?string $supersedesId): StudentCertificate
    {
        return $this->context->withSchool($school, function () use ($school, $actor, $studentId, $type, $previewFingerprint, $formKey, $proof, $supersedesId): StudentCertificate {
            $this->preconditions($school, $actor, $proof);
            $student = $this->studentDocuments->visibleStudent($school, $actor, $studentId);
            if (! Str::isUuid($formKey)) {
                throw new StudentCertificateFormReusedException;
            }
            $requestFingerprint = hash('sha256', implode('|', [$student->id, $type->value, $previewFingerprint, (string) $supersedesId, $actor->id]));
            if (($existing = $this->claimed($school, $formKey)) !== null) {
                return $this->replay($existing, $requestFingerprint);
            }

            try {
                return DB::transaction(function () use ($school, $actor, $student, $type, $previewFingerprint, $formKey, $proof, $supersedesId, $requestFingerprint): StudentCertificate {
                    $locked = Student::query()->where('school_id', $school->id)->whereKey($student->id)->lock('for no key update')->firstOrFail();
                    $this->requireCurrentAuthority($school, $actor);
                    // A concurrent submit of the SAME form (a double click) waited
                    // on this lock: replay what it issued instead of refusing.
                    if (($winner = $this->claimed($school, $formKey)) !== null) {
                        return $this->replay($winner, $requestFingerprint);
                    }

                    if ($supersedesId !== null) {
                        $this->voidLocked($school, $actor, $locked, $supersedesId, StudentCertificateVoidReason::REPLACED_BY_CORRECTED_CERTIFICATE, $proof, $type);
                    }

                    $result = $this->eligibility->evaluate($school, $locked);
                    if (! $result['eligible']) {
                        throw new StudentCertificateNotEligibleException((string) $result['refusal']);
                    }
                    if (! in_array($type, $result['types'], true)) {
                        throw new StudentCertificateNotEligibleException('not_transferred_out');
                    }
                    $snapshot = $result['snapshot'];
                    $source = (string) $result['sourceEnrollmentId'];
                    // A replacement certifies the SAME exit as the certificate it replaces.
                    if ($supersedesId !== null && StudentCertificate::query()->whereKey($supersedesId)->value('source_enrollment_id') !== $source) {
                        throw new StudentCertificateNotEligibleException('other_exit');
                    }
                    if (! hash_equals(StudentCertificateEligibility::fingerprint($snapshot, $type, $source, $supersedesId), $previewFingerprint)) {
                        throw new StudentCertificateChangedException;
                    }
                    StudentCertificateRenderer::assertPrintable($snapshot);
                    if (StudentCertificate::query()->where('student_id', $locked->id)->where('certificate_type', $type)->where('source_enrollment_id', $source)->where('status', 'issued')->exists()) {
                        throw new StudentCertificateAlreadyIssuedException;
                    }

                    $serial = $this->allocate($school, $type);
                    $displayNumber = $type->displayNumber($serial);
                    $issuedOn = SchoolTimezone::today($school);
                    $bytes = $this->renderer->render($type, $displayNumber, $issuedOn, $snapshot);

                    $path = tempnam(sys_get_temp_dir(), 'certificate');
                    file_put_contents($path, $bytes);
                    $file = new UploadedFile($path, strtolower($type->label()).'-'.$displayNumber.'.pdf', 'application/pdf', null, true);

                    $certificate = null;
                    try {
                        // A failure inside $within rolls the Document back and
                        // Documents' compensation deletes the bytes; a failure
                        // only at the final commit leaves an unreferenced object
                        // for the canonical orphan run (platform:storage-orphans-prune).
                        $this->documents->createCertificateForStudent($school, $locked->id, $file, $actor, function (Document $document) use ($school, $actor, $locked, $type, $serial, $displayNumber, $issuedOn, $snapshot, $source, $formKey, $requestFingerprint, $proof, $supersedesId, &$certificate): void {
                            $record = new StudentDocument([
                                'category' => StudentDocumentCategory::ISSUED_CERTIFICATE,
                                'title' => $type->label().' '.$displayNumber,
                                'document_date' => $issuedOn,
                            ]);
                            $record->forceFill([
                                'school_id' => $school->id, 'student_id' => $locked->id, 'document_id' => $document->id,
                                'form_key' => (string) Str::uuid7(), 'upload_fingerprint' => hash('sha256', 'certificate|'.$document->id),
                                'uploaded_by_user_id' => $actor->id,
                            ])->save();

                            $certificate = new StudentCertificate;
                            $certificate->forceFill([
                                'school_id' => $school->id, 'student_id' => $locked->id, 'certificate_type' => $type,
                                'serial' => $serial, 'display_number' => $displayNumber, 'status' => 'issued',
                                'source_enrollment_id' => $source, 'document_id' => $document->id, 'supersedes_certificate_id' => $supersedesId,
                                ...$snapshot,
                                'issued_on' => $issuedOn, 'form_key' => $formKey, 'request_fingerprint' => $requestFingerprint,
                                'issued_by_user_id' => $actor->id,
                            ])->save();

                            $this->audit->school($school, 'student_certificate.issued', actor: $actor, subject: $locked, metadata: [
                                'certificateId' => $certificate->id,
                                'studentId' => $locked->id,
                                'type' => $type->value,
                                'serial' => $serial,
                                'sourceEnrollmentId' => $source,
                                'documentId' => $document->id,
                                'supersedesCertificateId' => $supersedesId,
                                'mfaVerifiedAt' => $proof->verifiedAt->toIso8601String(),
                            ]);
                        });
                    } finally {
                        @unlink($path);
                    }

                    /** @var StudentCertificate $certificate */
                    return $certificate;
                });
            } catch (UniqueConstraintViolationException) {
                // A concurrent retry of the same form won: replay it. Any other
                // collision (the one-issued-per-exit index) is a refusal.
                $winner = $this->claimed($school, $formKey);
                if ($winner !== null) {
                    return $this->replay($winner, $requestFingerprint);
                }

                throw new StudentCertificateAlreadyIssuedException;
            }
        });
    }

    /** Locks and voids one ISSUED certificate of this Student (inside the caller's transaction). */
    private function voidLocked(School $school, User $actor, Student $student, string $certificateId, StudentCertificateVoidReason $reason, FreshMfaProof $proof, ?StudentCertificateType $expectedType): StudentCertificate
    {
        $certificate = (Str::isUuid($certificateId) ? StudentCertificate::query()->where('school_id', $school->id)->where('student_id', $student->id)->lockForUpdate()->find($certificateId) : null)
            ?? throw (new ModelNotFoundException)->setModel(StudentCertificate::class, [$certificateId]);
        if (! $certificate->isIssued() || ($expectedType !== null && $certificate->certificate_type !== $expectedType)) {
            throw new StudentCertificateNotIssuedException;
        }

        // Lock order: the certificate, then its Document, then its record.
        // SIS.HARDEN-1 (R-7): a concurrent void waits on the certificate lock
        // above and then sees it void (409); its Document can never be
        // archived while the certificate is still issued, but if it were, that
        // is the same conflict, never NOT FOUND.
        try {
            $this->documents->archiveCertificateForStudent($school, $student->id, $certificate->document_id, $actor, function (Document $document) use ($school, $actor): void {
                $record = StudentDocument::query()->where('school_id', $school->id)->where('document_id', $document->id)->lockForUpdate()->firstOrFail();
                $record->forceFill([
                    'archived_at' => now(), 'archived_by_user_id' => $actor->id,
                    'archive_reason' => StudentDocumentArchiveReason::CERTIFICATE_VOIDED, 'version' => $record->version + 1,
                ])->save();
            });
        } catch (DocumentArchivedException) {
            throw new StudentCertificateNotIssuedException;
        }

        $certificate->forceFill([
            'status' => 'voided', 'voided_at' => now(), 'voided_by_user_id' => $actor->id,
            'void_reason' => $reason,
        ])->save();

        $this->audit->school($school, 'student_certificate.voided', actor: $actor, subject: $student, metadata: [
            'certificateId' => $certificate->id,
            'studentId' => $student->id,
            'type' => $certificate->certificate_type->value,
            'serial' => $certificate->serial,
            'reason' => $reason->value,
            'mfaVerifiedAt' => $proof->verifiedAt->toIso8601String(),
        ]);

        return $certificate;
    }

    /** The next serial of this School's counter for the type (row lock; part of the caller's transaction). */
    private function allocate(School $school, StudentCertificateType $type): int
    {
        DB::insert('INSERT INTO student_certificate_sequences (school_id, certificate_type, last_serial, created_at, updated_at) VALUES (?, ?, 0, now(), now()) ON CONFLICT (school_id, certificate_type) DO NOTHING', [$school->id, $type->value]);
        $next = 1 + (int) DB::selectOne('SELECT last_serial FROM student_certificate_sequences WHERE school_id = ? AND certificate_type = ? FOR UPDATE', [$school->id, $type->value])->last_serial;
        DB::update('UPDATE student_certificate_sequences SET last_serial = ?, updated_at = now() WHERE school_id = ? AND certificate_type = ?', [$next, $school->id, $type->value]);

        return $next;
    }

    private function preconditions(School $school, User $actor, FreshMfaProof $proof): void
    {
        StudentCertificateAvailability::assertAvailable();
        if (! $proof->isFor($actor->id)) {
            throw new LogicException('A fresh MFA proof belongs to the acting user only.');
        }
        $this->authorizeCapabilityFor($actor, DocumentService::STUDENT_CERTIFICATES_ISSUE, $school);
    }

    private function requireCurrentAuthority(School $school, User $actor): void
    {
        if (! $this->capabilities->holdsInSchoolNow($actor, DocumentService::STUDENT_CERTIFICATES_ISSUE, $school)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    /**
     * Reads the current database state (a concurrent retry may have won).
     *
     * @phpstan-impure
     */
    private function claimed(School $school, string $formKey): ?StudentCertificate
    {
        return StudentCertificate::query()->where('school_id', $school->id)->where('form_key', $formKey)->first();
    }

    private function replay(StudentCertificate $existing, string $requestFingerprint): StudentCertificate
    {
        if (! hash_equals($existing->request_fingerprint, $requestFingerprint)) {
            throw new StudentCertificateFormReusedException;
        }

        return $existing;
    }
}
