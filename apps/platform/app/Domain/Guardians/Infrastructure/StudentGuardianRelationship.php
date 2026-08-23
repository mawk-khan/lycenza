<?php

namespace App\Domain\Guardians\Infrastructure;

use App\Domain\Students\Infrastructure\Student;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\StudentGuardianRelationshipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The first-class join between a Student and a Guardian -- never
 * father_id/mother_id columns on Student, and never a duplicate
 * Guardian row per child (siblings reuse the same Guardian row via
 * separate relationship rows). `is_primary`/`is_legal_guardian`/
 * `is_emergency_contact`/`is_authorized_pickup` all live here, not on
 * Guardian, because the same Guardian can have different authority for
 * different Students. See docs/modules/STUDENT-GUARDIAN-IDENTITY.md.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $school_id
 * @property string $student_id
 * @property string $guardian_id
 * @property RelationshipType $relationship_type
 * @property bool $is_primary
 * @property bool $is_legal_guardian
 * @property bool $is_emergency_contact
 * @property bool $is_authorized_pickup
 */
class StudentGuardianRelationship extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'student_guardian_relationships';

    protected $fillable = [
        'school_id', 'student_id', 'guardian_id', 'relationship_type',
        'is_primary', 'is_legal_guardian', 'is_emergency_contact', 'is_authorized_pickup',
    ];

    protected function casts(): array
    {
        return [
            'relationship_type' => RelationshipType::class,
            'is_primary' => 'boolean',
            'is_legal_guardian' => 'boolean',
            'is_emergency_contact' => 'boolean',
            'is_authorized_pickup' => 'boolean',
        ];
    }

    protected static function newFactory(): StudentGuardianRelationshipFactory
    {
        return StudentGuardianRelationshipFactory::new();
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Guardian, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }
}
