<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Student is a permanent School-level identity (Phase 1A) --
 * deliberately independent of admission/enrollment/grade/section/
 * attendance/fee-account/portal-login state, all of which are separate
 * concerns owned by future modules (docs/architecture/DOMAIN-MAP.md
 * Layer 2/3). Never linked to `users` directly -- a future
 * StudentUserLink (portal access) would be an explicit, separate join,
 * not a column on this table.
 *
 * `student_number` is unique within a School only (see the migration),
 * never globally unique, and carries no grade/class/roll-number
 * meaning -- it must remain stable across academic years.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $student_number
 * @property string $first_name
 * @property string|null $middle_name
 * @property string|null $last_name
 * @property Carbon $date_of_birth
 * @property string $status
 */
class Student extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'students';

    protected $fillable = [
        'school_id', 'student_number', 'first_name', 'middle_name', 'last_name',
        'date_of_birth', 'status',
    ];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date'];
    }

    protected static function newFactory(): StudentFactory
    {
        return StudentFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The domain relationship records themselves -- relationship_type,
     * is_primary, is_legal_guardian, etc. Distinct from guardians()
     * below, which is the plain related-Guardian collection.
     *
     * @return HasMany<StudentGuardianRelationship, $this>
     */
    public function guardianRelationships(): HasMany
    {
        return $this->hasMany(StudentGuardianRelationship::class);
    }

    /**
     * A Student's academic placement history (Phase 1B.1) -- many
     * historical rows, never mutated to represent a later year. See
     * App\Domain\Students\Infrastructure\StudentEnrollment's docblock.
     *
     * @return HasMany<StudentEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    /**
     * Phase 0H.4D-P2: the append-only processing-authorization ledger
     * (guardian/adult-student consent, statutory School purpose).
     * Read-only convenience -- StudentProcessingAuthorizationService/
     * ReadService own every write and every "current state" query;
     * this relation is never used to derive qualifying status.
     *
     * @return HasMany<StudentProcessingAuthorization, $this>
     */
    public function processingAuthorizations(): HasMany
    {
        return $this->hasMany(StudentProcessingAuthorization::class);
    }

    /**
     * Read convenience only (eager-loading, counting, querying "all
     * Guardians of this Student"). Phase 1A.2's P3 finding, resolved in
     * Phase 1A.3: `attach()`/`sync()`/`detach()` are NOT the supported
     * mutation API for this relationship -- they write via a raw query
     * builder insert that bypasses BelongsToSchool's `school_id`
     * auto-fill entirely (Laravel does not route attach()/sync()
     * through a custom pivot model's Eloquent events even when one is
     * configured via `->using()`, so configuring one here would not
     * have fixed this). In practice this fails loudly rather than
     * silently: `school_id` is NOT NULL, so attach() raises a
     * QueryException rather than creating a School-less or wrongly-
     * scoped row -- proven in
     * StudentGuardianRelationshipTest::attach_is_not_the_supported_mutation_api_and_fails_closed.
     * Always create/update rows via
     * App\Domain\Guardians\Infrastructure\StudentGuardianRelationship
     * directly (or a future dedicated service), never via this
     * relation's write methods.
     *
     * @return BelongsToMany<Guardian, $this>
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardian_relationships', 'student_id', 'guardian_id')
            ->withPivot(['relationship_type', 'is_primary', 'is_legal_guardian', 'is_emergency_contact', 'is_authorized_pickup'])
            ->withTimestamps();
    }
}
