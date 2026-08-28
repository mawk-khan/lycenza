<?php

namespace App\Domain\Hostel\Infrastructure;

use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\HostelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A School's Hostel (docs/modules/HOSTEL.md "Hostel model"). Belongs
 * to exactly one Campus in this checkpoint. `status` (active|inactive)
 * is an ordinary reference-lifecycle flag, not a security/safety
 * signal.
 *
 * @property string $id
 * @property string $school_id
 * @property string $campus_id
 * @property string $code
 * @property string $name
 * @property string $status active|inactive
 */
class Hostel extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'hostels';

    protected $fillable = ['school_id', 'campus_id', 'code', 'name', 'status'];

    protected static function newFactory(): HostelFactory
    {
        return HostelFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return HasMany<HostelRoom, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(HostelRoom::class, 'hostel_id');
    }
}
