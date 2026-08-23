<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Application-layer (Layer 1) tenant scoping -- see
 * docs/architecture/TENANCY.md. Independent of, and not a substitute
 * for, PostgreSQL RLS (Layer 2, TenantRls): this scope is proven by
 * Laravel-only tests (no real Postgres required) while RLS is proven
 * by the real-Postgres integration suite. Both must independently hold.
 *
 * Fails closed exactly like RLS does: no School context set means the
 * query returns zero rows, never "every school's rows."
 */
class SchoolScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->hasSchool()) {
            $builder->where($model->qualifyColumn('school_id'), $context->schoolId());
        } else {
            $builder->whereRaw('1 = 0');
        }
    }
}
