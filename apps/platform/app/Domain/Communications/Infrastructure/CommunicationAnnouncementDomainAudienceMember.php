<?php

namespace App\Domain\Communications\Infrastructure;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The AUTHORED domain-audience selection for `student`/`guardian`/
 * `guardians_of_students` announcements (Phase 5B.1) -- exactly one of
 * `student_id`/`guardian_id` is set per row (database-enforced, see
 * the creating migration), never both. Re-validated for eligibility
 * again at resolve/publish time by the matching audience resolver,
 * the same "authored list is not the final word" pattern
 * `communication_announcement_audience_members` already established.
 *
 * @property string $id
 * @property string $school_id
 * @property string $announcement_id
 * @property string|null $student_id
 * @property string|null $guardian_id
 */
class CommunicationAnnouncementDomainAudienceMember extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'communication_announcement_domain_audience_members';

    protected $fillable = ['school_id', 'announcement_id', 'student_id', 'guardian_id'];

    /** @return BelongsTo<CommunicationAnnouncement, $this> */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(CommunicationAnnouncement::class, 'announcement_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Guardian, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }
}
