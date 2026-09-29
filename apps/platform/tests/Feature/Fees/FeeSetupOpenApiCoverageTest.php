<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Http\Controllers\FeeHeadController;
use App\Domain\Fees\Http\Controllers\FeeOptionalSelectionController;
use App\Domain\Fees\Http\Controllers\FeeStructureController;
use App\Domain\Finance\Http\Controllers\LedgerAccountController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FEE.1: every live fee setup / ledger-account API route is documented in
 * packages/contracts/openapi/school-os-api.yaml and vice versa, the only
 * DELETE is the draft-line removal, and the public schemas expose no
 * schoolId and money only as decimal strings.
 */
class FeeSetupOpenApiCoverageTest extends TestCase
{
    private const CONTROLLERS = [
        FeeHeadController::class, FeeStructureController::class,
        FeeOptionalSelectionController::class, LedgerAccountController::class,
    ];

    private const PATH_PATTERN = '#^  (/schools/\{schoolId\}/(?:fee-heads|fee-structures|fee-optional-selections|ledger-accounts)\S*):$#';

    #[Test]
    public function every_live_route_is_documented_and_vice_versa(): void
    {
        $live = $this->liveOperations();
        $documented = $this->documentedOperations();

        $this->assertSame([], array_values(array_diff($live, $documented)), 'Live but undocumented: '.implode(', ', array_diff($live, $documented)));
        $this->assertSame([], array_values(array_diff($documented, $live)), 'Documented but not live: '.implode(', ', array_diff($documented, $live)));
        $this->assertCount(22, $live, 'FEE.1 pin: 21 new operations plus the existing ledger-account list. Update deliberately.');
    }

    #[Test]
    public function the_only_documented_delete_removes_a_draft_line(): void
    {
        $deletes = array_values(array_filter($this->documentedOperations(), fn ($o) => str_starts_with($o, 'DELETE ')));

        $this->assertSame(['DELETE /schools/{param}/fee-structures/{param}/lines/{param}'], $deletes);
    }

    #[Test]
    public function public_schemas_expose_no_school_id_and_money_as_strings(): void
    {
        $yaml = (string) file_get_contents($this->contractPath());

        foreach (['FeeHead', 'FeeStructure', 'FeeStructureLine', 'FeeStructureInstallment', 'FeeOptionalSelection'] as $schema) {
            $block = $this->schemaBlock($yaml, $schema);
            $this->assertStringNotContainsString('schoolId', $block, "{$schema} must not expose schoolId.");
            if (str_contains($block, 'amount:')) {
                $this->assertMatchesRegularExpression('/amount: \{ type: string, pattern:/', $block, "{$schema}.amount is a decimal string.");
            }
        }
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
            if (! is_string($action) || ! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            [$controller] = explode('@', $action, 2) + [null];
            if (! in_array($controller, self::CONTROLLERS, true)) {
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
