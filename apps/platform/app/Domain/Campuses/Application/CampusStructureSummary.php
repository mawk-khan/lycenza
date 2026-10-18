<?php

namespace App\Domain\Campuses\Application;

use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * ORG.3 (ADR 0076 §9.2, §10): the platform registry's view of one School's
 * Campus structure -- counts, and names/codes on the overview. Read inside
 * that one School's TenantContext (campuses are FORCE-RLS), never across
 * Schools, never cached. Campus is data here, never authority.
 */
class CampusStructureSummary
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array{active: int, inactive: int} */
    public function counts(School $school): array
    {
        $row = $this->context->withSchool($school, fn () => Campus::query()
            ->where('school_id', $school->id)
            ->selectRaw("count(*) filter (where status = 'active') as active, count(*) filter (where status <> 'active') as inactive")
            ->toBase()
            ->first());

        return ['active' => (int) ($row->active ?? 0), 'inactive' => (int) ($row->inactive ?? 0)];
    }

    /** @return list<array{name: string, code: string, status: string}> */
    public function campuses(School $school): array
    {
        return $this->context->withSchool($school, fn () => Campus::query()
            ->where('school_id', $school->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['name', 'code', 'status'])
            ->map(fn (Campus $campus): array => ['name' => $campus->name, 'code' => $campus->code, 'status' => $campus->status])
            ->values()
            ->all());
    }
}
