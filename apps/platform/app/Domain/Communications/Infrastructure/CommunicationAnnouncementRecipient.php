<?php

namespace App\Domain\Communications\Infrastructure;

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
 * @use HasFactory<CommunicationAnnouncementRecipientFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string $announcement_id
 * @property string $school_membership_id
 * @property string $user_id
 */
class CommunicationAnnouncementRecipient extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = ['school_id', 'announcement_id', 'school_membership_id', 'user_id'];

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
}
