<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\EmployeeCategory;
use App\Models\School;
use Illuminate\Support\Str;

/**
 * HR.9 (ADR 0065 §28.3): HR's narrow answer to "which Employee Categories
 * may another domain name in its own School configuration" -- today only a
 * Leave eligibility rule. A Category is School configuration (tenant
 * lifetime, no personal data); this returns id, code and name only. The one
 * direction is Leave -> HR (DOMAIN-MAP); HR never reads Leave.
 *
 * Every method MUST run inside the target School's TenantContext.
 */
final class EmployeeCategoryReference
{
    /** @return list<array{id: string, code: string, name: string}> active Categories, bounded */
    public function options(School $school): array
    {
        return EmployeeCategory::query()
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'code', 'name'])
            ->map(fn (EmployeeCategory $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])
            ->values()
            ->all();
    }

    /**
     * The names of these Categories, in ONE read (inactive ones included:
     * an existing rule keeps naming the Category it was written with).
     *
     * @param  list<string>  $categoryIds
     * @return array<string, string> id => name
     */
    public function names(School $school, array $categoryIds): array
    {
        $ids = array_values(array_filter($categoryIds, fn (string $id) => Str::isUuid($id)));
        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> */
        return EmployeeCategory::query()->where('school_id', $school->id)->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /** Inside the caller's write transaction: share-locks the Category and answers whether it is an active Category of this School. */
    public function lockActive(School $school, string $categoryId): bool
    {
        return Str::isUuid($categoryId) && EmployeeCategory::query()
            ->where('school_id', $school->id)
            ->whereKey($categoryId)
            ->where('status', 'active')
            ->sharedLock()
            ->exists();
    }
}
