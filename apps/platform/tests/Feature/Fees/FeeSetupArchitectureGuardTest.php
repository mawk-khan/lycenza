<?php

namespace Tests\Feature\Fees;

use App\Jobs\ExecuteFeeAssessmentRunJob;
use App\Support\Webhooks\WebhookEventRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FEE.1 structural guards (ADR 0062 §3, §19-§22):
 * - money never passes through a float in the FEE.1 code;
 * - Fees never reads Payments' allocations or Students' enrollment tables
 *   directly (rule 4) and never branches on a role name (rule 24);
 * - `fee_structure.activated.v1` stays internal (not webhook-registered);
 * - every FEE.1 table is RLS-enabled in its migration and none has a
 *   Section column (owner decision B).
 */
class FeeSetupArchitectureGuardTest extends TestCase
{
    private const FEE_1_FILES = [
        'app/Domain/Fees/Application/FeeHeadService.php',
        'app/Domain/Fees/Application/FeeStructureService.php',
        'app/Domain/Fees/Application/FeeOptionalSelectionService.php',
        'app/Domain/Fees/Application/FeeStructureReadService.php',
        'app/Domain/Fees/Domain/FeeAmount.php',
        'app/Domain/Fees/Domain/FeeCode.php',
        'app/Domain/Fees/Http/FeeSetupPresenter.php',
        'app/Domain/Fees/Http/Controllers/FeeHeadController.php',
        'app/Domain/Fees/Http/Controllers/FeeStructureController.php',
        'app/Domain/Fees/Http/Controllers/FeeOptionalSelectionController.php',
        'app/Domain/Finance/Application/LedgerAccountAdministrationService.php',
        'app/Http/Controllers/App/Finance/FeeSetupController.php',
        // FEE.2
        'app/Domain/Fees/Application/FeeAssessmentRunService.php',
        'app/Domain/Fees/Application/FeeAssessmentItemExecutor.php',
        'app/Domain/Fees/Application/FeeAssessmentRunReadService.php',
        'app/Domain/Fees/Application/FeeAssessmentService.php',
        'app/Domain/Fees/Application/FeeStructureResolver.php',
        'app/Domain/Fees/Http/Controllers/FeeAssessmentRunController.php',
        'app/Http/Controllers/App/Finance/FeeAssessmentRunController.php',
        'app/Jobs/ExecuteFeeAssessmentRunJob.php',
        // FEE.3
        'app/Domain/Fees/Application/FeeConcessionService.php',
        'app/Domain/Fees/Application/FeeAdjustmentService.php',
        'app/Domain/Fees/Application/FeeConcessionReadService.php',
        'app/Domain/Fees/Application/FeeSettingsService.php',
        'app/Domain/Fees/Application/RequestFeeConcessionData.php',
        'app/Domain/Fees/Http/Controllers/FeeConcessionController.php',
        'app/Http/Controllers/App/Finance/FeeConcessionController.php',
    ];

    private const MIGRATIONS = [
        'database/migrations/2026_10_29_090100_create_fee_heads_and_fee_settings_tables.php',
        'database/migrations/2026_10_29_090200_create_fee_structures_tables.php',
        'database/migrations/2026_10_29_090300_create_fee_optional_selections_table.php',
    ];

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    #[Test]
    public function no_fee_1_file_uses_a_float(): void
    {
        foreach (self::FEE_1_FILES as $file) {
            $code = $this->source($file);
            foreach (['(float)', 'floatval(', ': float', 'float $', 'round(', 'number_format('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$file} must not use {$forbidden} for money.");
            }
        }

        foreach (['resources/js/Pages/App/Finance/FeeSetup/Index.vue', 'resources/js/Pages/App/Finance/FeeSetup/Structure.vue',
            'resources/js/Pages/App/Finance/FeeSetup/Runs.vue', 'resources/js/Pages/App/Finance/FeeSetup/Run.vue',
            'resources/js/Pages/App/Finance/Concessions/Index.vue', 'resources/js/Pages/App/Finance/Concessions/Create.vue',
            'resources/js/Pages/App/Finance/Concessions/Show.vue'] as $page) {
            $code = $this->source($page);
            foreach (['parseFloat', 'Number(', 'toFixed('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$page} must not use {$forbidden} for money.");
            }
        }
    }

    #[Test]
    public function fees_reads_no_other_modules_tables_and_branches_on_no_role(): void
    {
        foreach (self::FEE_1_FILES as $file) {
            $code = $this->source($file);
            // Enrollment facts come only through Students' published read
            // service; never its Eloquent model or table.
            foreach (['payment_allocations', 'PaymentAllocation', "'student_enrollments'", 'Infrastructure\\StudentEnrollment', "'principal'", "'school_admin'"] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$file} must not reference {$forbidden}.");
            }
        }
    }

    #[Test]
    public function the_fee_events_are_internal_only(): void
    {
        $registry = app(WebhookEventRegistry::class);

        foreach (['fee_structure.activated.v1', 'fee_assessment_run.completed.v1', 'fee_concession.approved.v1', 'fee_concession.rejected.v1',
            'fee_adjustment.posted.v1', 'fee_adjustment.cancelled.v1'] as $type) {
            $this->assertFalse($registry->exists($type), $type);
            $this->assertFalse($registry->isSubscribable($type), $type);
        }
    }

    #[Test]
    public function fees_reaches_enrollments_only_through_the_students_read_service_and_the_job_is_bounded(): void
    {
        $executor = $this->source('app/Domain/Fees/Application/FeeAssessmentItemExecutor.php');
        $this->assertStringContainsString('StudentEnrollmentFeeTargetReadService', $executor);
        $this->assertStringContainsString('ChargeService', $executor, 'Charges come only from the trusted ChargeService core.');
        $this->assertStringNotContainsString('ChargeAdministrationService', $executor, 'The job never goes through a human facade.');
        $this->assertStringNotContainsString('LedgerService->post', $executor, 'No alternative posting path.');

        $job = new ExecuteFeeAssessmentRunJob('x');
        $this->assertSame(1, $job->tries);
        $this->assertLessThan(90, $job->timeout);
    }

    #[Test]
    public function every_fee_1_table_is_rls_enabled_and_has_no_section_scope(): void
    {
        $all = implode("\n", array_map(fn ($m) => $this->source($m), self::MIGRATIONS));

        foreach (['fee_heads', 'fee_settings', 'fee_structures', 'fee_structure_lines', 'fee_structure_installments', 'fee_optional_selections'] as $table) {
            $this->assertStringContainsString("TenantRls::enable('{$table}')", $all, "{$table} must be RLS-enabled.");
        }

        $this->assertStringNotContainsString('section_id', $all, 'Fee structures are never Section-specific (owner decision B).');

        foreach (['fee_heads', 'fee_settings', 'fee_structures', 'fee_optional_selections'] as $table) {
            $this->assertStringContainsString("TenantRls::revokeDelete('{$table}')", $all, "{$table} must never be hard-deleted.");
        }
    }

    #[Test]
    public function fee_3_tables_are_rls_enabled_never_deleted_and_the_capacity_guard_is_payments_owned(): void
    {
        $concessions = $this->source('database/migrations/2026_10_31_090000_create_fee_concessions_table.php');
        $adjustments = $this->source('database/migrations/2026_10_31_090100_create_fee_adjustments_table.php');
        $capacity = $this->source('database/migrations/2026_10_31_090200_amend_payment_charge_capacity_for_fee_adjustments.php');

        foreach (['fee_concessions' => $concessions, 'fee_adjustments' => $adjustments] as $table => $migration) {
            $this->assertStringContainsString("TenantRls::enable('{$table}')", $migration);
            $this->assertStringContainsString("TenantRls::revokeDelete('{$table}')", $migration);
            $this->assertStringNotContainsString('payment_allocations', $migration, 'Fees-owned migrations never read payment_allocations.');
        }
        $this->assertStringNotContainsString("'note'", $concessions, 'No free-text note column (owner decision M).');
        $this->assertStringContainsString('fee_concessions_sod_check', $concessions);

        // ADR 0062 §15: the Payments-owned amendment reads allocations and
        // adjustments, and its down() restores the 0G.5 body verbatim.
        $this->assertStringContainsString('payments_lock_and_validate_charge_adjustment', $capacity);
        $this->assertSame(2, substr_count($capacity, 'CREATE OR REPLACE FUNCTION payments_lock_and_validate_charge_allocation()'));
        $original = $this->source('database/migrations/2026_09_09_090200_create_payment_allocations_table.php');
        preg_match('/(DECLARE\s+v_charge_amount numeric;\s+v_cancelled_at timestamp;\s+v_allocated_total numeric;\s+BEGIN.*?END;)/s', $original, $m);
        $this->assertNotEmpty($m, 'The 0G.5 function body was found.');
        $this->assertStringContainsString($m[1], $capacity, 'down() restores the 0G.5 allocation function body verbatim.');
    }
}
