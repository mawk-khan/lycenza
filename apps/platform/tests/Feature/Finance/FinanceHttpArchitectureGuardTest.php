<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0G.6 (rule 5/70): a static source guard proving
 * Finance/Fees/Payments HTTP controllers depend ONLY on the authorized
 * facades/read services, never the trusted cores or a raw Infrastructure
 * repository -- "HTTP -> authorized facade/read service -> trusted
 * core", never "HTTP -> trusted core". Deliberately grep-based (not
 * PHPStan-rule-based) -- simple, auditable, and does not require adding
 * a new static-analysis rule set merely for this one checkpoint's
 * boundary.
 */
class FinanceHttpArchitectureGuardTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function controllerFiles(): array
    {
        $base = base_path();

        return array_filter([
            $base.'/app/Domain/Finance/Http/Controllers/LedgerAccountController.php',
            $base.'/app/Domain/Finance/Http/Controllers/JournalEntryController.php',
            $base.'/app/Domain/Fees/Http/Controllers/ChargeController.php',
            $base.'/app/Domain/Payments/Http/Controllers/PaymentController.php',
        ], 'file_exists');
    }

    #[Test]
    public function every_finance_http_controller_exists(): void
    {
        $this->assertCount(4, $this->controllerFiles(), 'All four 0G.6 Finance/Fees/Payments controllers must exist.');
    }

    #[Test]
    public function no_finance_http_controller_references_a_trusted_core_service(): void
    {
        $forbidden = [
            'App\Domain\Finance\Application\LedgerService',
            'App\Domain\Fees\Application\ChargeService',
            'App\Domain\Payments\Application\PaymentProviderEventService',
        ];

        foreach ($this->controllerFiles() as $file) {
            $source = file_get_contents($file);

            foreach ($forbidden as $class) {
                $this->assertStringNotContainsString($class, $source, "{$file} must never reference the trusted core {$class} directly.");
            }
        }
    }

    #[Test]
    public function no_finance_http_controller_references_a_raw_infrastructure_model(): void
    {
        $forbidden = [
            'App\Domain\Finance\Infrastructure\LedgerAccount',
            'App\Domain\Finance\Infrastructure\JournalEntry',
            'App\Domain\Finance\Infrastructure\JournalLine',
            'App\Domain\Fees\Infrastructure\Charge',
            'App\Domain\Payments\Infrastructure\Payment',
            'App\Domain\Payments\Infrastructure\PaymentAllocation',
            'App\Domain\Payments\Infrastructure\PaymentProviderEvent',
        ];

        foreach ($this->controllerFiles() as $file) {
            $source = file_get_contents($file);

            foreach ($forbidden as $class) {
                $this->assertStringNotContainsString($class, $source, "{$file} must never reference the raw Infrastructure model {$class} directly.");
            }
        }
    }

    #[Test]
    public function no_finance_http_controller_exposes_internal_transaction_identity_fields(): void
    {
        foreach ($this->controllerFiles() as $file) {
            $source = file_get_contents($file);

            foreach (['posting_txid', 'postingTxid', 'creation_txid', 'creationTxid'] as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$file} must never reference internal transaction-identity metadata.");
            }
        }
    }

    #[Test]
    public function no_payment_mutation_route_exists_anywhere_in_the_api(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'payments'))
            ->filter(fn ($route) => in_array('POST', $route->methods(), true) || in_array('PATCH', $route->methods(), true) || in_array('DELETE', $route->methods(), true));

        $this->assertCount(0, $routes, 'No POST/PATCH/DELETE route may exist under any "payments" URI -- there is no finance.payments.manage capability.');
    }

    #[Test]
    public function no_public_provider_callback_route_exists(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'provider') || str_contains($route->uri(), 'webhook-events') || str_contains($route->uri(), 'callback'));

        $this->assertCount(0, $routes, 'No generic provider-callback/ingestion route may be publicly registered in 0G.6.');
    }
}
