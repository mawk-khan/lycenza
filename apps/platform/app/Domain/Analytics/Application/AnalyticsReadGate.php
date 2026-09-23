<?php

namespace App\Domain\Analytics\Application;

use App\Domain\Analytics\Application\Exceptions\AnalyticsReportUnavailableException;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * The single execution path for every Analytics read model (Phase
 * 0L.2-1, ADR 0040). In order, BEFORE any source data is read:
 *
 * 1. `analytics.view` in THIS School for THIS actor (403 otherwise).
 *    Source-record capabilities are neither required nor sufficient
 *    (ADR 0040 §5).
 * 2. CohortSuppressionPolicy: a read model that counts people fails
 *    closed while no minimum person-cohort size is approved (503) --
 *    checked from its declaration alone, registered or not.
 * 3. The read model is in AnalyticsReadModelRegistry (503 otherwise).
 * 4. Filters are restricted to the declaration's closed list.
 *
 * Then it computes inside TenantContext::withSchool() for the trusted
 * School only (single-School v1, ADR 0040 §4), and audits the read
 * when the declared tier is Sensitive or Highly Sensitive
 * (`analytics.report_viewed`: read model key and filter ids only,
 * never values). Nothing is cached.
 */
class AnalyticsReadGate
{
    use AuthorizesCapability;

    public const VIEW_CAPABILITY = 'analytics.view';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AnalyticsReadModelRegistry $registry,
        private readonly CohortSuppressionPolicy $policy,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, string>  $filters
     * @return array<string, mixed>
     */
    public function read(AnalyticsReadModel $readModel, School $school, User $actor, array $filters = []): array
    {
        $this->authorizeCapabilityFor($actor, self::VIEW_CAPABILITY, $school);

        $declaration = $readModel->declaration();

        $this->policy->assertServable($declaration);

        if (! $this->registry->isRegistered($readModel)) {
            throw AnalyticsReportUnavailableException::notRegistered($declaration->key);
        }

        $unknown = array_diff(array_keys($filters), $declaration->filters);
        if ($unknown !== []) {
            throw new InvalidArgumentException("Analytics read model [{$declaration->key}] does not accept filter(s): ".implode(', ', $unknown).'.');
        }

        return $this->context->withSchool($school, function () use ($readModel, $school, $actor, $filters, $declaration): array {
            $result = $readModel->compute($school, $filters);

            if ($declaration->tier->requiresReadAudit()) {
                $this->audit->school($school, 'analytics.report_viewed', actor: $actor, metadata: [
                    'readModel' => $declaration->key,
                    'filters' => $filters,
                ]);
            }

            return $result;
        });
    }
}
