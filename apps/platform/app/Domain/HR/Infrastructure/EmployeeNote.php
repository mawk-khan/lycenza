<?php

namespace App\Domain\HR\Infrastructure;

use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EmployeeNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 8A closure correction -- one of an Employee's 1:N HR-authored
 * notes (docs/modules/HR.md entity model). Classified per-note via
 * `classification_tier`, never a fixed table-wide tier.
 *
 * The only sanctioned write path is
 * App\Domain\HR\Application\EmployeeNoteService -- never create/
 * update this model directly outside a test.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employee_id
 * @property string $author_user_id
 * @property string $body
 * @property string $classification_tier sensitive|confidential
 * @property Carbon|null $archived_at
 * @property string|null $archived_by_user_id
 * @property string|null $archive_reason
 */
class EmployeeNote extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'employee_notes';

    public const array CLASSIFICATION_TIERS = ['sensitive', 'confidential'];

    protected $fillable = [
        'school_id',
        'employee_id',
        'author_user_id',
        'body',
        'classification_tier',
    ];

    /** HR.2A: archived (S-16) rows are frozen evidence; reads show live rows. */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    protected static function newFactory(): EmployeeNoteFactory
    {
        return EmployeeNoteFactory::new();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
