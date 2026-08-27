<?php

namespace App\Domain\Library\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\LibraryTitleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned, School-wide bibliographic/catalogue record. See
 * docs/modules/LIBRARY.md for the title/copy modeling decision.
 *
 * @property string $id
 * @property string $school_id
 * @property string $title
 * @property string|null $author
 * @property string|null $isbn
 * @property string $status active|inactive
 */
class LibraryTitle extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'library_titles';

    protected $fillable = ['school_id', 'title', 'author', 'isbn', 'status'];

    protected static function newFactory(): LibraryTitleFactory
    {
        return LibraryTitleFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return HasMany<LibraryCopy, $this> */
    public function copies(): HasMany
    {
        return $this->hasMany(LibraryCopy::class);
    }
}
