<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\Students\Application\Documents\DocumentRejectionReason;
use App\Domain\Students\Application\Documents\DocumentVerificationStatus;
use App\Domain\Students\Application\Documents\StudentDocumentArchiveReason;
use App\Domain\Students\Application\Documents\StudentDocumentCategory;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SIS.EXP-3: the Student-record side of a Student-owned Document (category,
 * title, date, archive). The file itself is Documents' (`document_id`); this
 * model never reads it. Written only by StudentDocumentService; identity is
 * never mass assignable (`school_id`, `student_id`, `document_id`,
 * `form_key`, `uploaded_by_user_id` are set explicitly at creation and frozen by the database).
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property string $document_id
 * @property StudentDocumentCategory $category
 * @property string $title
 * @property Carbon|null $document_date
 * @property string $form_key
 * @property string $upload_fingerprint
 * @property int $version
 * @property string $uploaded_by_user_id
 * @property Carbon|null $archived_at
 * @property string|null $archived_by_user_id
 * @property StudentDocumentArchiveReason|null $archive_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class StudentDocument extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'student_documents';

    protected $fillable = ['category', 'title', 'document_date'];

    protected function casts(): array
    {
        return [
            'category' => StudentDocumentCategory::class,
            'archive_reason' => StudentDocumentArchiveReason::class,
            'document_date' => 'date',
            'archived_at' => 'datetime',
            'version' => 'integer',
            // SIS 11.5: verification (NULL = uploaded before verification existed).
            'verification_status' => DocumentVerificationStatus::class,
            'rejection_reason' => DocumentRejectionReason::class,
            'verified_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
