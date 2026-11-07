<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\CorrectionDeltaInput;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingConfigurationService;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Statutory\Application\Admin\EmployeeEsiCoverageAdminService;
use App\Domain\Payroll\Statutory\Application\Admin\EmployeePfStatusAdminService;
use App\Domain\Payroll\Statutory\Application\Admin\EmployeeTaxProfileAdminService;
use App\Domain\Payroll\Statutory\Application\Admin\StatutoryIdentifierAdminService;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryRuleVersionNotFoundException;
use App\Domain\Payroll\Statutory\Application\StatutoryAccountingConfigurationService;
use App\Domain\Payroll\Statutory\Application\StatutoryPayrollCalculationService;
use App\Domain\Payroll\Statutory\Application\StatutoryPayrollPostingService;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollSalaryComponentStatutoryClassification;
use App\Models\User;
use App\Support\Tenancy\SchoolNotOperationalException;
use App\Support\Tenancy\SchoolOperationalGuard;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Payroll\Concerns\BuildsPayrollCorrections;
use Tests\Feature\Payroll\Concerns\BuildsPayrollIndependenceScenarios;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C15 (C14-N6, rule 86): every canonical Payroll writer asks the School when it runs.
 *
 * Each case calls the service directly, with no controller or middleware: suspended first, then
 * the same call again after the School resumes. That second call is the positive control (the
 * fixture and the actor are valid) and the retry. A refusal is proven by content, not by row
 * counts: an open, a close or a deactivation adds no row. Reproduced on `1935712c`: every
 * negative case wrote on the suspended School.
 */
class PayrollExecutionTimeSchoolCheckTest extends TestCase
{
    use BuildsPayrollCorrections, BuildsPayrollIndependenceScenarios, CreatesPayrollRetentionFixtures, ProvidesSensitiveActionMfa;

    private User $statutory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->statutorySetup($this->school, $this->setup);
        $this->statutory = $this->createUserWithCapabilities($this->school, ['payroll.statutory.manage', 'payroll.statutory.view', 'payroll.statutory.identifiers.manage', 'payroll.statutory.identifiers.view']);
    }

    /** @return array<string, array{0: string}> */
    public static function writers(): array
    {
        $names = [
            'period create', 'period open', 'period close',
            'run create', 'correction run create', 'correction delta', 'manual override', 'run calculate', 'run approve',
            'component create', 'component deactivate', 'structure draft', 'structure add component', 'structure activate',
            'compensation assign', 'payroll accounting', 'statutory accounting',
            'esi coverage', 'pf status', 'tax profile', 'statutory identifier',
            'statutory calculate', 'statutory post', 'statutory reverse',
        ];

        return array_combine($names, array_map(fn (string $name) => [$name], $names));
    }

    #[DataProvider('writers')]
    #[Test]
    public function a_suspended_school_refuses_the_writer_and_a_resumed_one_runs_it(string $writer): void
    {
        $act = $this->prepare($writer);

        $this->suspend();
        $before = $this->state();
        $refused = null;
        try {
            $this->inSchool($this->school, $act);
        } catch (SchoolNotOperationalException $refused) {
        }
        $this->assertInstanceOf(SchoolNotOperationalException::class, $refused, "{$writer} ran on a suspended School");
        $this->assertSame($before, $this->state(), "{$writer} changed something on a suspended School");

        $this->resume();
        if ($writer === 'statutory calculate') {
            // A dormant service with no production caller, and statutory rules start in 2026 while this world is 2015.
            // Its control is weaker: on an active School the call gets past the guard to the domain's own rule lookup.
            $this->expectException(StatutoryRuleVersionNotFoundException::class);
        }
        $this->inSchool($this->school, $act);
        $this->assertNotSame($before, $this->state(), "{$writer}: the positive control changed nothing, so the fixture proves nothing");
    }

    /** Builds what the writer needs while the School is active, and returns the call. */
    private function prepare(string $writer): Closure
    {
        $periods = app(PayrollPeriodService::class);
        $runs = app(PayrollRunService::class);
        $structures = app(SalaryStructureService::class);
        $components = app(SalaryComponentService::class);
        $basic = fn (): string => (string) DB::table('salary_structure_components')->where('id', $this->setup['line'])->value('salary_component_id');

        return match ($writer) {
            'period create' => fn () => $periods->createPeriod($this->school, Carbon::parse('2015-10-01'), null, $this->preparer),
            'period open' => (function () use ($periods): Closure {
                $period = $this->inSchool($this->school, fn () => $periods->createPeriod($this->school, Carbon::parse('2015-10-01'), null, $this->preparer));

                return fn () => $periods->open($period->fresh(), $this->preparer);
            })(),
            'period close' => (function () use ($periods): Closure {
                $period = $this->openPeriod('2015-10-01');

                return fn () => $periods->close($period->fresh(), $this->preparer);
            })(),
            'run create' => (function () use ($runs): Closure {
                $period = $this->openPeriod('2015-10-01');

                return fn () => $runs->createRun($period->fresh(), $this->preparer);
            })(),
            'correction run create' => (function () use ($runs): Closure {
                $target = $this->postedTarget();
                $period = $this->openPeriod('2015-09-01');

                return fn () => $runs->createCorrectionRun($target->fresh(), $period->fresh(), $this->preparer);
            })(),
            'correction delta' => (function () use ($runs, $basic): Closure {
                $correction = $this->draftCorrection($this->postedTarget());
                $component = $this->inSchool($this->school, $basic);

                return fn () => $runs->recordCorrectionDelta($correction->fresh(), $this->record->fresh(), [new CorrectionDeltaInput($component, '100.00', 'increase')], 'underpaid', $this->preparer);
            })(),
            'manual override' => (function () use ($runs, $basic): Closure {
                $run = $this->inSchool($this->school, fn () => $runs->createRun($this->openPeriod('2015-10-01')->fresh(), $this->preparer));
                $component = $this->inSchool($this->school, $basic);

                return fn () => $runs->recordManualOverride($run->fresh(), $this->record->fresh(), [$component => '41000.00'], 'Joined mid-month', $this->preparer);
            })(),
            'run calculate' => (function () use ($runs): Closure {
                $run = $this->inSchool($this->school, fn () => $runs->createRun($this->openPeriod('2015-10-01')->fresh(), $this->preparer));

                return fn () => $runs->calculate($run->fresh(), $this->preparer);
            })(),
            'run approve' => (function () use ($runs): Closure {
                $run = $this->calculatedRun('2015-10-01');
                $proof = $this->freshMfaProofFor($this->checker);

                return fn () => $runs->approve($run->fresh(), $this->checker, $proof, $runs->latestCalculationId($run->fresh()), $runs->latestAttributionVersion($run->fresh()));
            })(),
            'component create' => fn () => $components->create($this->school, 'HRA-C15', 'House rent', 'earning', null, $this->x),
            'component deactivate' => (function () use ($components): Closure {
                $component = $this->inSchool($this->school, fn () => $components->create($this->school, 'HRA-C15', 'House rent', 'earning', null, $this->x));

                return fn () => $components->deactivate($component->fresh(), $this->x);
            })(),
            'structure draft' => fn () => $structures->createDraft($this->school, 'GRADE-C15', 'Grade C15', $this->x),
            'structure add component' => (function () use ($structures, $components): Closure {
                [$structure, $component] = $this->inSchool($this->school, fn () => [
                    $structures->createDraft($this->school, 'GRADE-C15', 'Grade C15', $this->x),
                    $components->create($this->school, 'HRA-C15', 'House rent', 'earning', null, $this->x),
                ]);

                return fn () => $structures->addComponent($structure->fresh(), new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $this->x);
            })(),
            'structure activate' => (function () use ($structures, $components): Closure {
                $structure = $this->inSchool($this->school, function () use ($structures, $components) {
                    $draft = $structures->createDraft($this->school, 'GRADE-C15', 'Grade C15', $this->x);
                    $component = $components->create($this->school, 'HRA-C15', 'House rent', 'earning', null, $this->x);
                    $structures->addComponent($draft, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $this->x);

                    return $draft;
                });

                return fn () => $structures->activate($structure->fresh(), $this->x);
            })(),
            'compensation assign' => (function (): Closure {
                $proof = $this->freshMfaProofFor($this->x);

                return fn () => app(CompensationService::class)->assign($this->school, $this->record->fresh(), $this->setup['structure'], Carbon::parse('2015-09-01'), [new FixedComponentValueInput($this->setup['line'], '52000.00')], $this->x, $proof);
            })(),
            'payroll accounting' => (function (): Closure {
                [$expense, $payable] = [$this->otherAccount('expense'), $this->otherAccount('liability')];

                return fn () => app(PayrollAccountingConfigurationService::class)->configure($this->school, $expense, $payable, $this->preparer);
            })(),
            'statutory accounting' => (function (): Closure {
                $accounts = $this->statutoryAccounts();

                return fn () => app(StatutoryAccountingConfigurationService::class)->configure($this->school, $accounts, $this->preparer);
            })(),
            'esi coverage' => fn () => app(EmployeeEsiCoverageAdminService::class)->correct($this->school, $this->record->id, '2015-10-01', '2016-03-31', '18000.00', true, $this->statutory),
            'pf status' => fn () => app(EmployeePfStatusAdminService::class)->configure($this->school, $this->record->id, [
                'hasExistingPfMembership' => true, 'hasUan' => true, 'hasApprovedHigherWageContribution' => false,
                'higherWageApprovalReference' => null, 'higherWageApprovalEffectiveFrom' => null,
                'isEpsEligible' => true, 'hasHigherPensionStatus' => false,
            ], $this->statutory),
            'tax profile' => fn () => app(EmployeeTaxProfileAdminService::class)->configure($this->school, $this->record->id, '2015-04-01', [
                'regime' => 'new', 'regimeSwitchPolicyReference' => null, 'previousEmployerIncome' => '0.00',
                'previousEmployerTds' => '0.00', 'declaredOtherIncome' => '0.00', 'declaredDeductions' => '0.00',
            ], $this->statutory),
            'statutory identifier' => fn () => app(StatutoryIdentifierAdminService::class)->set($this->school, $this->record->id, 'pan', 'ABCDE1234F', $this->statutory),
            'statutory calculate' => (function () use ($basic): Closure {
                $component = $this->inSchool($this->school, $basic);
                $this->inSchool($this->school, fn () => PayrollSalaryComponentStatutoryClassification::query()->create([
                    'school_id' => $this->school->id, 'salary_component_id' => $component, 'pf_classification' => 'core_wage',
                    'esi_wage_included' => true, 'income_tax_treatment' => 'taxable', 'effective_from' => '2015-01-01',
                ]));
                $this->inSchool($this->school, fn () => $this->prepare('pf status')());
                $this->inSchool($this->school, fn () => $this->prepare('tax profile')());
                $this->inSchool($this->school, fn () => $this->prepare('esi coverage')());
                $run = $this->postedRun($this->school, '2015-10-01', null, approve: false);

                return fn () => app(StatutoryPayrollCalculationService::class)->calculateForRun($run->fresh(), $this->preparer);
            })(),
            'statutory post' => (function (): Closure {
                $run = $this->postedRun($this->school, '2015-10-01', '2015-10-28', statutory: true, postStatutory: false);

                return fn () => app(StatutoryPayrollPostingService::class)->post($run->fresh(), $this->finance);
            })(),
            'statutory reverse' => (function (): Closure {
                $run = $this->postedRun($this->school, '2015-10-01', '2015-10-28', statutory: true);

                return fn () => app(StatutoryPayrollPostingService::class)->reverse($run->fresh(), $this->finance, 'posted in error');
            })(),
        };
    }

    private function otherAccount(string $type): string
    {
        return $this->inSchool($this->school, fn () => (string) LedgerAccount::factory()->for($this->school, 'school')->type($type)->create()->id);
    }

    /** @return array<string, string> */
    private function statutoryAccounts(): array
    {
        $current = (array) $this->inSchool($this->school, fn () => DB::table('payroll_statutory_accounting_configurations')->first());
        $this->assertNotEmpty($current);
        $ids = [];
        foreach (array_keys($current) as $column) {
            if (str_ends_with($column, '_ledger_account_id')) {
                $ids[$column] = $this->otherAccount(str_contains($column, 'expense') ? 'expense' : 'liability');
            }
        }

        return $ids;
    }

    /** Suspends, and proves it took: C14's first probe wrote a status nobody could see. */
    private function suspend(): void
    {
        $this->assertSame(1, DB::table('schools')->where('id', $this->school->id)->where('status', 'active')->update(['status' => 'suspended']));
        $this->assertFalse(app(SchoolOperationalGuard::class)->isOperational($this->school->id));
    }

    private function resume(): void
    {
        $this->assertSame(1, DB::table('schools')->where('id', $this->school->id)->where('status', 'suspended')->update(['status' => 'active']));
        $this->assertTrue(app(SchoolOperationalGuard::class)->isOperational($this->school->id));
    }

    /**
     * A digest of every row this School can see in each table a Payroll writer touches, the audit and
     * the outbox included, read under its own RLS context.
     *
     * @return array<string, string>
     */
    private function state(): array
    {
        $tables = DB::table('information_schema.tables')->where('table_schema', 'public')->where('table_type', 'BASE TABLE')
            ->where(fn ($q) => $q->where('table_name', 'like', 'payroll%')->orWhere('table_name', 'like', 'salary%')
                ->orWhere('table_name', 'like', 'employee_compensation%')->orWhere('table_name', 'like', 'compensation%')
                ->orWhere('table_name', 'like', 'employee_esi%')->orWhere('table_name', 'like', 'employee_pf%')
                ->orWhere('table_name', 'like', 'employee_tax%')->orWhere('table_name', 'like', 'employee_statutory%')
                ->orWhereIn('table_name', ['journal_entries', 'journal_lines', 'school_audit_events', 'domain_event_outbox']))
            ->orderBy('table_name')->pluck('table_name')->all();
        // Every table a migration creates under those names is digested: a misspelt or invisible one fails here.
        $expected = ['journal_entries', 'journal_lines', 'school_audit_events', 'domain_event_outbox'];
        foreach (glob(database_path('migrations/*.php')) as $migration) {
            preg_match_all("/Schema::create\\('((?:payroll|salary|employee_compensation|compensation|employee_esi|employee_pf|employee_tax|employee_statutory)[a-z_]*)'/", (string) file_get_contents($migration), $found);
            $expected = [...$expected, ...$found[1]];
        }
        $this->assertGreaterThan(30, count($expected));
        $this->assertSame([], array_values(array_diff(array_unique($expected), $tables)), 'tables the digest cannot see');

        return $this->inSchool($this->school, function () use ($tables): array {
            $digest = [];
            foreach ($tables as $table) {
                $digest[$table] = (string) DB::selectOne("select md5(coalesce(string_agg(t::text, '|' order by t::text), '')) d from \"{$table}\" t")->d;
            }

            return $digest;
        });
    }
}
