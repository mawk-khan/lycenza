<?php

namespace Tests\Feature\Retention;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E21.3E: the communications and platform residual expiries stay narrow,
 * and the categories adopted as tenant lifetime stay unexpired.
 */
class ResidualRetentionArchitectureGuardTest extends TestCase
{
    private const FILES = [
        'Domain/Communications/Application/Retention/CommunicationResidualRetentionService.php',
        'Domain/Transport/Application/Retention/DriverAssignmentRetentionService.php',
        'Domain/Visitor/Application/Retention/VisitorRetentionService.php',
        'Support/Retention/AutomationExecutionRetention.php',
        'Console/Commands/PruneOperationalRecords.php',
    ];

    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $files = [];
        exec('find '.escapeshellarg(app_path()).' -name "*.php"', $files);
        sort($files);

        return $files;
    }

    /** @return list<string> app-relative files whose code contains $needle */
    private function filesContaining(string $needle): array
    {
        $hits = [];
        foreach ($this->phpFiles() as $file) {
            if (str_contains($this->code($file), $needle)) {
                $hits[] = substr($file, strlen(app_path()) + 1);
            }
        }

        return $hits;
    }

    #[Test]
    public function the_clocks_are_canonical_end_times_never_updated_or_created_at(): void
    {
        foreach (self::FILES as $file) {
            $code = $this->code(app_path($file));
            $this->assertStringNotContainsString('updated_at', $code, "{$file}: never updated_at");
            $this->assertStringNotContainsString('last_used_at', $code, "{$file}: never last use");
        }
        $residual = $this->code(app_path('Domain/Communications/Application/Retention/CommunicationResidualRetentionService.php'));
        $this->assertStringNotContainsString('created_at', $residual);
        $this->assertStringContainsString("whereNull('a.published_at')->whereNull('a.message_id')", $residual, 'a sent announcement is never a residual (D3 owns it)');
    }

    #[Test]
    public function the_residual_expiries_are_a_closed_list_reached_only_by_their_commands(): void
    {
        foreach ([
            'CommunicationResidualRetentionService' => ['Console/Commands/PruneCommunications.php'],
            'DriverAssignmentRetentionService' => ['Console/Commands/PruneOperationalRecords.php'],
            'VisitorRetentionService' => ['Console/Commands/PruneOperationalRecords.php'],
            'AutomationExecutionRetention;' => ['Console/Commands/PruneOperationalRecords.php'],
        ] as $service => $allowed) {
            $callers = array_values(array_filter($this->filesContaining($service), fn ($f) => ! str_ends_with($f, '/'.rtrim($service, ';').'.php')));
            $this->assertSame($allowed, $callers, "{$service} is reached only by its command");
        }
        $this->assertStringNotContainsString('--table', (string) file_get_contents(app_path('Console/Commands/PruneOperationalRecords.php')));
    }

    #[Test]
    public function memberships_their_preferences_and_inventory_have_no_age_based_expiry(): void
    {
        // No retention command names them, and nothing in the application deletes them.
        foreach (glob(app_path('Console/Commands/Prune*.php')) ?: [] as $file) {
            $code = $this->code($file);
            foreach (['school_memberships', 'SchoolMembership', 'communication_preferences', 'CommunicationPreference', 'inventory_', 'stock_movements', 'InventoryItem', 'StockMovement'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$file}: memberships, membership preferences and Inventory are tenant lifetime");
            }
        }
        foreach ($this->phpFiles() as $file) {
            $code = $this->code($file);
            $this->assertDoesNotMatchRegularExpression("/table\\('school_memberships'\\)[^;]*->delete\\(|SchoolMembership::[^;]*->delete\\(/s", $code, "{$file}: a membership is suspended, never deleted (rule 92)");
            $this->assertDoesNotMatchRegularExpression("/table\\('(inventory_[a-z_]+|stock_movements)'\\)[^;]*->delete\\(/s", $code, "{$file}: Inventory is tenant lifetime");
        }
    }

    #[Test]
    public function notifications_still_have_no_production_producer(): void
    {
        // E21.2G C7: the only notifications writer is the Phase 0C demo consumer of
        // school.setting.changed.v1, emitted only by SchoolSettingsService, which no
        // production code calls. A caller appearing here needs a D13 decision first.
        $callers = array_values(array_filter($this->filesContaining('SchoolSettingsService'), fn ($f) => ! str_starts_with($f, 'Support/Settings/') && $f !== 'Support/Retention/TenantRetentionCatalog.php'));
        $this->assertSame([], $callers, 'school.setting.changed.v1 gained a production emitter: classify notifications retention (D13) before shipping it');
    }

    #[Test]
    public function canteen_finance_evidence_has_only_the_finance_expiry(): void
    {
        foreach (glob(app_path('Console/Commands/Prune*.php')) ?: [] as $file) {
            if (str_ends_with($file, 'PruneFinanceRecords.php')) {
                continue;
            }
            $this->assertStringNotContainsString('canteen_', $this->code($file), "{$file}: Canteen orders follow Finance (D8)");
        }
    }

    #[Test]
    public function the_ended_lifecycles_are_one_way(): void
    {
        // No reactivation exists, so there is no expiry-vs-reopen race to guard.
        $this->assertStringContainsString("->where('status', 'active')", $this->code(app_path('Domain/Transport/Application/TransportRouteAssignmentService.php')), 'a driver assignment ends only from active');
        $this->assertStringContainsString("->where('status', 'checked_in')", $this->code(app_path('Domain/Visitor/Application/VisitorVisitService.php')), 'a checkout happens only from checked_in');
        $this->assertStringContainsString('STATUS_PENDING, AutomationExecution::STATUS_RUNNING', $this->code(app_path('Domain/Automation/Application/AutomationExecutionService.php')), 'only pending/running executions are ever claimed');
    }
}
