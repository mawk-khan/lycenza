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
     * @return BelongsToMany<Guardian, $this>
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardian_relationships', 'student_id', 'guardian_id')
            ->withPivot(['relationship_type', 'is_primary', 'is_legal_guardian', 'is_emergency_contact', 'is_authorized_pickup'])
            ->withTimestamps();
    }
}
