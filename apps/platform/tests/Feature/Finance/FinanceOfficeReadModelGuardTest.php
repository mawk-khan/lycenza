<?php

namespace Tests\Feature\Finance;

use App\Support\Authorization\Campus\CampusScopeCatalogue;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ORG.9B (ADR 0076 §68): the Finance office read models are reads.
 *
 * Their surfaces sit on the existing read authority:
 * - Outstanding fees: `finance.charges.view,campus` ∩ `finance.payments.view`;
 * - the account ledger: `finance.ledger.view`;
 * - the audit log: `school.audit.view`.
 *
 * They add no write route, no export, no fee-head column, no new capability
 * key and no Campus adoption. Their read services call no write path other
 * than the read audit.
 */
class FinanceOfficeReadModelGuardTest extends TestCase
{
    /** The three read services and the one new read model; each may write only through AuditRecorder. */
    private const READ_SOURCES = [
        'app/Domain/Payments/Application/Collections/OutstandingFeesReadService.php',
        'app/Domain/Finance/Application/LedgerAccountStatementService.php',
        'app/Domain/Compliance/Application/AuditLogReviewService.php',
        'app/Support/Audit/SchoolAuditEventReader.php',
    ];

    private function route(string $name): Route
    {
        $route = Router::getRoutes()->getByName($name);
        $this->assertNotNull($route, $name);

        return $route;
    }

    #[Test]
    public function the_read_surfaces_are_get_only_on_their_existing_authority(): void
    {
        $outstanding = $this->route('app.finance.outstanding.index');
        $this->assertSame(['GET', 'HEAD'], $outstanding->methods());
        $this->assertContains('capability:finance.charges.view,campus', $outstanding->gatherMiddleware());

        $ledger = $this->route('app.finance.ledger-accounts.ledger');
        $this->assertSame(['GET', 'HEAD'], $ledger->methods());
        $this->assertStringContainsString("authorizeCapability('finance.ledger.view'", (string) file_get_contents(app_path('Http/Controllers/App/Finance/LedgerAccountController.php')));
        $this->assertStringContainsString("'finance.ledger.view'", (string) file_get_contents(app_path('Domain/Finance/Application/LedgerAccountStatementService.php')));

        $audit = $this->route('app.compliance.audit-log');
        $this->assertSame(['GET', 'HEAD'], $audit->methods());
        $this->assertStringContainsString("VIEW_CAPABILITY = 'school.audit.view'", (string) file_get_contents(app_path('Domain/Compliance/Application/AuditLogReviewService.php')));
    }

    #[Test]
    public function the_read_services_never_write_anything_but_the_read_audit(): void
    {
        foreach (self::READ_SOURCES as $path) {
            $source = (string) file_get_contents(base_path($path));
            foreach (['->save(', '->insert(', '->update(', '->delete(', '->forceFill(', '::create(', 'DB::statement', 'DB::insert', 'DB::update', 'DB::delete', 'LedgerService', 'lockForUpdate', 'PaymentCorrectionService', 'ChargeService::cancel', '->cancel('] as $write) {
                $this->assertStringNotContainsString($write, $source, "{$path} must stay read-only ({$write}).");
            }
        }
    }

    #[Test]
    public function no_export_route_and_no_fee_head_column_exist_for_these_surfaces(): void
    {
        foreach (Router::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            if (str_starts_with($name, 'app.finance.') || str_starts_with($name, 'app.compliance.')) {
                $this->assertDoesNotMatchRegularExpression('/export|download|csv|xlsx|pdf/i', $name.' '.$route->uri(), "{$name}: exports stay owner decision D-11.");
            }
        }
        $this->assertFalse(Schema::hasColumn('charges', 'fee_head_id'), 'Fee-head attribution on Charges is owner decision OD-9.1, not ORG.9B.');
    }

    #[Test]
    public function no_read_key_of_these_surfaces_was_campus_adopted_beyond_its_existing_contract(): void
    {
        $this->assertFalse(CampusScopeCatalogue::isAdopted('finance.ledger.view'), 'The ledger stays whole-School.');
        $this->assertTrue(CampusScopeCatalogue::isCampusIncompatible('finance.ledger.view'));
        $this->assertFalse(CampusScopeCatalogue::isAdopted('school.audit.view'), 'The audit log stays whole-School.');
        // The two existing ORG.7G-1 adoptions are unchanged; no other finance read key joined them.
        $finance = array_values(array_filter(CampusScopeCatalogue::capabilities(), fn (string $key) => str_starts_with($key, 'finance.')));
        sort($finance);
        $this->assertSame(['finance.charges.view', 'finance.payments.record', 'finance.payments.view'], $finance);
    }
}
