<?php

namespace App\Domain\Hostel\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\HostelBedFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One independently assignable physical Bed belonging to exactly one
 * Room (docs/modules/HOSTEL.md "HostelBed model"). Deliberately
 * carries NO `student_id`/current-occupant column -- current occupancy
 * is always derived from the active `HostelResidencyAssignment` row
 * referencing this Bed (`activeResidency()`), never duplicated here.
 *
 * @property string $id
 * @property string $school_id
 * @property string $hostel_room_id
 * @property string $code
 * @property string $status active|inactive
 */
class HostelBed extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'hostel_beds';

    protected $fillable = ['school_id', 'hostel_room_id', 'code', 'status'];

    protected static function newFactory(): HostelBedFactory
    {
        return HostelBedFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * A Bed is available for a NEW assignment only when it, its Room,
     * and its Hostel are all active (docs/modules/HOSTEL.md "Room/Bed
     * status interactions") -- deactivating an ancestor blocks new
     * assignments without touching existing residency history.
     */
    public function isAvailableForAssignment(): bool
    {
        return $this->isActive() && $this->room->isActive() && $this->room->hostel->isActive();
    }

    /** @return BelongsTo<HostelRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(HostelRoom::class, 'hostel_room_id');
    }

    /** @return HasMany<HostelResidencyAssignment, $this> */
    public function residencyAssignments(): HasMany
    {
        return $this->hasMany(HostelResidencyAssignment::class, 'hostel_bed_id');
    }

    /** @return HasOne<HostelResidencyAssignment, $this> */
    public function activeResidency(): HasOne
    {
        return $this->hasOne(HostelResidencyAssignment::class, 'hostel_bed_id')->where('status', 'active');
    }
}
