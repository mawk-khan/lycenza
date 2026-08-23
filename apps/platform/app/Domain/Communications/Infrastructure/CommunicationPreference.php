<?php

namespace App\Domain\Communications\Infrastructure;

use App\Models\SchoolMembership;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5A.5 §11/§12 -- a single SchoolMembership's explicit channel
 * preference. Scoped to `school_membership_id`, never `user_id`
 * directly -- see the creating migration's docblock. Row absence
 * means "inherit the system/school default," not "disabled."
 *
 * @use HasFactory<CommunicationPreferenceFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string $school_membership_id
 * @property string $channel
 * @property string $preference
 */
class CommunicationPreference extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = ['school_id', 'school_membership_id', 'channel', 'preference'];

    protected static function newFactory(): CommunicationPreferenceFactory
    {
        return CommunicationPreferenceFactory::new();
    }

    /** @return BelongsTo<SchoolMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(SchoolMembership::class, 'school_membership_id');
    }

    public function isEnabled(): bool
    {
        return $this->preference === 'enabled';
    }
}
