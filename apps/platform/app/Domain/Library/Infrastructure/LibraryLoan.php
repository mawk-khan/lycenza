<?php

namespace App\Domain\Library\Infrastructure;

use App\Domain\Students\Infrastructure\Student;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\LibraryLoanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One circulation/loan record -- see docs/modules/LIBRARY.md
 * ("Circulation lifecycle"). Never write directly; the sole sanctioned
 * write path is App\Domain\Library\Application\LibraryLoanService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $library_copy_id
 * @property string $student_id
 * @property string $status active|returned
 * @property Carbon $checked_out_at
 * @property Carbon $due_at
 * @property Carbon|null $checked_in_at
 */
class LibraryLoan extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'library_loans';

    protected $fillable = ['school_id', 'library_copy_id', 'student_id', 'status', 'checked_out_at', 'due_at', 'checked_in_at'];

    protected static function newFactory(): LibraryLoanFactory
    {
        return LibraryLoanFactory::new();
    }

    protected function casts(): array
    {
        return [
            'checked_out_at' => 'datetime',
            'due_at' => 'datetime',
            'checked_in_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOverdue(): bool
    {
        return $this->isActive() && $this->due_at->isPast();
    }

    /** @return BelongsTo<LibraryCopy, $this> */
    public function copy(): BelongsTo
    {
        return $this->belongsTo(LibraryCopy::class, 'library_copy_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
