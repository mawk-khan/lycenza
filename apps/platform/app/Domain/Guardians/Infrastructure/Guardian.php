<?php

namespace App\Domain\Guardians\Infrastructure;

use App\Domain\Students\Infrastructure\Student;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\GuardianFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Guardian is a permanent School-level identity, never duplicated
 * once per child -- a Guardian with several Students at the same
 * School is one row, linked to each Student through
 * StudentGuardianRelationship. Deliberately independent of `users`:
 * this table carries no user_id column -- a future GuardianUserLink
 * would be an explicit, separate join for portal access, not a column
 * here.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $first_name
 * @property string|null $middle_name
 * @property string|null $last_name
 * @property string $status
 */
class Guardian extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'guardians';

    protected $fillable = ['school_id', 'first_name', 'middle_name', 'last_name', 'status'];

    protected static function newFactory(): GuardianFactory
    {
        return GuardianFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The domain relationship records themselves -- relationship_type,
     * is_primary, is_legal_guardian, etc. Distinct from students()
     * below, which is the plain related-Student collection.
     *
     * @return HasMany<StudentGuardianRelationship, $this>
     */
    public function studentRelationships(): HasMany
    {
        return $this->hasMany(StudentGuardianRelationship::class);
    }

    /**
     * @return BelongsToMany<Student, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_guardian_relationships', 'guardian_id', 'student_id')
            ->withPivot(['relationship_type', 'is_primary', 'is_legal_guardian', 'is_emergency_contact', 'is_authorized_pickup'])
            ->withTimestamps();
    }
}
