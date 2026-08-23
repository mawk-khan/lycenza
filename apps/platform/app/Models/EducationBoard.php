<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Central/platform catalog (Phase 0D section 11) -- same shape as
 * Capability/Role: seeded once, referenced by every School, never
 * School-owned and never RLS-protected. `code` is an open string
 * (e.g. "cbse", "cisce", "ib", "cambridge", "state_board", "other"),
 * not a hard-coded enum -- see docs/modules/ACADEMIC-STRUCTURE.md.
 *
 * @property string $id UUIDv7 (ADR 0019).
 * @property string $code
 * @property string $name
 * @property string $status
 */
class EducationBoard extends Model
{
    use GeneratesUuidV7;

    protected $fillable = ['code', 'name', 'status'];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
