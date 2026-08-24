<?php

namespace App\Domain\Identity\Infrastructure;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 5B.2 -- an explicit link between a Student or Guardian domain
 * identity and an existing SchoolMembership, never auto-created (see
 * App\Domain\Identity\Application\AccountLinkService, the sole write
 * path). Exactly one of `student_id`/`guardian_id` is set per row
 * (database-enforced). Current operational state, not an audit
 * ledger -- `status` flips between `active`/`revoked` in place,
 * mirroring `App\Models\SchoolMembership`'s own status column rather
 * than the append-only pattern this codebase reserves for real
 * history (`school_audit_events` is the actual ledger for link/unlink
 * events).
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $student_id
 * @property string|null $guardian_id
 * @property string $school_membership_id
 * @property string $status
 * @property string $linked_by_user_id
 * @property Carbon $linked_at
 * @property string|null $unlinked_by_user_id
 * @property Carbon|null $unlinked_at
 */
class StudentGuardianAccountLink extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'student_guardian_account_links';

    protected $fillable = [
        'school_id', 'student_id', 'guardian_id', 'school_membership_id', 'status',
        'linked_by_user_id', 'linked_at', 'unlinked_by_user_id', 'unlinked_at',
    ];

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'unlinked_at' => 'datetime',
        ];
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

    /** @return BelongsTo<SchoolMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(SchoolMembership::class, 'school_membership_id');
    }

    /** @return BelongsTo<User, $this> */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function unlinkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlinked_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @param  Builder<StudentGuardianAccountLink>  $query
     * @return Builder<StudentGuardianAccountLink>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
