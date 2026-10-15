<?php

namespace App\Support\DataHealth;

use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * V11.5 (ADR 0075 section 10): runs the registered Data Health checks for
 * one School, for one viewer.
 *
 * - Authorization per check: a check (and every finding it returns) is
 *   visible only to a viewer holding ALL of its view capabilities -- the
 *   same capabilities that show that data at its source. Holding the Data
 *   Health page never widens what a viewer can learn.
 * - Links per finding: only those whose capabilities the viewer holds.
 * - Tenancy: every check runs inside TenantContext::withSchool($school)
 *   (and scopes its own queries to the School).
 * - Read-only: nothing here or in any check writes, caches or stores a
 *   finding. The overview reports only whether a check found anything
 *   (no counts: a count of people is Analytics, ADR 0040); a finding list
 *   shows its own row total (ADR 0042 section 11).
 */
class DataHealthService
{
    public const int PER_PAGE = 25;

    public function __construct(
        private readonly DataHealthRegistry $registry,
        private readonly CapabilityResolver $capabilities,
        private readonly TenantContext $context,
    ) {}

    /** @return list<DataHealthCheck> */
    public function visibleChecks(School $school, User $actor): array
    {
        return array_values(array_filter(
            $this->registry->all(),
            fn (DataHealthCheck $check) => $this->holdsAll($actor, $check->definition()->viewCapabilities, $school),
        ));
    }

    /**
     * @return list<array{definition: array<string, mixed>, hasFindings: bool}>
     */
    public function overview(School $school, User $actor): array
    {
        return array_map(fn (DataHealthCheck $check) => [
            'definition' => $check->definition()->toArray(),
            'hasFindings' => $this->context->withSchool($school, fn () => $check->hasFindings($school)),
        ], $this->visibleChecks($school, $actor));
    }

    /**
     * @return array{definition: array<string, mixed>, findings: Paginator<int, array<string, mixed>>}|null
     *                                                                                                      null for an unknown check code
     *
     * @throws AuthorizationException when the viewer may not see this check
     */
    public function findings(School $school, string $code, User $actor, int $page): ?array
    {
        $check = $this->registry->find($code);
        if ($check === null) {
            return null;
        }

        $definition = $check->definition();
        if (! $this->holdsAll($actor, $definition->viewCapabilities, $school)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $paginator = $this->context->withSchool($school, fn () => $check->findings($school, max($page, 1), self::PER_PAGE));
        $held = [];
        $holds = function (array $required) use (&$held, $actor, $school): bool {
            foreach ($required as $capability) {
                $held[$capability] ??= $this->capabilities->canInSchool($actor, $capability, $school);
                if (! $held[$capability]) {
                    return false;
                }
            }

            return true;
        };

        /** @var Paginator<int, array<string, mixed>> $findings */
        $findings = new Paginator(
            collect($paginator->items())->map(fn (DataHealthFinding $f) => [
                'entityType' => $f->entityType,
                'entityId' => $f->entityId,
                'label' => $f->label,
                'detail' => $f->detail,
                'links' => array_values(array_map(
                    fn (DataHealthLink $l) => ['label' => $l->label, 'url' => $l->url, 'isRepair' => $l->isRepair],
                    array_filter($f->links, fn (DataHealthLink $l) => $holds($l->requires)),
                )),
            ])->all(),
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => Paginator::resolveCurrentPath()],
        );

        return ['definition' => $definition->toArray(), 'findings' => $findings];
    }

    /** @param  list<string>  $capabilities */
    private function holdsAll(User $actor, array $capabilities, School $school): bool
    {
        foreach ($capabilities as $capability) {
            if (! $this->capabilities->canInSchool($actor, $capability, $school)) {
                return false;
            }
        }

        return true;
    }
}
