<?php

namespace App\Support\Retention;

use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * E21.3E (E21.2G O4, project-adopted, pending legal ratification): a
 * COMPLETED automation execution, with its attempts and review items, is
 * kept OPERATIONS_AUTOMATION_RETENTION_YEARS (adopted 1) calendar year after
 * `completed_at`, then deleted. Only `platform:operations-retention-prune`
 * calls this, never a request.
 *
 * - Completed means a terminal status with `completed_at`: `succeeded`,
 *   `skipped` and `abandoned` (attempts exhausted), set by
 *   AutomationExecutionService in the same UPDATE as `completed_at`; and
 *   `failed` if ever recorded so. A terminal execution is never reclaimed
 *   (the claim accepts only `pending`/`running`). `pending`, `running` and a
 *   retry waiting in `pending` are never eligible, whatever their age.
 * - Its attempts and review items are its own append-only history and go
 *   with it by ON DELETE CASCADE (pinned by the classification test). Rule
 *   instances (the School's automation configuration) stay.
 * - It lives in Support because the Automation module may not touch tables
 *   through the query builder (AutomationArchitectureGuardTest); it reads
 *   only Automation's execution table, through RetentionBatch.
 * - Bounded batches; a held School is counted only. Counts only, never a
 *   subject or payload.
 */
final class AutomationExecutionRetention
{
    public const COMPLETED = ['succeeded', 'skipped', 'failed', 'abandoned'];

    private const TABLE = 'automation_executions';

    /** The execution's own history, removed with it (ON DELETE CASCADE). */
    private const OWNED = ['automation_execution_attempts', 'automation_review_items'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly RetentionBatch $batches,
    ) {}

    /**
     * @param  CarbonInterface  $cutoff  UTC: an execution completed strictly before it is eligible
     * @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    public function prune(School $school, CarbonInterface $cutoff, int $batch, bool $dryRun, bool $held): array
    {
        // E21-RH.6 (ADR 0066 §14): the whole unit as the retention identity, on its own connection.
        return app(RetentionExpiry::class)->retained('automation_execution', $dryRun || $held, $school->id, ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0], fn (): array => $this->pruneUnit($school, $cutoff, $batch, $dryRun, $held), recordedBefore: $cutoff);
    }

    /** @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int} */
    private function pruneUnit(School $school, CarbonInterface $cutoff, int $batch, bool $dryRun, bool $held): array
    {
        $at = $cutoff->copy()->utc()->format('Y-m-d H:i:s');

        return $this->context->withSchool($school, fn (): array => $this->batches->prune(
            self::TABLE,
            fn () => DB::table(self::TABLE.' as t')->where('t.school_id', $school->id)->whereIn('t.status', self::COMPLETED)
                ->whereNotNull('t.completed_at')->where('t.completed_at', '<', $at),
            $batch,
            $dryRun,
            $held,
            self::OWNED,
        ));
    }
}
