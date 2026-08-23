<?php

namespace App\Domain\Communications\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationAnnouncementChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5A.3 §16/§17: one row per channel explicitly requested for an
 * Announcement -- see the migration's docblock.
 *
 * @use HasFactory<CommunicationAnnouncementChannelFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string $announcement_id
 * @property string $channel
 */
class CommunicationAnnouncementChannel extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = ['school_id', 'announcement_id', 'channel'];

    protected static function newFactory(): CommunicationAnnouncementChannelFactory
    {
        return CommunicationAnnouncementChannelFactory::new();
    }

    /** @return BelongsTo<CommunicationAnnouncement, $this> */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(CommunicationAnnouncement::class, 'announcement_id');
    }
}
