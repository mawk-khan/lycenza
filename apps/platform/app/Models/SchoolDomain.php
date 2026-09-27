<?php

namespace App\Models;

use App\Support\Domains\DomainState;
use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Central/platform data: a custom School domain and its lifecycle (ADR
 * 0054; docs/architecture/TENANCY.md, "Custom School domains"). No RLS:
 * a Host is resolved before any School context exists. Written only by
 * App\Domain\Platform\Application\Domains services; `state` is the only
 * lifecycle truth and the database enforces its transitions.
 *
 * `challenge_token` is Confidential while pending (ADR 0054 section 10.3):
 * encrypted at rest (it must be recoverable, because every check compares
 * the published TXT value with it), hidden from serialization, never logged
 * or audited.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $hostname
 * @property DomainState $state
 * @property bool $is_primary
 * @property string|null $challenge_token
 * @property int $challenge_generation
 * @property Carbon|null $challenge_expires_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $ownership_checked_at
 * @property string|null $ownership_outcome
 * @property Carbon|null $routing_checked_at
 * @property string|null $routing_outcome
 * @property Carbon|null $tls_checked_at
 * @property string|null $tls_outcome
 * @property Carbon|null $certificate_not_after
 * @property string|null $certificate_fingerprint
 * @property string|null $certificate_issuer
 * @property Carbon|null $activated_at
 * @property Carbon|null $suspended_at
 * @property string|null $suspension_reason
 * @property Carbon|null $revoked_at
 * @property string|null $revocation_source
 * @property Carbon|null $expired_at
 * @property int $ownership_mismatch_count
 * @property Carbon|null $ownership_mismatch_since
 * @property int $ownership_absent_count
 * @property Carbon|null $ownership_absent_since
 * @property int $routing_fail_count
 * @property Carbon|null $routing_fail_since
 * @property int $tls_fail_count
 * @property Carbon|null $tls_fail_since
 * @property Carbon|null $indeterminate_since
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $next_check_at
 * @property Carbon $created_at
 * @property-read School $school
 */
class SchoolDomain extends Model
{
    use GeneratesUuidV7;

    /** Mass assignment is closed: every write names its columns explicitly. */
    protected $guarded = ['*'];

    protected $hidden = ['challenge_token'];

    protected function casts(): array
    {
        return [
            'state' => DomainState::class,
            'is_primary' => 'boolean',
            'challenge_token' => 'encrypted',
            'challenge_generation' => 'integer',
            'challenge_expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'ownership_checked_at' => 'datetime',
            'routing_checked_at' => 'datetime',
            'tls_checked_at' => 'datetime',
            'certificate_not_after' => 'datetime',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expired_at' => 'datetime',
            'ownership_mismatch_count' => 'integer',
            'ownership_mismatch_since' => 'datetime',
            'ownership_absent_count' => 'integer',
            'ownership_absent_since' => 'datetime',
            'routing_fail_count' => 'integer',
            'routing_fail_since' => 'datetime',
            'tls_fail_count' => 'integer',
            'tls_fail_since' => 'datetime',
            'indeterminate_since' => 'datetime',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * @param  Builder<SchoolDomain>  $query
     * @return Builder<SchoolDomain>
     */
    public function scopeClaiming(Builder $query): Builder
    {
        return $query->whereIn('state', DomainState::CLAIMING);
    }

    public function isActive(): bool
    {
        return $this->state === DomainState::Active;
    }
}
