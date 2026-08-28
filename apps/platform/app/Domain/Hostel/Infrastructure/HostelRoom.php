<?php

namespace App\Domain\Hostel\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\HostelRoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Room belonging to exactly one Hostel (docs/modules/HOSTEL.md
 * "HostelRoom model"). Deliberately NOT a reuse of Academic
 * Structure's `Room` -- that model is a teaching space, this is a
 * residential space; overloading it would conflate two unrelated
 * concepts that only happen to share a name. No stored `capacity` --
 * see `beds()`/`activeBeds()`; capacity for this checkpoint is always
 * derived by counting active `HostelBed` rows.
 *
 * @property string $id
 * @property string $school_id
 * @property string $hostel_id
 * @property string $code
 * @property string|null $floor_or_block
 * @property string $status active|inactive
 */
class HostelRoom extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'hostel_rooms';

    protected $fillable = ['school_id', 'hostel_id', 'code', 'floor_or_block', 'status'];

    protected static function newFactory(): HostelRoomFactory
    {
        return HostelRoomFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<Hostel, $this> */
    public function hostel(): BelongsTo
    {
        return $this->belongsTo(Hostel::class, 'hostel_id');
    }

    /** @return HasMany<HostelBed, $this> */
    public function beds(): HasMany
    {
        return $this->hasMany(HostelBed::class, 'hostel_room_id');
    }
}
