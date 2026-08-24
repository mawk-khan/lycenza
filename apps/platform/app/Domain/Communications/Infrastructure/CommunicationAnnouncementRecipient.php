<?php

namespace App\Domain\Communications\Infrastructure;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationAnnouncementRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The immutable, durable resolved-audience snapshot (Phase 5A.2 §10) --
 * append-only at the database privilege level
 * (App\Support\Tenancy\TenantRls::makeAppendOnly). Never re-resolved
 * on view; this table IS the historical record of exactly who a
 * published Announcement targeted.
 *
 * Phase 5B.1: widened to represent a Student or Guardian logical
 * recipient, not only a SchoolMembership one -- exactly one of
 * `user_id`/`student_id`/`guardian_id` is set per row (database-
 * enforced). A pre-5B.1 row always has `user_id`/`school_membership_id`
 * set and `student_id`/`guardian_id` null, unchanged.
 *
 * @use HasFactory<CommunicationAnnouncementRecipientFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string $announcement_id
 * @property string|null $school_membership_id
 * @property string|null $user_id
 * @property string|null $student_id
 * @property string|null $guardian_id
 */
class CommunicationAnnouncementRecipient extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = ['school_id', 'announcement_id', 'school_membership_id', 'user_id', 'student_id', 'guardian_id'];

    protected static function newFactory(): CommunicationAnnouncementRecipientFactory
    {
        return CommunicationAnnouncementRecipientFactory::new();
    }

    /** @return BelongsTo<CommunicationAnnouncement, $this> */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(CommunicationAnnouncement::class, 'announcement_id');
    }

    /** @return BelongsTo<SchoolMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(SchoolMembership::class, 'school_membership_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
