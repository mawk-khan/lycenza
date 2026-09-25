<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 0N.9 (ADR 0047 section 8): the execution-time School lifecycle
 * check for School business effects. Business jobs, consumers and
 * services ask here when they RUN -- never trusting what was true when
 * the work was queued -- and each substrate decides its own outcome
 * (defer, skip, create nothing). There is deliberately no blanket check
 * in SetTenantContextForJob: platform safety work (audit, elevation
 * expiry, pruning) must keep running for a suspended School.
 *
 * holdOperational() is the linearization point: it reads the School row
 * FOR SHARE inside the caller's transaction, so it and a lifecycle
 * transition (which updates that row) serialize. Work claimed while it
 * held the lock completes as in-flight work; nothing that asks after a
 * suspension commits proceeds. The lock is released when the caller's
 * transaction ends -- never held across an external HTTP call.
 */
class SchoolOperationalGuard
{
    /** A plain, fresh read (no lock): for refusals with no effect to serialize. */
    public function isOperational(string $schoolId): bool
    {
        return DB::table('schools')->where('id', $schoolId)->value('status') === SchoolStatus::Active->value;
    }

    /**
     * True only if the School is `active`, holding its row FOR SHARE until
     * the caller's transaction ends. Must run inside a transaction.
     */
    public function holdOperational(string $schoolId): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SchoolOperationalGuard::holdOperational() must run inside a database transaction.');
        }

        $row = DB::selectOne('SELECT status FROM schools WHERE id = ? FOR SHARE', [$schoolId]);

        return $row !== null && $row->status === SchoolStatus::Active->value;
    }

    /** holdOperational(), or SchoolNotOperationalException. */
    public function requireOperational(string $schoolId): void
    {
        if (! $this->holdOperational($schoolId)) {
            throw new SchoolNotOperationalException;
        }
    }
}
