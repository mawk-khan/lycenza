<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Position;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * HR.13: HR's read-only Position labels for another module (Staff Tasks'
 * designation applicability and attribution) -- the shape of
 * DepartmentReference. Ids and names only; another School's id yields nothing.
 */
final class PositionReference
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return list<array{id: string, label: string}> */
    public function options(School $school): array
    {
        return $this->context->withSchool($school, fn (): array => Position::query()->where('school_id', $school->id)->where('status', 'active')
            ->orderBy('name')->get(['id', 'name'])->map(fn (Position $p) => ['id' => $p->id, 'label' => $p->name])->all());
    }

    /**
     * @param  list<string>  $positionIds
     * @return array<string, string>
     */
    public function names(School $school, array $positionIds): array
    {
        if ($positionIds === []) {
            return [];
        }

        return $this->context->withSchool($school, fn (): array => Position::query()->where('school_id', $school->id)->whereIn('id', $positionIds)
            ->pluck('name', 'id')->all());
    }
}
