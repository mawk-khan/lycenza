<?php

namespace App\Domain\Communications\Infrastructure;

use App\Models\SchoolMembership;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationAnnouncementAudienceMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @use HasFactory<CommunicationAnnouncementAudienceMemberFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string $announcement_id
 * @property string $school_membership_id
 */
class CommunicationAnnouncementAudienceMember extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = ['school_id', 'announcement_id', 'school_membership_id'];

    protected static function newFactory(): CommunicationAnnouncementAudienceMemberFactory
    {
        return CommunicationAnnouncementAudienceMemberFactory::new();
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
}
