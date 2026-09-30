<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Http\Controllers\PaymentReceiptController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FEE.4: the receipt and statement API routes are documented and vice
 * versa, read-only (GET only -- no create/edit/delete/renumber/void), the
 * schemas expose no schoolId, money only as decimal strings, and the
 * receipt carries no tax field and never calls itself a tax invoice (J).
 */
class PaymentReceiptOpenApiCoverageTest extends TestCase
{
    private const PATH_PATTERN = '#^  (/schools/\{schoolId\}/(?:payments/\{paymentId\}/receipt|students/\{studentId\}/fee-statement)):$#';

    #[Test]
    public function every_live_route_is_documented_and_vice_versa_and_read_only(): void
    {
        $live = $this->liveOperations();
        $documented = $this->documentedOperations();

        $this->assertSame([], array_values(array_diff($live, $documented)), 'Live but undocumented: '.implode(', ', array_diff($live, $documented)));
        $this->assertSame([], array_values(array_diff($documented, $live)), 'Documented but not live: '.implode(', ', array_diff($documented, $live)));
        $this->assertSame(['GET /schools/{param}/payments/{param}/receipt', 'GET /schools/{param}/students/{param}/fee-statement'], $live, 'FEE.4 pin: two read-only operations.');
    }

    #[Test]
    public function the_receipt_schema_has_no_tax_field_and_is_never_a_tax_invoice(): void
    {
        $yaml = (string) file_get_contents($this->contractPath());

        foreach (['PaymentReceipt', 'PaymentReceiptLine', 'StudentFeeStatement', 'StudentFeeStatementLine'] as $schema) {
            $block = $this->schemaBlock($yaml, $schema);
            $this->assertStringNotContainsString('schoolId', $block, "{$schema} must not expose schoolId.");
            $this->assertStringNotContainsString('type: number', $block, "{$schema} has no numeric money.");
            $this->assertDoesNotMatchRegularExpression('/^\s+(gst\w*|hsn\w*|sac\w*|taxable\w*|cgst|sgst|igst|tax\w*):/mi', $block, "{$schema} has no tax field (J).");
        }
        $this->assertStringContainsString('title: { type: string, enum: [Payment receipt] }', $this->schemaBlock($yaml, 'PaymentReceipt'));
    }

    private function schemaBlock(string $yaml, string $name): string
    {
        $start = strpos($yaml, "\n    {$name}:\n");
        $this->assertNotFalse($start, "Schema {$name} missing.");
        $rest = substr($yaml, $start + 1);
        preg_match('/\n    [A-Za-z]+:\n/', $rest, $m, PREG_OFFSET_CAPTURE);

        return substr($rest, 0, $m[0][1] ?? strlen($rest));
    }

    private function contractPath(): string
    {
        $path = base_path('../../packages/contracts/openapi/school-os-api.yaml');
        $this->assertFileExists($path);

        return $path;
    }

    /** @return list<string> */
    private function liveOperations(): array
    {
        $operations = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('controller');
            if (! is_string($action) || ! str_starts_with($route->uri(), 'api/v1/') || explode('@', $action, 2)[0] !== PaymentReceiptController::class) {
                continue;
            }
            $path = preg_replace('/\{[^}]+\}/', '{param}', '/'.preg_replace('#^api/v1/#', '', $route->uri()));
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $operations[] = "{$method} {$path}";
                }
            }
        }
        sort($operations);

        return array_values(array_unique($operations));
    }

    /** @return list<string> */
    private function documentedOperations(): array
    {
        $operations = [];
        $current = null;
        foreach (file($this->contractPath(), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match(self::PATH_PATTERN, $line, $m)) {
                $current = preg_replace('/\{[^}]+\}/', '{param}', $m[1]);

                continue;
            }
            if ($current !== null && (preg_match('/^\S/', $line) || preg_match('#^  [/\#]#', $line))) {
                $current = null;

                continue;
            }
            if ($current !== null && preg_match('#^    (get|post|patch|put|delete):$#', $line, $m)) {
                $operations[] = strtoupper($m[1])." {$current}";
            }
        }
        sort($operations);

        return array_values(array_unique($operations));
    }
}
