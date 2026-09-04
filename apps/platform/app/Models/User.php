<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Identifiers\GeneratesUuidV7;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

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
 * @property bool $is_disabled
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
        ];
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
}
