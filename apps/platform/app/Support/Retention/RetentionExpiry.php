<?php

namespace App\Support\Retention;

use App\Models\School;
use App\Support\Observability\MetricsRecorder;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * E21.2B (E21-D1/D2/D6): the ONLY caller of the database retention
 * functions (migration 2026_11_06_090000; architecture guard).
 *
 * The functions are SECURITY DEFINER with fixed tables and predicates, an
 * age floor and a tenant tie. The runtime role holds EXECUTE on them, but
 * no DELETE on the ledgers.
 *
 * This class adds the application half:
 * - a closed category map (no caller-supplied table, function or SQL);
 * - bounded batches until exhausted;
 * - the School hold seam;
 * - counts-only metrics.
 *
 * It runs only from the retention console commands, never from a request.
 */
final class RetentionExpiry
{
    public const SCHOOL_AUDIT = 'school_audit';

    public const PLATFORM_AUDIT = 'platform_audit';

    public const RELEASED_SUPPRESSION = 'released_suppression';

    public const SCHOOL_ROLE_GRANT = 'school_role_grant';

    public const TEACHING_ASSIGNMENT = 'teaching_assignment';

    public const SCHOOL_ELEVATION = 'school_elevation';

    public const GROUP_ROLE_GRANT = 'group_role_grant';

    public const PLATFORM_ROLE_GRANT = 'platform_role_grant';

    /** E21.2C (E21-D3): append-only delivery policy decisions, 1 year after created_at. */
    public const COMMUNICATION_POLICY_DECISION = 'communication_policy_decision';

    /** E21.2F (E21-D10): a closed erasure case, 7 years after it closed. */
    public const ERASURE_CASE = 'erasure_case';

    /** School-scoped categories => their fixed database function. */
    private const SCHOOL_FUNCTIONS = [
        self::SCHOOL_AUDIT => 'retention_expire_school_audit_events',
        self::SCHOOL_ROLE_GRANT => 'retention_expire_membership_role_assignments',
        self::TEACHING_ASSIGNMENT => 'retention_expire_teaching_assignments',
        self::SCHOOL_ELEVATION => 'retention_expire_school_elevations',
        self::COMMUNICATION_POLICY_DECISION => 'retention_expire_communication_delivery_policy_decisions',
    ];

    /** Categories that belong to no School => their fixed database function. */
    private const PLATFORM_FUNCTIONS = [
        self::PLATFORM_AUDIT => 'retention_expire_platform_audit_events',
        self::RELEASED_SUPPRESSION => 'retention_expire_released_email_suppressions',
        self::GROUP_ROLE_GRANT => 'retention_expire_group_role_assignments',
        self::PLATFORM_ROLE_GRANT => 'retention_expire_platform_role_assignments',
        self::ERASURE_CASE => 'retention_expire_erasure_cases',
    ];

    /** Categories whose trigger is a School-local date (the cutoff is a date). */
    private const DATE_CUTOFFS = [self::TEACHING_ASSIGNMENT];

    /** @return list<string> every category (the metric's closed `operation` values) */
    public static function categories(): array
    {
        return array_merge(array_keys(self::SCHOOL_FUNCTIONS), array_keys(self::PLATFORM_FUNCTIONS));
    }

    public function __construct(
        private readonly TenantContext $context,
        private readonly RetentionHolds $holds,
        private readonly MetricsRecorder $metrics,
    ) {}

    /**
     * Expires one School-scoped category for one School, inside that School's
     * own tenant context (the database requires it). A held School deletes
     * nothing; its eligible rows are counted as held.
     *
     * @return array{eligible: int, deleted: int, held: int}
     */
    public function forSchool(string $category, School $school, CarbonInterface $cutoff, int $batch, bool $dryRun): array
    {
        $function = self::SCHOOL_FUNCTIONS[$category] ?? throw new InvalidArgumentException("Not a School-scoped retention category: {$category}");

        return $this->context->withSchool($school, fn () => $this->run(
            $category,
            fn (int $limit, bool $count) => $this->call($function, [$school->id, $this->cutoffFor($category, $cutoff), $limit, $count]),
            $batch,
            $dryRun,
            $this->holds->isHeld($school->id),
        ));
    }

    /**
     * Expires one category that belongs to no School. RETENTION_HOLD_PLATFORM
     * holds them all.
     *
     * @return array{eligible: int, deleted: int, held: int}
     */
    public function forPlatform(string $category, CarbonInterface $cutoff, int $batch, bool $dryRun): array
    {
        $function = self::PLATFORM_FUNCTIONS[$category] ?? throw new InvalidArgumentException("Not a platform retention category: {$category}");

        return $this->run(
            $category,
            fn (int $limit, bool $count) => $this->call($function, [$this->cutoffFor($category, $cutoff), $limit, $count]),
            $batch,
            $dryRun,
            $this->holds->platformHeld(),
        );
    }

    /**
     * @param  callable(int, bool): int  $expire
     * @return array{eligible: int, deleted: int, held: int}
     */
    private function run(string $category, callable $expire, int $batch, bool $dryRun, bool $held): array
    {
        $eligible = $expire($batch, true);
        $result = ['eligible' => $eligible, 'deleted' => 0, 'held' => 0];

        if ($held) {
            $result['held'] = $eligible;
        } elseif (! $dryRun) {
            do {
                $deleted = $expire($batch, false);
                $result['deleted'] += $deleted;
            } while ($deleted === $batch);
        }

        foreach ($result as $outcome => $count) {
            if ($count > 0) {
                $this->metrics->counter('lycenza_retention_rows_total', $count, ['operation' => $category, 'outcome' => $outcome]);
            }
        }

        return $result;
    }

    private function cutoffFor(string $category, CarbonInterface $cutoff): string
    {
        return in_array($category, self::DATE_CUTOFFS, true) ? $cutoff->toDateString() : $cutoff->format('Y-m-d H:i:s');
    }

    /** @param  list<mixed>  $arguments */
    private function call(string $function, array $arguments): int
    {
        $placeholders = implode(', ', array_fill(0, count($arguments), '?'));
        $row = DB::selectOne("SELECT {$function}({$placeholders}) AS n", array_map(fn ($a) => is_bool($a) ? ($a ? 'true' : 'false') : $a, $arguments));

        return (int) $row->n;
    }
}
