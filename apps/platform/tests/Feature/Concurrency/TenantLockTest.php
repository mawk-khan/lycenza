<?php

namespace Tests\Feature\Concurrency;

use App\Support\Concurrency\TenantLock;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0C.4 section 26/27: proves the actual isolation property a
 * distributed lock exists for -- School A holding a lock for
 * "operation X" must never block School B from acquiring "operation X"
 * at the same time, while two callers for the SAME School and
 * operation genuinely contend.
 */
class TenantLockTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function two_different_schools_can_hold_the_same_named_operation_lock_simultaneously(): void
    {
        $lock = new TenantLock;
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $lockA = $lock->forSchool($schoolA, 'nightly-report');
        $lockB = $lock->forSchool($schoolB, 'nightly-report');

        $this->assertTrue($lockA->get());
        $this->assertTrue($lockB->get());

        $lockA->release();
        $lockB->release();
    }

    #[Test]
    public function the_same_school_and_operation_cannot_be_locked_twice_concurrently(): void
    {
        $lock = new TenantLock;
        $school = $this->createSchool();

        $first = $lock->forSchool($school, 'nightly-report');
        $second = $lock->forSchool($school, 'nightly-report');

        $this->assertTrue($first->get());
        $this->assertFalse($second->get());

        $first->release();
        $this->assertTrue($second->get());
        $second->release();
    }

    #[Test]
    public function a_central_lock_is_namespaced_separately_from_any_school_lock(): void
    {
        $lock = new TenantLock;
        $school = $this->createSchool();

        $schoolLock = $lock->forSchool($school, 'shared-name');
        $centralLock = $lock->central('shared-name');

        $this->assertTrue($schoolLock->get());
        // A central lock sharing the SAME operation name as a School
        // lock is a completely different cache key -- it must not be
        // blocked by the School lock being held.
        $this->assertTrue($centralLock->get());

        $schoolLock->release();
        $centralLock->release();
    }
}
