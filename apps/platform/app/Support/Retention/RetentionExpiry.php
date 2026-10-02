<?php

namespace App\Support\Retention;

use App\Models\School;
use App\Support\Observability\MetricsRecorder;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

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

    /** E21.3E (E21.2G I3, E21-D6): an ended API client credential, 7 years after its authority ended. */
    public const SCHOOL_API_CREDENTIAL = 'school_api_credential';

    /** E21.2F (E21-D10): a closed erasure case, 7 years after it closed. */
    public const ERASURE_CASE = 'erasure_case';

    /** E21.3B (E21.2G P1): a Student's processing authorizations, with its core record. */
    public const STUDENT_PROCESSING_AUTHORIZATION = 'student_processing_authorization';

    /** E21.3B (E21.2G C4): a Student's consent events, with its core record. */
    public const STUDENT_CONSENT_EVENT = 'student_consent_event';

    /**
     * E21.3B: Student-core evidence => its fixed database function. Each
     * removes ONE Student's rows inside the core purge transaction.
     */
    private const STUDENT_CORE_FUNCTIONS = [
        self::STUDENT_PROCESSING_AUTHORIZATION => 'retention_expire_student_processing_authorizations',
        self::STUDENT_CONSENT_EVENT => 'retention_expire_student_consent_events',
    ];

    /** School-scoped categories => their fixed database function. */
    private const SCHOOL_FUNCTIONS = [
        self::SCHOOL_AUDIT => 'retention_expire_school_audit_events',
        self::SCHOOL_ROLE_GRANT => 'retention_expire_membership_role_assignments',
        self::TEACHING_ASSIGNMENT => 'retention_expire_teaching_assignments',
        self::SCHOOL_ELEVATION => 'retention_expire_school_elevations',
        self::COMMUNICATION_POLICY_DECISION => 'retention_expire_communication_delivery_policy_decisions',
        self::SCHOOL_API_CREDENTIAL => 'retention_expire_api_client_credentials',
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
     * E21.3A2 (E21-D8): expires ONE settled Finance unit -- the given charges
     * with everything that depends on them, plus the given standalone
     * journal entries -- through `retention_expire_finance_unit`, which
     * re-verifies the whole unit in the database (closed under references,
     * settled, every entry in a period closed >= 8 calendar years ago,
     * baseline present). With `$dryRun` it validates and counts only.
     * Runs inside the caller's School TenantContext and transaction;
     * the caller (Finance retention) owns holds, metrics and verification.
     *
     * @param  list<string>  $chargeIds
     * @param  list<string>  $entryIds
     * @return int journal entries in the unit
     */
    public function financeUnit(School $school, array $chargeIds, array $entryIds, bool $dryRun): int
    {
        $array = fn (array $ids): string => '{'.implode(',', array_map(fn (string $id) => Str::isUuid($id) ? $id : throw new InvalidArgumentException('Not a uuid.'), $ids)).'}';

        return (int) DB::selectOne(
            'SELECT retention_expire_finance_unit(?, ?::uuid[], ?::uuid[], ?, ?) AS n',
            [$school->id, $array($chargeIds), $array($entryIds), (string) new UuidV7, $dryRun ? 'true' : 'false'],
        )->n;
    }

    /**
     * E21.3B (E21-D7 core): removes one Student's rows of an append-only
     * Student-core evidence table through its fixed function, which
     * re-proves the core floor in the database (the School is the tenant
     * context; the Student row locked; inactive; every placement ended
     * before a cutoff at least 25 calendar years back). With `$dryRun` it
     * validates and counts only. Runs inside the caller's School
     * TenantContext and one-Student purge transaction; the Student core
     * purge owns holds, metrics and eligibility.
     *
     * @param  string  $cutoffDate  the School-local core cutoff (Y-m-d)
     * @return int rows (that would be) removed
     */
    public function studentCoreEvidence(string $category, School $school, string $studentId, string $cutoffDate, bool $dryRun): int
    {
        $function = self::STUDENT_CORE_FUNCTIONS[$category] ?? throw new InvalidArgumentException("Not a Student-core evidence category: {$category}");
        if (! Str::isUuid($studentId)) {
            throw new InvalidArgumentException('Not a uuid.');
        }

        return $this->call($function, [$school->id, $studentId, $cutoffDate, $dryRun]);
    }

    /**
     * E21.3C (E21.2G G1/C4): removes one Guardian subject's consent events
     * through `retention_expire_guardian_consent_events`, which re-proves the
     * Guardian floor in the database (tenant context; the Guardian row
     * locked; no relationship; `no_relationship_since` strictly before a
     * cutoff at least one calendar year old). With `$dryRun` it validates
     * and counts only. Runs inside the caller's School TenantContext and
     * one-Guardian purge transaction.
     *
     * @param  CarbonInterface  $cutoff  UTC
     */
    public function guardianConsentEvents(School $school, string $guardianId, CarbonInterface $cutoff, bool $dryRun): int
    {
        if (! Str::isUuid($guardianId)) {
            throw new InvalidArgumentException('Not a uuid.');
        }

        return $this->call('retention_expire_guardian_consent_events', [$school->id, $guardianId, $cutoff->copy()->utc()->format('Y-m-d H:i:s'), $dryRun]);
    }

    /**
     * E21.3F (E21-D9): removes ONE Employee's posted payroll evidence
     * (results with lines and statutory results, adjustments, LWF charges)
     * through its fixed function, which re-proves in the database: tenant
     * context; the Employee locked and finally separated before a cutoff at
     * least 8 calendar years back; every run holding the evidence locked,
     * posted, and with every posting older than the cutoff. Journal entries
     * are never touched. Runs inside the caller's School context and unit
     * transaction.
     *
     * @param  string  $cutoffDate  the School-local cutoff (Y-m-d)
     */
    public function payrollEmployeeEvidence(School $school, string $employeeId, string $cutoffDate, bool $dryRun): int
    {
        if (! Str::isUuid($employeeId)) {
            throw new InvalidArgumentException('Not a uuid.');
        }

        return $this->call('retention_expire_payroll_employee_evidence', [$school->id, $employeeId, $cutoffDate, $dryRun]);
    }

    /**
     * E21.3F (E21-D9 x E21-D8): removes ONE emptied regular payroll run with
     * its correction runs and their postings (payroll and statutory), which
     * releases their journal entries to Finance's own D8 expiry. The
     * database re-proves: tenant context; every run posted before a cutoff
     * at least 8 years back, emptied by payroll retention, holding no
     * result or adjustment; every posting older than the cutoff.
     *
     * @return int the postings removed (each one journal entry released)
     */
    public function payrollRun(School $school, string $runId, CarbonInterface $cutoff, bool $dryRun): int
    {
        if (! Str::isUuid($runId)) {
            throw new InvalidArgumentException('Not a uuid.');
        }

        return $this->call('retention_expire_payroll_run', [$school->id, $runId, $cutoff->copy()->utc()->format('Y-m-d H:i:s'), $dryRun]);
    }

    /**
     * E21.3D (E21.2G A1): removes ONE LMS resource (`learning_content` or
     * `assignment`) with its Section audiences through its fixed function,
     * which re-proves in the database: tenant context; the resource locked;
     * its Academic Year ended before a cutoff at least 7 calendar years
     * back; for an owned resource no TeachingAssignment of the owner over
     * an audience Section open or ended on/after the cutoff (D6); no
     * Document left. Runs inside the caller's School context and unit
     * transaction.
     *
     * @param  string  $cutoffDate  the School-local cutoff (Y-m-d)
     */
    public function lmsResource(string $kind, School $school, string $id, string $cutoffDate, bool $dryRun): int
    {
        $function = ['learning_content' => 'retention_expire_learning_content', 'assignment' => 'retention_expire_assignment'][$kind]
            ?? throw new InvalidArgumentException("Not an LMS resource kind: {$kind}");
        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('Not a uuid.');
        }

        return $this->call($function, [$school->id, $id, $cutoffDate, $dryRun]);
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
                $this->metrics->counter('lycenza_retention_rows_total', $count, ['operation' => RetentionMetrics::family($category), 'outcome' => $outcome]);
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
