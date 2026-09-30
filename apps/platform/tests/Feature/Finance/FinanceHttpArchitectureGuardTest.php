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
            // Phase 0O.11A: the browser Payments controllers, including the
            // one human payment write (manual/offline recording).
            $base.'/app/Http/Controllers/App/Finance/PaymentController.php',
            $base.'/app/Http/Controllers/App/Finance/ManualPaymentController.php',
        ], 'file_exists');
    }

    #[Test]
    public function every_finance_http_controller_exists(): void
    {
        $this->assertCount(6, $this->controllerFiles(), 'The four 0G.6 Finance/Fees/Payments controllers and the two 0O.11A browser Payments controllers must exist.');
    }

    #[Test]
    public function no_finance_http_controller_references_a_trusted_core_service(): void
    {
        $forbidden = [
            'App\Domain\Finance\Application\LedgerService',
            'App\Domain\Fees\Application\ChargeService',
            'App\Domain\Payments\Application\PaymentProviderEventService',
            'App\Domain\Payments\Application\SettledPaymentRecorder',
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
    public function finance_never_depends_on_fees(): void
    {
        // FEE closure remediation (DOMAIN-MAP: Finance has no dependency on
        // Fees): every Finance-owned file -- the module itself and its two
        // browser controllers -- is free of any Fees reference in code
        // (comments may still name a Fees class for context). Shared HTTP
        // glue lives in App\Support\Http or Finance's own Http namespace.
        $base = base_path();
        $files = [$base.'/app/Http/Controllers/App/Finance/LedgerAccountController.php', $base.'/app/Http/Controllers/App/Finance/JournalEntryController.php'];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base.'/app/Domain/Finance', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $this->assertGreaterThan(20, count($files));

        foreach ($files as $file) {
            $code = '';
            foreach (\PhpToken::tokenize((string) file_get_contents($file)) as $token) {
                if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
                    $code .= $token->text;
                }
            }

            $this->assertStringNotContainsString('Domain\Fees', $code, "{$file} must not depend on the Fees module.");
            $this->assertStringNotContainsString('TranslatesFeeSetupErrors', $code, "{$file} must not use the Fees-owned HTTP glue.");
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
    public function the_only_payment_mutation_route_is_the_browser_offline_recording_route(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'payments'))
            ->filter(fn ($route) => array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) !== [])
            ->values();

        // Phase 0O.11A (ADR 0031 implementation amendment section 9): exactly
        // one Payment write exists -- recording an offline payment from a
        // School browser session. No /api/v1 write, no edit/delete/refund,
        // no provider callback; finance.payments.manage still does not exist.
        $this->assertCount(1, $routes, 'Exactly one Payment mutation route may exist.');
        $route = $routes->first();
        $this->assertSame('app.finance.payments.record.store', $route->getName());
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware(), 'The recording route must run in the web group (session + CSRF).');
        $this->assertContains('throttle:finance-payment-recording', $route->gatherMiddleware());
        $this->assertStringStartsNotWith('api/', $route->uri());
    }

    #[Test]
    public function no_public_provider_callback_route_exists(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'provider') || str_contains($route->uri(), 'webhook-events') || str_contains($route->uri(), 'callback'))
            // Phase 0O.9A: the one ADR 0055 email provider-event endpoint -- not a
            // payment callback; authenticated by its provider adapter, 404 without one.
            ->reject(fn ($route) => $route->getName() === 'api.integrations.email-provider.events');

        $this->assertCount(0, $routes, 'No generic provider-callback/ingestion route may be publicly registered in 0G.6.');
    }
}
