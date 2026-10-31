<?php

namespace App\Domain\Admissions\Infrastructure;

use App\Domain\Students\Application\Documents\DocumentRejectionReason;
use App\Domain\Students\Application\Documents\DocumentVerificationStatus;
use App\Domain\Students\Application\Documents\StudentDocumentArchiveReason;
use App\Domain\Students\Application\Documents\StudentDocumentCategory;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SIS 11.5: a pre-admission document record -- an Applicant-owned Document in
 * the one Documents repository. Final once archived or moved to the Student
 * the application became (the same stored file; no copy).
 *
 * @property string $id
 * @property string $applicant_id
 * @property string $admission_application_id
 * @property string $document_id
 * @property StudentDocumentCategory $category
 * @property string $title
 * @property int $version
 * @property DocumentVerificationStatus $verification_status
 * @property ?DocumentRejectionReason $rejection_reason
 * @property ?Carbon $document_date
 * @property ?StudentDocumentArchiveReason $archive_reason
 * @property ?Carbon $verified_at
 * @property ?Carbon $archived_at
 * @property ?Carbon $moved_to_student_at
 * @property ?string $moved_to_student_id
 * @property string $upload_fingerprint
 * @property string $uploaded_by_user_id
 * @property ?string $verified_by_user_id
 * @property Carbon $created_at
 */
class ApplicantDocument extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'applicant_documents';

    protected $fillable = ['category', 'title', 'document_date'];

    protected function casts(): array
    {
        return [
            'category' => StudentDocumentCategory::class,
            'archive_reason' => StudentDocumentArchiveReason::class,
            'verification_status' => DocumentVerificationStatus::class,
            'rejection_reason' => DocumentRejectionReason::class,
            'document_date' => 'date',
            'archived_at' => 'datetime',
            'verified_at' => 'datetime',
            'moved_to_student_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function isFinal(): bool
    {
        return $this->archived_at !== null || $this->moved_to_student_at !== null;
    }
}
