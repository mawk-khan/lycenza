<?php

namespace App\Support\Tenancy;

use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use Throwable;

/**
 * Queue job middleware (section 25): initializes TenantContext (and,
 * through it, the Postgres RLS session variable) from the job's
 * captured payload BEFORE the job runs, and unconditionally clears it
 * after -- so a long-running worker processing many jobs for many
 * Schools in the same PHP process/connection can never leak one job's
 * tenant context into the next.
 *
 * Deliberately try/catch, not try/finally -- see
 * docs/architecture/TENANCY.md ("Root cause: aborted-transaction
 * cleanup"). If the job's own handler throws after leaving the
 * connection in PostgreSQL's aborted-transaction state (e.g. a job
 * that does a raw write outside its own DB::transaction()), a bare
 * `finally { $context->clearAll(); }` could itself fail with SQLSTATE
 * 25P02 and REPLACE the job's real original exception with that
 * unrelated failure -- exactly the defect
 * TenantContext::withSchool()'s own restoreAfterFailure()/
 * restoreOnSuccess() split fixes, applied here to this middleware's
 * identical try/finally shape. `clearAllAfterFailure()` preserves the
 * job's real exception; plain `clearAll()` on the success path is
 * unchanged (a job that returns normally but somehow still leaves the
 * connection poisoned is a genuine job-authoring bug worth failing
 * loudly on, not hiding).
 */
class SetTenantContextForJob
{
    public function handle($job, callable $next): void
    {
        $context = app(TenantContext::class);

        try {
            if ($job->contextSchoolId !== null) {
                $school = School::findOrFail($job->contextSchoolId);
                $context->set($school);

                if ($job->contextCampusId !== null) {
                    // Requery now that School context is set, so
                    // Campus's own SchoolScope resolves correctly
                    // (see TenantContext::withSchool()'s docblock for
                    // the same "set context before querying
                    // RLS/scope-protected rows" principle).
                    $campus = Campus::find($job->contextCampusId);
                    if ($campus !== null) {
                        $context->set($school, $campus);
                    }
                }
            }

            if ($job->contextActorId !== null) {
                $context->setActor(User::find($job->contextActorId));
            }

            $context->setRequestId($job->contextRequestId);
            $context->setCorrelationId($job->contextCorrelationId);

            $next($job);
        } catch (Throwable $e) {
            $context->clearAllAfterFailure();

            throw $e;
        }

        $context->clearAll();
    }
}
