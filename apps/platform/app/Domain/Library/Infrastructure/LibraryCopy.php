<?php

namespace App\Domain\Library\Infrastructure;

use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\LibraryCopyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One individually loanable physical object. See
 * docs/modules/LIBRARY.md -- `status` here is the ordinary active/
 * inactive reference-entity lifecycle only; whether this Copy is
 * currently on loan is derived from `loans()`/`activeLoan()`, never a
 * mirrored status value on this table.
 *
 * @property string $id
 * @property string $school_id
 * @property string $library_title_id
 * @property string|null $campus_id
 * @property string $code
 * @property string $status active|inactive
 */
class LibraryCopy extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'library_copies';

    protected $fillable = ['school_id', 'library_title_id', 'campus_id', 'code', 'status'];

    protected static function newFactory(): LibraryCopyFactory
    {
        return LibraryCopyFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<LibraryTitle, $this> */
    public function title(): BelongsTo
    {
        return $this->belongsTo(LibraryTitle::class, 'library_title_id');
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return HasMany<LibraryLoan, $this> */
    public function loans(): HasMany
    {
        return $this->hasMany(LibraryLoan::class);
    }

    /** @return HasOne<LibraryLoan, $this> */
    public function activeLoan(): HasOne
    {
        return $this->hasOne(LibraryLoan::class)->where('status', 'active');
    }

    public function isAvailable(): bool
    {
        return $this->isActive() && ($this->relationLoaded('activeLoan') ? $this->activeLoan === null : $this->activeLoan()->doesntExist());
    }
}
