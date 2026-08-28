<?php

namespace App\Domain\Visitor\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\VisitorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A School's Visitor directory record (docs/modules/VISITOR.md
 * "Visitor directory model"). School-scoped, not Campus-scoped -- the
 * same Visitor identity may visit multiple Campuses within the same
 * School without duplicate rows.
 *
 * `status` (active|inactive) is an ordinary reference-lifecycle flag,
 * NOT a security blocklist -- see VISITOR.md "Active/inactive is not
 * blocklisting".
 *
 * @property string $id
 * @property string $school_id
 * @property string $full_name
 * @property string|null $phone
 * @property string $status active|inactive
 */
class Visitor extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'visitors';

    protected $fillable = ['school_id', 'full_name', 'phone', 'status'];

    protected static function newFactory(): VisitorFactory
    {
        return VisitorFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return HasMany<VisitorVisit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(VisitorVisit::class, 'visitor_id');
    }

    /** @return HasOne<VisitorVisit, $this> */
    public function activeVisit(): HasOne
    {
        return $this->hasOne(VisitorVisit::class, 'visitor_id')->where('status', 'checked_in');
    }
}
