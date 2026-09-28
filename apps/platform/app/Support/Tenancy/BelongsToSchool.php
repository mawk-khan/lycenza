<?php

namespace App\Support\Tenancy;

use App\Models\School;
use App\Support\Email\PlatformEmailScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-owned Eloquent model. Adds the SchoolScope
 * global scope (Layer 1 isolation) and auto-fills school_id from the
 * active TenantContext on create when not explicitly given -- so
 * application code creating a tenant-owned row inside a School context
 * doesn't have to remember to pass school_id every time, but CAN
 * override it explicitly (e.g. a privileged admin tool creating a row
 * for a specific school while in a different/no active context).
 *
 * To intentionally bypass the scope (Layer 5 Analytics/Compliance
 * reads, platform tooling -- docs/architecture/DOMAIN-MAP.md), call
 * Model::withoutGlobalScope(SchoolScope::class) explicitly. There is no
 * implicit unscoped path.
 */
trait BelongsToSchool
{
    protected static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope);

        static::creating(function ($model): void {
            // Phase 0O.10A: an identity-level row (no School) is created only
            // inside the platform email scope.
            if (empty($model->school_id) && ! ($model instanceof AllowsIdentityLevelRows && app(PlatformEmailScope::class)->active())) {
                $context = app(TenantContext::class);
                $model->school_id = $context->requireSchool()->id;
            }
        });
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
