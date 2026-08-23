<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned data (RLS-protected). Phase 0C.2's infrastructure-only
 * demonstration side effect -- see
 * App\Http\Controllers\Api\V1\Internal\IdempotencyDemoController and
 * the migration's docblock. NOT a real ERP business module.
 *
 * @property string $school_id
 * @property int $value
 */
class PlatformIdempotencyDemoCounter extends Model
{
    use BelongsToSchool;

    protected $table = 'platform_idempotency_demo_counters';

    protected $primaryKey = 'school_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
