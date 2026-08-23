<?php

namespace App\Domain\HR\Infrastructure;

use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EmployeeDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 8A.7 -- one of an Employee's 1:N Restricted-tier document
 * metadata records (docs/modules/HR.md). Metadata ONLY -- this model
 * never reads or writes file bytes; `storage_disk`/`storage_path`
 * describe where a file lives, they do not make one accessible through
 * this class. The only sanctioned write path is
 * App\Domain\HR\Application\EmployeeDocumentService -- never create/
 * update this model directly outside a test.
 *
 * `classification_tier` is database-restricted to `restricted`/
 * `highly_sensitive` -- `directory` is not a legal value for an
 * Employee document.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employee_id
 * @property string $category
 * @property string $classification_tier restricted|highly_sensitive
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $uploaded_by_user_id
 * @property Carbon $uploaded_at
 * @property Carbon|null $issued_on
 * @property Carbon|null $expires_on
 * @property string $status active|archived
 */
class EmployeeDocument extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'employee_documents';

    protected $fillable = [
        'school_id',
        'employee_id',
        'category',
        'classification_tier',
        'storage_disk',
        'storage_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'uploaded_by_user_id',
        'uploaded_at',
        'issued_on',
        'expires_on',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'issued_on' => 'date',
            'expires_on' => 'date',
        ];
    }

    protected static function newFactory(): EmployeeDocumentFactory
    {
        return EmployeeDocumentFactory::new();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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
}
