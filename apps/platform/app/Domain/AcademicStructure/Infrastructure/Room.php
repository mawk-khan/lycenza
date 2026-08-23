<?php

namespace App\Domain\AcademicStructure\Infrastructure;

use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned, Campus-scoped learning space (Phase 0D sections 34-36).
 *
 * @property string $id
 * @property string $school_id
 * @property string $campus_id
 * @property string $name
 * @property string $code
 * @property string $room_type classroom|laboratory|auditorium|library|sports|other
 * @property int|null $capacity
 * @property string $status
 */
class Room extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'rooms';

    protected $fillable = ['school_id', 'campus_id', 'name', 'code', 'room_type', 'capacity', 'status'];

    protected static function newFactory(): RoomFactory
    {
        return RoomFactory::new();
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
}
