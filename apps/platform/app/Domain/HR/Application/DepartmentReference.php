<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Department;
use App\Models\School;
use Illuminate\Support\Str;

/**
 * ORG.9C-2 (ADR 0076 §70): HR's narrow answer to "which Department may
 * another domain name as an organisational recipient" -- today only an
 * Inventory issue. A Department is School configuration (tenant lifetime,
 * no personal data); this returns id, code and name only and never a
 * person, a position or a reporting line. The one direction is Inventory ->
 * HR (DOMAIN-MAP); HR never reads Inventory.
 *
 * Both methods MUST run inside the target School's TenantContext.
 */
final class DepartmentReference
{
    private const LIMIT = 20;

    /**
     * Active Departments a picker may offer: School-wide ones, plus those
     * on $campusIds (null: every Campus, a whole-School actor).
     *
     * @param  list<string>|null  $campusIds
     * @return list<array{id: string, code: string, name: string}>
     */
    public function pickable(School $school, ?array $campusIds, string $term): array
    {
        $term = trim($term);
        $like = '%'.addcslashes($term, '%_\\').'%';

        return Department::query()
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->when($campusIds !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('campus_id')->orWhereIn('campus_id', $campusIds)))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like)))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'code', 'name'])
            ->map(fn (Department $d) => ['id' => $d->id, 'code' => $d->code, 'name' => $d->name])
            ->values()
            ->all();
    }

    /**
     * The names of these Departments, in ONE read (a movement list labels its
     * issue recipients; never a per-row lookup).
     *
     * @param  list<string>  $departmentIds
     * @return array<string, string> id => name
     */
    public function names(School $school, array $departmentIds): array
    {
        $ids = array_values(array_filter($departmentIds, fn (string $id) => Str::isUuid($id)));
        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> */
        return Department::query()->where('school_id', $school->id)->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * Inside the caller's write transaction: share-locks the Department and
     * answers whether it may receive stock from a Location on
     * $locationCampusId (null: a School-wide store). It must be active and
     * School-wide or on that same Campus -- never another Campus's.
     */
    public function lockIssuable(School $school, string $departmentId, ?string $locationCampusId): bool
    {
        if (! Str::isUuid($departmentId)) {
            return false;
        }

        $department = Department::query()
            ->where('school_id', $school->id)
            ->whereKey($departmentId)
            ->sharedLock()
            ->first(['id', 'campus_id', 'status']);

        return $department !== null
            && $department->status === 'active'
            && ($department->campus_id === null || $locationCampusId === null || $department->campus_id === $locationCampusId);
    }
}
