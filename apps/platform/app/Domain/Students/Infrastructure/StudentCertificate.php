<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\Students\Application\Certificates\StudentCertificateType;
use App\Domain\Students\Application\Certificates\StudentCertificateVoidReason;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SIS.EXP-4: one issued Leaving/Transfer certificate -- its canonical serial,
 * the frozen issuance snapshot, the final placement it certifies and its
 * generated Document. Written only by StudentCertificateService; nothing is
 * mass assignable, and the database freezes every column except the one
 * ISSUED -> VOIDED transition.
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property StudentCertificateType $certificate_type
 * @property int $serial
 * @property string $display_number
 * @property string $status issued|voided
 * @property string $source_enrollment_id
 * @property string $document_id
 * @property string|null $supersedes_certificate_id
 * @property string $student_name
 * @property string $student_number
 * @property string $school_name
 * @property string|null $school_legal_name
 * @property string|null $school_affiliation_reference
 * @property string|null $school_registration_reference
 * @property string $campus_name
 * @property string $academic_year_name
 * @property string $grade_name
 * @property string $section_name
 * @property Carbon|null $joined_on
 * @property Carbon $left_on
 * @property string $exit_reason
 * @property Carbon $issued_on
 * @property string $form_key
 * @property string $request_fingerprint
 * @property string $issued_by_user_id
 * @property Carbon $issued_at
 * @property Carbon|null $voided_at
 * @property string|null $voided_by_user_id
 * @property StudentCertificateVoidReason|null $void_reason
 */
class StudentCertificate extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'student_certificates';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'certificate_type' => StudentCertificateType::class,
            'void_reason' => StudentCertificateVoidReason::class,
            'serial' => 'integer',
            'joined_on' => 'date',
            'left_on' => 'date',
            'issued_on' => 'date',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }
}
