<?php

namespace App\Domain\Fees\Application\Sources;

use App\Domain\Fees\Infrastructure\FeeHead;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * SWC.3 (ADR 0073 section 19.2): the School's fee heads as a summary --
 * id, code, name and whether each is active -- so a source module's web
 * page can offer the selectable ones and label the ones already recorded
 * without reading a Fees table itself (CLAUDE.md rule 4). No accounts, no
 * amounts; read-only.
 *
 * Deliberately separate from FeeSourceSelectionService: that seam records
 * fee intent and has a closed caller allow-list (ADR 0067 section 8,
 * OperationalFeeSourceArchitectureGuardTest). A label read is not a
 * selection, so it never widens that list. Whether a head is selectable is
 * still decided by the OPF services through
 * FeeSourceSelectionService::selectableFeeHead().
 */
final class FeeHeadDirectory
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return list<array{id: string, code: string, name: string, active: bool}> */
    public function forSchool(School $school): array
    {
        return $this->context->withSchool($school, fn () => FeeHead::query()->where('school_id', $school->id)->orderBy('code')->get()
            ->map(fn (FeeHead $head) => ['id' => $head->id, 'code' => $head->code, 'name' => $head->name, 'active' => $head->isActive()])
            ->values()->all());
    }
}
