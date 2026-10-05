<?php

namespace App\Domain\Transport\Application\Retention;

use App\Models\School;
use App\Support\Retention\RetentionBatch;
use App\Support\Retention\RetentionExpiry;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * E21.3E (E21.2G O2, project-adopted, pending legal ratification): an ENDED
 * driver (route) assignment is kept OPERATIONS_DRIVER_ASSIGNMENT_RETENTION_YEARS
 * (adopted 7) calendar years after its canonical end, then deleted. Only
 * `platform:operations-retention-prune` calls this, never a request.
 *
 * - The end is `ends_on`, set with `status = 'ended'` in the one-way
 *   conditional UPDATE (TransportRouteAssignmentService; a CHECK ties the
 *   two): an ended assignment is never reactivated, a new one is a new
 *   row. An active assignment is never eligible. Never `updated_at`, and
 *   not the Employee's separation.
 * - Its driver reference (`driver_employee_id`) goes with it, never
 *   earlier: the Employee then follows its own D9 clock. Routes, vehicles
 *   and stops are School configuration and stay.
 * - Bounded batches (RetentionBatch); any referencing row keeps it. A held
 *   School is counted only. Counts only.
 */
final class DriverAssignmentRetentionService
{
    private const TABLE = 'transport_route_assignments';

    public function __construct(
        private readonly TenantContext $context,
        private readonly RetentionBatch $batches,
    ) {}

    /**
     * @param  CarbonInterface  $cutoff  UTC: an assignment that ended strictly before it is eligible
     * @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    public function prune(School $school, CarbonInterface $cutoff, int $batch, bool $dryRun, bool $held): array
    {
        // E21-RH.6 (ADR 0066 §14): the whole unit as the retention identity, on its own connection.
        return app(RetentionExpiry::class)->retained('driver_assignment', $dryRun || $held, $school->id, ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0], fn (): array => $this->pruneUnit($school, $cutoff, $batch, $dryRun, $held));
    }

    /** @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int} */
    private function pruneUnit(School $school, CarbonInterface $cutoff, int $batch, bool $dryRun, bool $held): array
    {
        $at = $cutoff->copy()->utc()->format('Y-m-d H:i:s');

        return $this->context->withSchool($school, fn (): array => $this->batches->prune(
            self::TABLE,
            fn () => DB::table(self::TABLE.' as t')->where('t.school_id', $school->id)->where('t.status', 'ended')->whereNotNull('t.ends_on')->where('t.ends_on', '<', $at),
            $batch,
            $dryRun,
            $held,
        ));
    }
}
