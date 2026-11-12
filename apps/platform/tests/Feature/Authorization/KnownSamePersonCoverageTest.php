<?php

namespace Tests\Feature\Authorization;

use App\Domain\HR\Infrastructure\Employee;
use App\Support\Approval\ApprovalWorkflowRegistry;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C34 (WO-16; owner decision HR.R1-C33): which decisions refuse an account known to be the same
 * person as a participant, and that each declaration is real.
 *
 * - Every request/decide workflow declares `knownSamePerson`: the HR family and Payroll enforce it, the
 *   other five are exactly the C33 handoffs. Of the direct-assured actions, exactly Payroll posting
 *   (its attestation) and the reconciliation review enforce it.
 * - Each enforced declaration names a service method that calls the HR rule, and a trigger that is
 *   enabled, bound to the expected function, and whose body calls the SQL relation.
 * - The SQL relation agrees with the C8 relation on every pair of a fixture.
 *
 * Limits: a source scan sees a method's text, not every path that reaches it, and cannot see a name
 * built at run time; `prosrc` proves the call is present, not that every branch reaches it (the
 * behaviour tests -- PayrollKnownSamePersonTest, LeaveKnownSamePersonTest,
 * SeparationKnownSamePersonTest -- prove that). One runtime role serves every domain, so the database
 * cannot know which human acted: it judges the recorded decider column (rule 102).
 */
class KnownSamePersonCoverageTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const ENFORCED_DOMAINS = ['HR', 'Leave', 'StaffAttendance', 'Payroll'];

    private const HANDOFFS = [
        'fee_concessions' => 'C33-H1',
        'payment_corrections' => 'C33-H2',
        'communication_approvals' => 'C33-H3',
        'student_mark_corrections' => 'C33-H4',
        'erasure_cases' => 'C33-H5',
    ];

    private const DIRECT = [
        'App\\Domain\\Payroll\\Application\\PayrollPostingService',
        'App\\Domain\\Payroll\\Application\\PayrollReversalReconciliationService',
    ];

    /** @return array<string, array{check: string, dbGuard: string}> */
    private function enforced(): array
    {
        $out = [];
        foreach (ApprovalWorkflowRegistry::WORKFLOWS as $key => $w) {
            if (isset($w['knownSamePerson']['check'])) {
                $out[$key] = $w['knownSamePerson'];
            }
        }
        foreach (ApprovalWorkflowRegistry::DIRECT_ASSURED as $class => $meta) {
            if (isset($meta['knownSamePerson'])) {
                $out[$class] = $meta['knownSamePerson'];
            }
        }

        return $out;
    }

    #[Test]
    public function every_workflow_declares_it_the_hr_family_and_payroll_enforce_it_and_the_rest_are_the_c33_handoffs(): void
    {
        $handoffs = [];
        foreach (ApprovalWorkflowRegistry::WORKFLOWS as $key => $w) {
            $this->assertArrayHasKey('knownSamePerson', $w, "{$key}: declare whether a known same person is refused (C33)");
            if (in_array($w['domain'], self::ENFORCED_DOMAINS, true)) {
                $this->assertSame(['check', 'dbGuard'], array_keys($w['knownSamePerson']), "{$key}: an HR-family or Payroll workflow enforces it");
            } else {
                $this->assertSame(['handoff'], array_keys($w['knownSamePerson']), "{$key}: outside HR and Payroll it is the owning domain's decision");
                $handoffs[$key] = $w['knownSamePerson']['handoff'];
            }
        }
        $this->assertSame(self::HANDOFFS, $handoffs);

        $direct = array_keys(array_filter(ApprovalWorkflowRegistry::DIRECT_ASSURED, fn (array $m) => isset($m['knownSamePerson'])));
        $this->assertSame(self::DIRECT, $direct, 'the decided direct actions, and no others');
    }

    #[Test]
    public function each_declared_check_calls_the_hr_rule_and_each_guard_is_pinned_by_the_verifier(): void
    {
        $guards = [];
        foreach ($this->enforced() as $key => ['check' => $check, 'dbGuard' => $guard]) {
            [$class, $method] = explode('::', $check);
            $reflection = new ReflectionMethod($class, $method);
            $lines = array_slice(file((string) $reflection->getFileName()), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1);
            $this->assertMatchesRegularExpression('/knownSame|isKnownToBeEmployee/', implode('', $lines), "{$key}: {$check} applies the known-same-person rule");
            $guards[] = $guard;
        }
        $pinned = array_keys(DatabaseRoleVerifier::KNOWN_SAME_PERSON_GUARDS);
        sort($guards);
        sort($pinned);
        $this->assertSame($pinned, $guards, 'every declared guard is pinned by platform:verify-database, and nothing else is');
    }

    #[Test]
    public function each_guard_is_enabled_bound_and_calls_the_relation(): void
    {
        foreach (DatabaseRoleVerifier::KNOWN_SAME_PERSON_GUARDS as $trigger => [$table, $function, $relation]) {
            $row = DB::selectOne(
                'select t.tgenabled, p.proname, p.prosrc from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid where c.relname = ? and t.tgname = ?',
                [$table, $trigger],
            );
            $this->assertNotNull($row, $trigger);
            $this->assertSame(['O', $function], [$row->tgenabled, $row->proname], $trigger);
            $this->assertStringContainsString('public.'.$relation.'(', (string) $row->prosrc, "{$trigger} calls {$relation}");
        }

        $fn = DB::selectOne("select p.prosecdef, p.provolatile, array_to_string(p.proconfig, '|') as config,
                has_function_privilege('school_os_app', p.oid, 'EXECUTE') as app, has_function_privilege('public', p.oid, 'EXECUTE') as pub
            from pg_proc p where p.proname = 'employee_account_link_same_person_accounts'");
        $this->assertSame([false, 's', 'search_path=pg_catalog, public, pg_temp', true, false], [$fn->prosecdef, $fn->provolatile, $fn->config, $fn->app, $fn->pub], 'INVOKER (RLS applies), STABLE, pg_temp last, the runtime role only');
    }

    #[Test]
    public function the_relation_agrees_with_the_c8_relation_on_every_pair(): void
    {
        $school = $this->createSchool();
        [$a, $b, $c, $d, $e] = array_map(fn () => $this->createUser(), range(1, 5));
        $context = app(TenantContext::class);
        $relink = function (string ...$users) use ($school, $context): void {
            $employee = $this->createEmployee($school, ['user_id' => null]);
            foreach ($users as $user) {
                $context->withSchool($school, fn () => Employee::query()->whereKey($employee->id)->update(['user_id' => $user === '' ? null : $user]));
            }
        };
        $relink($a->id, '', $b->id, '');      // a and b: one person
        $relink($b->id, '', $c->id);          // b and c: one person (a and c share no Employee: NOT known to be one person)
        $relink($d->id);                      // d: linked, alone

        $users = [$a, $b, $c, $d, $e];
        foreach ($users as $x) {
            foreach ($users as $y) {
                [$aliases, $c8] = $context->withSchool($school, fn () => [
                    array_column(DB::select('select user_id from employee_account_link_same_person_accounts(?, ?)', [$school->id, $x->id]), 'user_id'),
                    (bool) DB::selectOne('select employee_account_link_correction_same_person(?, ?, ?) as s', [$school->id, $x->id, $y->id])->s,
                ]);
                $this->assertSame($x->id !== $y->id && $c8, in_array($y->id, $aliases, true), "pair {$x->id}/{$y->id}");
            }
        }
        $this->assertSame([], $context->withSchool($school, fn () => DB::select('select user_id from employee_account_link_same_person_accounts(?, ?)', [$school->id, $e->id])), 'never linked: unknown');
    }
}
