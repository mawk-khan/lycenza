<?php

namespace App\Support\Tenancy;

use App\Models\Campus;
use App\Models\School;
use App\Models\User;

/**
 * Queue job middleware (section 25): initializes TenantContext (and,
 * through it, the Postgres RLS session variable) from the job's
 * captured payload BEFORE the job runs, and unconditionally clears it
 * in `finally` after -- so a long-running worker processing many jobs
 * for many Schools in the same PHP process/connection can never leak
 * one job's tenant context into the next.
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
        } finally {
            $context->clearAll();
        }
    }
}
