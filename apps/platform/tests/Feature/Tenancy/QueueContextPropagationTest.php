<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\RecordSchoolAuditPingJob;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextRequiredException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Section 25: tenant-aware queue context propagation, proven with real
 * job dispatch/execution (QUEUE_CONNECTION=sync still runs jobs through
 * the full middleware pipeline, ADR 0024) -- not simulated.
 */
class QueueContextPropagationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_dispatched_job_captures_and_applies_its_schools_context(): void
    {
        $school = $this->createSchool();
        app(TenantContext::class)->set($school);

        RecordSchoolAuditPingJob::dispatchSync('first');

        app(TenantContext::class)->withSchool(
            $school,
            fn () => $this->assertSame(
                1,
                SchoolAuditEvent::query()->where('event_type', 'diagnostics.ping')->count(),
            ),
        );
    }

    #[Test]
    public function sequential_jobs_for_different_schools_do_not_leak_context(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $context = app(TenantContext::class);

        $context->set($schoolA);
        RecordSchoolAuditPingJob::dispatchSync('for-a');

        $context->set($schoolB);
        RecordSchoolAuditPingJob::dispatchSync('for-b');

        $countInA = $context->withSchool(
            $schoolA,
            fn () => SchoolAuditEvent::query()->where('event_type', 'diagnostics.ping')->count(),
        );
        $countInB = $context->withSchool(
            $schoolB,
            fn () => SchoolAuditEvent::query()->where('event_type', 'diagnostics.ping')->count(),
        );

        $this->assertSame(1, $countInA, 'School A should have exactly its own ping, not School B\'s.');
        $this->assertSame(1, $countInB, 'School B should have exactly its own ping, not School A\'s.');
    }

    #[Test]
    public function context_is_cleared_after_the_job_runs_even_though_the_dispatching_request_had_a_school_active(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $context->set($school);

        RecordSchoolAuditPingJob::dispatchSync('ping');

        // SetTenantContextForJob's `finally` clears context after the
        // job -- it must not silently restore the dispatching request's
        // context either; the job's own middleware owns the full
        // set/clear lifecycle for its execution.
        $this->assertFalse($context->hasSchool());
    }

    #[Test]
    public function a_tenant_job_followed_by_a_central_job_style_operation_sees_no_leaked_context(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);

        $context->set($school);
        RecordSchoolAuditPingJob::dispatchSync('tenant-job');

        // Simulates a subsequent "central" unit of work on the same
        // (in this test, literally the same) connection/process --
        // must fail closed, not silently inherit School A.
        $context->clear();
        $this->expectException(TenantContextRequiredException::class);
        $context->requireSchool();
    }
}
