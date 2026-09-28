<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Api\ApiScope;
use App\Support\Api\HumanApiTokenLifetime;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Privacy\EmailNormalizer;
use Database\Factories\UserFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use InvalidArgumentException;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;
use LogicException;

/**
 * Central/platform data: authentication identity only
 * (docs/security/AUTHORIZATION.md, "Identity separate from domain
 * personas"). Deliberately does NOT carry employment/student/guardian
 * fields -- future domain records (Employee, Teacher, Guardian,
 * Student) link to a User when they need login, they don't extend it.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $name
 * @property string $email
 * @property string|null $password NULL: a credential-less bootstrap account (ADR 0059 section 4).
 * @property bool $is_disabled
 * @property int $credential_version ADR 0056: the security generation every session carries.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use GeneratesUuidV7, HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_disabled' => 'boolean',
            'disabled_at' => 'datetime',
            'credential_version' => 'integer',
        ];
    }

    /**
     * Phase 0O.10A (ADR 0056 section 4.4): every write stores the ONE
     * canonical form (trim + lowercase); `users_email_canonical_check`
     * enforces it in the database.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => EmailNormalizer::canonical($value));
    }

    /**
     * Phase 0O.10A (ADR 0056 section 16): Laravel's stock password-reset
     * notification is NOT a recovery path here. Account recovery is
     * App\Domain\Identity\Application\AccountRecovery through the email
     * layer; this refuses so no caller can use the stock broker by accident.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        throw new LogicException('Laravel password-reset notifications are disabled; use account recovery (ADR 0056).');
    }

    /**
     * Phase 0O.12B (ADR 0059 section 4): whether this User has an established
     * local password. An operator-provisioned bootstrap account has none
     * (`password` NULL) until its one-time activation: it cannot sign in, is
     * not recovery-eligible and never counts as a qualifying School
     * administrator.
     */
    public function hasLocalCredential(): bool
    {
        return $this->password !== null && $this->password !== '';
    }

    /** @return HasMany<SchoolMembership, $this> */
    public function schoolMemberships(): HasMany
    {
        return $this->hasMany(SchoolMembership::class);
    }

    /** @return HasMany<PlatformRoleAssignment, $this> */
    public function platformRoleAssignments(): HasMany
    {
        return $this->hasMany(PlatformRoleAssignment::class);
    }

    /**
     * Phase 0H.4D-P1: User-global MFA factors (no school_id -- see
     * user_mfa_factors' migration docblock).
     *
     * @return HasMany<UserMfaFactor, $this>
     */
    public function mfaFactors(): HasMany
    {
        return $this->hasMany(UserMfaFactor::class);
    }

    /** @return HasMany<UserMfaRecoveryCode, $this> */
    public function mfaRecoveryCodes(): HasMany
    {
        return $this->hasMany(UserMfaRecoveryCode::class);
    }

    /**
     * Section 31: a disabled user must lose access immediately.
     */
    public function isDisabled(): bool
    {
        return (bool) $this->is_disabled;
    }

    /**
     * Phase 0O.3 (ADR 0049 section 2): Sanctum's createToken(), made safe by
     * construction -- the ONLY way a human API token is minted. Abilities
     * must be a non-empty set from the closed catalog (App\Support\Api\
     * ApiScope; never `*`), and every token expires: 30 days by default,
     * never more than 90 days after issue. There is no non-expiring path.
     * The authorization checks that decide WHO may mint one live in
     * App\Support\Api\HumanApiTokenService (fresh MFA, own account only).
     *
     * @param  array<int, string>  $abilities
     */
    public function createToken(string $name, array $abilities = [ApiScope::READ, ApiScope::WRITE], ?DateTimeInterface $expiresAt = null): NewAccessToken
    {
        if (! ApiScope::isValidHumanSet($abilities)) {
            throw new InvalidArgumentException('An API token needs at least one approved scope and no wildcard.');
        }

        $expiresAt = HumanApiTokenLifetime::expiryFor($expiresAt);
        $plainTextToken = $this->generateTokenString();

        $token = $this->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => array_values(array_unique($abilities)),
            'expires_at' => $expiresAt,
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
    }
}
