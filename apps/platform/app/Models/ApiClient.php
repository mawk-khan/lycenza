<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Phase 0O.3 (ADR 0049 section 3): a PARTNER API client -- a non-human
 * external integration bound to exactly one School (immutable, database-
 * enforced). Not a User, not a service identity, not a membership, not a
 * Group grant, not elevation. It is the request principal of an
 * `auth:partner` request (hence Authenticatable) and holds only its
 * approved partner scopes (App\Support\Api\PartnerScopeRegistry).
 *
 * A platform-resolvable authorization bootstrap record (no RLS), like
 * `school_memberships`: never a source of tenant-domain content.
 * Written only by App\Support\ApiClients\ApiClientService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $name
 * @property list<string> $scopes
 * @property string $status
 * @property string $created_by_user_id
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_by_user_id
 */
class ApiClient extends Model implements Authenticatable
{
    use AuthenticatableTrait, GeneratesUuidV7;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = ['school_id', 'name', 'scopes', 'created_by_user_id'];

    /**
     * The credential that authenticated the current request (set by the
     * partner guard; never persisted).
     */
    public ?ApiClientCredential $currentCredential = null;

    protected function casts(): array
    {
        return ['scopes' => 'array', 'revoked_at' => 'datetime'];
    }

    /**
     * The partner principal of an `auth:partner` request, or null. (A
     * request's user is typed as a User elsewhere; here it may be a client.)
     */
    public static function fromRequest(Request $request): ?self
    {
        /** @var mixed $principal */
        $principal = $request->user();

        return $principal instanceof self ? $principal : null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return HasMany<ApiClientCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(ApiClientCredential::class);
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
