<?php

namespace App\Domain\Students\Application\Documents;

use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\Students\Application\Exceptions\StudentDocumentArchivedException;
use App\Domain\Students\Application\Exceptions\StudentDocumentChangedException;
use App\Domain\Students\Application\Exceptions\StudentDocumentFormReusedException;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentDocument;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SIS.EXP-3 (OD-SIS-1): the one writer of Student document records.
 *
 * Every write needs, independently:
 * - Student visibility: whole-School `students.view` (StudentAccess), then
 *   the Student in this School -- anything else is NOT FOUND;
 * - document authority: whole-School `students.documents.manage`, decided
 *   here and again by Documents for the file itself;
 * - a FreshMfaProof (the files are Highly Sensitive, rule 95; the same
 *   step-up HR applies to its Highly Sensitive document records).
 *
 * The bytes and the file's own status go through Documents'
 * createForStudent()/archiveForStudent(); this record is written inside the
 * same transaction, so a failure leaves neither a row nor an object behind.
 * An upload is claimed on its server-issued form key (unique per School):
 * a retry by the same person, for the same Student, with the same content
 * (fingerprint) replays the first result and writes nothing; anything else
 * -- including the same form re-sent with a different file -- is refused.
 *
 * Audits name ids, the category and changed field NAMES -- never a title,
 * file name or content.
 */
final class StudentDocumentService
{
    use AuthorizesCapability;

    public const int TITLE_MAX = 120;

    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentAccess $access,
        private readonly DocumentService $documents,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{category: string, title: string, document_date?: ?string}  $data
     */
    public function upload(School $school, User $actor, string $studentId, UploadedFile $file, array $data, string $formKey, FreshMfaProof $proof): StudentDocument
    {
        return $this->context->withSchool($school, function () use ($school, $actor, $studentId, $file, $data, $formKey, $proof): StudentDocument {
            $this->assertProof($proof, $actor);
            $student = $this->visibleStudent($school, $actor, $studentId);
            $this->authorizeCapabilityFor($actor, DocumentService::STUDENT_DOCUMENTS_MANAGE, $school);
            $category = $this->category($data['category']);
            $title = self::normalizeTitle($data['title']);
            if (! Str::isUuid($formKey)) {
                throw new StudentDocumentFormReusedException;
            }

            // A replay answers before any I/O; the unique key below is the guarantee.
            $fingerprint = self::fingerprint($category, $title, $data['document_date'] ?? null, $file);
            if (($existing = $this->claimed($school, $formKey)) !== null) {
                return $this->replay($existing, $student, $actor, $fingerprint);
            }

            try {
                $record = null;
                $this->documents->createForStudent($school, $student->id, $file, $actor, function (Document $document) use ($school, $actor, $student, $category, $title, $data, $formKey, $fingerprint, &$record): void {
                    $record = new StudentDocument([
                        'category' => $category,
                        'title' => $title,
                        'document_date' => $data['document_date'] ?? null,
                    ]);
                    $record->forceFill([
                        'school_id' => $school->id,
                        'student_id' => $student->id,
                        'document_id' => $document->id,
                        'form_key' => $formKey,
                        'upload_fingerprint' => $fingerprint,
                        'uploaded_by_user_id' => $actor->id,
                    ])->save();

                    $this->audit->school($school, 'student_document.uploaded', actor: $actor, subject: $student, metadata: [
                        'studentId' => $student->id,
                        'studentDocumentId' => $record->id,
                        'documentId' => $document->id,
                        'category' => $category->value,
                    ]);
                });

                /** @var StudentDocument $record */
                return $record;
            } catch (UniqueConstraintViolationException) {
                // A concurrent retry won the form key: Documents' compensation
                // already removed this attempt's bytes.
                return $this->replay($this->claimed($school, $formKey) ?? throw new StudentDocumentFormReusedException, $student, $actor, $fingerprint);
            }
        });
    }

    /**
     * @param  array{category: string, title: string, document_date?: ?string}  $data
     */
    public function update(School $school, User $actor, string $studentId, string $recordId, array $data, int $expectedVersion, FreshMfaProof $proof): StudentDocument
    {
        return $this->context->withSchool($school, function () use ($school, $actor, $studentId, $recordId, $data, $expectedVersion, $proof): StudentDocument {
            $this->assertProof($proof, $actor);
            $student = $this->visibleStudent($school, $actor, $studentId);
            $this->authorizeCapabilityFor($actor, DocumentService::STUDENT_DOCUMENTS_MANAGE, $school);
            $category = $this->category($data['category']);
            $title = self::normalizeTitle($data['title']);

            return DB::transaction(function () use ($school, $actor, $student, $recordId, $category, $title, $data, $expectedVersion): StudentDocument {
                $record = $this->lockedRecord($school, $student, $recordId);
                if ($record->version !== $expectedVersion) {
                    throw new StudentDocumentChangedException;
                }

                $record->fill(['category' => $category, 'title' => $title, 'document_date' => $data['document_date'] ?? null]);
                $changed = array_keys($record->getDirty());
                if ($changed === []) {
                    return $record;
                }
                $record->version = $record->version + 1;
                $record->save();

                $this->audit->school($school, 'student_document.updated', actor: $actor, subject: $student, metadata: [
                    'studentId' => $student->id,
                    'studentDocumentId' => $record->id,
                    'changedFields' => $changed,
                ]);

                return $record;
            });
        });
    }

    public function archive(School $school, User $actor, string $studentId, string $recordId, StudentDocumentArchiveReason $reason, int $expectedVersion, FreshMfaProof $proof): StudentDocument
    {
        return $this->context->withSchool($school, function () use ($school, $actor, $studentId, $recordId, $reason, $expectedVersion, $proof): StudentDocument {
            $this->assertProof($proof, $actor);
            $student = $this->visibleStudent($school, $actor, $studentId);
            $this->authorizeCapabilityFor($actor, DocumentService::STUDENT_DOCUMENTS_MANAGE, $school);

            $current = StudentDocument::query()->where('school_id', $school->id)->where('student_id', $student->id)->find($recordId)
                ?? throw (new ModelNotFoundException)->setModel(StudentDocument::class, [$recordId]);
            if ($current->isArchived()) {
                throw new StudentDocumentArchivedException;
            }

            $record = null;
            // Lock order: the Document (Documents), then this record.
            $this->documents->archiveForStudent($school, $student->id, $current->document_id, $actor, function () use ($school, $actor, $student, $recordId, $reason, $expectedVersion, &$record): void {
                $record = $this->lockedRecord($school, $student, $recordId);
                if ($record->version !== $expectedVersion) {
                    throw new StudentDocumentChangedException;
                }

                $record->forceFill([
                    'archived_at' => now(),
                    'archived_by_user_id' => $actor->id,
                    'archive_reason' => $reason,
                    'version' => $record->version + 1,
                ])->save();

                $this->audit->school($school, 'student_document.archived', actor: $actor, subject: $student, metadata: [
                    'studentId' => $student->id,
                    'studentDocumentId' => $record->id,
                    'documentId' => $record->document_id,
                    'reason' => $reason->value,
                ]);
            });

            /** @var StudentDocument $record */
            return $record;
        });
    }

    /** What a retry must repeat exactly: the metadata and the file's bytes. */
    private static function fingerprint(StudentDocumentCategory $category, string $title, ?string $date, UploadedFile $file): string
    {
        $bytes = hash_file('sha256', (string) $file->getRealPath()) ?: '';

        return hash('sha256', implode("\n", [$category->value, $title, (string) $date, $bytes]));
    }

    /** Plain text: control characters removed, whitespace collapsed, trimmed, bounded. */
    public static function normalizeTitle(string $title): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $title)));
        if ($clean === '') {
            throw ValidationException::withMessages(['title' => 'Enter a title.']);
        }
        if (mb_strlen($clean) > self::TITLE_MAX) {
            throw ValidationException::withMessages(['title' => 'The title can be at most '.self::TITLE_MAX.' characters.']);
        }

        return $clean;
    }

    /** The Student, when the actor may see the whole School's Students; else NOT FOUND. */
    public function visibleStudent(School $school, User $actor, string $studentId): Student
    {
        $scope = $this->access->scopeOf($actor, 'students.view', $school);
        if (! $scope->isWholeSchool()) {
            throw (new ModelNotFoundException)->setModel(Student::class, [$studentId]);
        }

        return $this->access->student($school, $scope, $studentId);
    }

    private function lockedRecord(School $school, Student $student, string $recordId): StudentDocument
    {
        $record = StudentDocument::query()->where('school_id', $school->id)->where('student_id', $student->id)
            ->lockForUpdate()->find($recordId)
            ?? throw (new ModelNotFoundException)->setModel(StudentDocument::class, [$recordId]);
        if ($record->isArchived()) {
            throw new StudentDocumentArchivedException;
        }

        return $record;
    }

    /**
     * Reads the current database state (a concurrent retry may have won).
     *
     * @phpstan-impure
     */
    private function claimed(School $school, string $formKey): ?StudentDocument
    {
        return StudentDocument::query()->where('school_id', $school->id)->where('form_key', $formKey)->first();
    }

    /** The same person's retry of the same content for the same Student; anything else fails closed. */
    private function replay(StudentDocument $existing, Student $student, User $actor, string $fingerprint): StudentDocument
    {
        if ($existing->student_id !== $student->id || $existing->uploaded_by_user_id !== $actor->id
            || ! hash_equals($existing->upload_fingerprint, $fingerprint)) {
            throw new StudentDocumentFormReusedException;
        }

        return $existing;
    }

    private function category(string $value): StudentDocumentCategory
    {
        return StudentDocumentCategory::tryFrom($value)
            ?? throw ValidationException::withMessages(['category' => 'Choose a document category.']);
    }

    private function assertProof(FreshMfaProof $proof, User $actor): void
    {
        if (! $proof->isFor($actor->id)) {
            throw new \LogicException('A fresh MFA proof belongs to the acting user only.');
        }
    }
}
