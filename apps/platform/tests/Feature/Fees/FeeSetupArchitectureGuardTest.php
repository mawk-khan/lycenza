<?php

namespace Tests\Feature\Fees;

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

        foreach (['resources/js/Pages/App/Finance/FeeSetup/Index.vue', 'resources/js/Pages/App/Finance/FeeSetup/Structure.vue'] as $page) {
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
            foreach (['payment_allocations', 'PaymentAllocation', 'student_enrollments', 'StudentEnrollment', "'principal'", "'school_admin'"] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$file} must not reference {$forbidden}.");
            }
        }
    }

    #[Test]
    public function the_activation_event_is_internal_only(): void
    {
        $registry = app(WebhookEventRegistry::class);

        $this->assertFalse($registry->exists('fee_structure.activated.v1'));
        $this->assertFalse($registry->isSubscribable('fee_structure.activated.v1'));
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
}
