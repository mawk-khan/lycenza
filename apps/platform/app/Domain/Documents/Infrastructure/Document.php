<?php

namespace App\Domain\Documents\Infrastructure;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Students\Infrastructure\Student;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 0E.1 -- the Documents module's foundation aggregate (ADR 0012,
 * docs/modules/DOCUMENTS.md). Stores metadata only, never file bytes.
 * Exactly one of employee()/student()/guardian() is ever set -- see
 * the owning migration's docblock for why this is three separate
 * structural composite foreign keys rather than a single polymorphic
 * owner column. Phase 0E.2 adds
 * App\Domain\Documents\Application\DocumentService as the sanctioned
 * write path for the Employee owner type (Student/Guardian remain
 * deferred, see that class's own docblock) -- this model itself stays
 * unchanged from 0E.1: no new column, no new relation shape.
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $employee_id
 * @property string|null $student_id
 * @property string|null $guardian_id
 * @property string $classification_tier public|internal|sensitive|highly_sensitive
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $uploaded_by_user_id
 * @property Carbon $uploaded_at
 * @property string $status active|archived
 * @property-read string $owner_type employee|student|guardian
 */
class Document extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'documents';

    protected $fillable = [
        'school_id',
        'employee_id',
        'student_id',
        'guardian_id',
        'classification_tier',
        'storage_disk',
        'storage_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'uploaded_by_user_id',
        'uploaded_at',
        'status',
    ];

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Which single owning entity this Document belongs to -- 'employee'
     * | 'student' | 'guardian'. The exactly-one-owner CHECK constraint
     * guarantees exactly one of these is ever non-null; this accessor
     * exists so callers never have to re-derive that logic themselves.
     */
    protected function ownerType(): Attribute
    {
        return Attribute::get(function () {
            return match (true) {
                $this->employee_id !== null => 'employee',
                $this->student_id !== null => 'student',
                $this->guardian_id !== null => 'guardian',
                default => throw new \LogicException('Document has no owner set -- violates the exactly-one-owner database constraint.'),
            };
        });
    }
}
