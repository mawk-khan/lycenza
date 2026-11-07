<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\User;
use App\Support\Tenancy\SchoolNotOperationalException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Payroll\Concerns\BuildsPayrollCorrections;
use Tests\Feature\Payroll\Concerns\BuildsPayrollIndependenceScenarios;
use Tests\Feature\Platform\Schools\SchoolLifecycleTestHelpers;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C15 (C14-N6): the School is suspended and resumed through the real platform lifecycle,
 * by a root holding a fresh MFA code, not by a status write. A direct Payroll call is refused
 * while it is suspended and runs once it resumes. Over HTTP it is the request-time check that
 * refuses, before the idempotency middleware, so nothing is stored and the same key works again.
 * The execution-time refusal inside an idempotent route is C14's `IdempotencyFailureClassificationTest`.
 */
class PayrollSchoolLifecycleSuspensionTest extends TestCase
{
    use BuildsPayrollCorrections, BuildsPayrollIndependenceScenarios, CreatesPayrollRetentionFixtures, ProvidesSensitiveActionMfa, SchoolLifecycleTestHelpers;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->root = $this->platformAdmin();
    }

    #[Test]
    public function a_payroll_service_called_directly_is_refused_while_the_lifecycle_has_the_school_suspended(): void
    {
        $period = $this->openPeriod('2015-10-01');

        $this->suspendViaPlatform($this->root, $this->school);
        $this->assertSame('suspended', DB::table('schools')->where('id', $this->school->id)->value('status'));

        $refused = null;
        try {
            $this->inSchool($this->school, fn () => app(PayrollRunService::class)->createRun($period->fresh(), $this->preparer));
        } catch (SchoolNotOperationalException $refused) {
        }
        $this->assertInstanceOf(SchoolNotOperationalException::class, $refused);
        $this->assertSame(0, $this->runsIn($period->id));

        $this->lifecycleAction($this->root, $this->school, 'resume')->assertSessionHasNoErrors();
        $this->inSchool($this->school, fn () => app(PayrollRunService::class)->createRun($period->fresh(), $this->preparer));
        $this->assertSame(1, $this->runsIn($period->id));
    }

    #[Test]
    public function the_same_idempotency_key_runs_once_the_school_resumes(): void
    {
        $period = $this->openPeriod('2015-10-01');
        $token = $this->preparer->createToken('c15')->plainTextToken;
        $create = fn () => $this->withToken($token)->withHeader('Idempotency-Key', 'test-key-c15-resume')
            ->postJson("/api/v1/schools/{$this->school->id}/payroll-periods/{$period->id}/payroll-runs");

        $this->suspendViaPlatform($this->root, $this->school);
        Auth::forgetGuards();
        $create()->assertNotFound();
        $this->assertSame(0, $this->runsIn($period->id));
        $this->assertSame(0, $this->keys(), 'the refusal is not remembered');

        $this->lifecycleAction($this->root, $this->school, 'resume')->assertSessionHasNoErrors();
        Auth::forgetGuards();
        $create()->assertCreated();
        $this->assertSame(1, $this->runsIn($period->id));
        $this->assertSame(1, $this->keys(), 'the same read sees the key the success stored, so the zero above was not blind');
    }

    private function keys(): int
    {
        return $this->inSchool($this->school, fn () => DB::table('api_idempotency_keys')->count());
    }

    private function runsIn(string $periodId): int
    {
        return $this->inSchool($this->school, fn () => PayrollRun::query()->where('payroll_period_id', $periodId)->count());
    }
}
