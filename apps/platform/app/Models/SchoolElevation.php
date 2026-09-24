<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One platform elevation into ONE School (Phase 0N.3, ADR 0044) -- the
 * grant being exercised, never a membership and never a School role.
 * Platform-owned, no RLS (see the migration); only
 * App\Domain\Platform\Application\Elevation\SchoolElevationService and
 * the web resolver (ResolvePlatformElevation) read or write it. Highly
 * Sensitive (docs/security/DATA-CLASSIFICATION.md).
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $actor_user_id
 * @property string $school_id
 * @property string $authority_type `platform` or `group` (ADR 0045), fixed at start
 * @property string|null $school_group_id the authorizing Group (group authority only)
 * @property string|null $group_role_assignment_id the authorizing grant (group authority only)
 * @property string $reason_code
 * @property string $status
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 * @property string|null $start_request_id
 */
class SchoolElevation extends Model
{
    use GeneratesUuidV7;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_TERMINATED = 'terminated';

    public const AUTHORITY_PLATFORM = 'platform';

    public const AUTHORITY_GROUP = 'group';

    protected $fillable = [
        'actor_user_id', 'school_id', 'authority_type', 'school_group_id',
        'group_role_assignment_id', 'reason_code', 'status',
        'started_at', 'expires_at', 'start_request_id',
    ];

    protected $attributes = ['authority_type' => self::AUTHORITY_PLATFORM];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<SchoolGroup, $this> */
    public function schoolGroup(): BelongsTo
    {
        return $this->belongsTo(SchoolGroup::class);
    }

    /** @return BelongsTo<GroupRoleAssignment, $this> */
    public function groupGrant(): BelongsTo
    {
        return $this->belongsTo(GroupRoleAssignment::class, 'group_role_assignment_id');
    }

    public function isGroupDerived(): bool
    {
        return $this->authority_type === self::AUTHORITY_GROUP;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasExpired(): bool
    {
        return ! $this->expires_at->isFuture();
    }
}
