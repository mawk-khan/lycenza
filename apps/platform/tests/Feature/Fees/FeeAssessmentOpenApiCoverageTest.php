<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Http\Controllers\FeeAssessmentRunController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FEE.2: every live assessment-run / assessment-void API route is
 * documented in packages/contracts/openapi/school-os-api.yaml and vice
 * versa, there is no DELETE, and the public schemas expose no schoolId and
 * money only as decimal strings.
 */
class FeeAssessmentOpenApiCoverageTest extends TestCase
{
    private const CONTROLLERS = [FeeAssessmentRunController::class];

    private const PATH_PATTERN = '#^  (/schools/\{schoolId\}/(?:fee-assessment-runs|fee-assessments)\S*):$#';

    #[Test]
    public function every_live_route_is_documented_and_vice_versa(): void
    {
        $live = $this->liveOperations();
        $documented = $this->documentedOperations();

        $this->assertSame([], array_values(array_diff($live, $documented)), 'Live but undocumented: '.implode(', ', array_diff($live, $documented)));
        $this->assertSame([], array_values(array_diff($documented, $live)), 'Documented but not live: '.implode(', ', array_diff($documented, $live)));
        $this->assertCount(10, $live, 'FEE.2 pin: 10 operations. Update deliberately.');
    }

    #[Test]
    public function no_delete_is_documented(): void
    {
        $this->assertSame([], array_values(array_filter($this->documentedOperations(), fn ($o) => str_starts_with($o, 'DELETE '))));
    }

    #[Test]
    public function public_schemas_expose_no_school_id_and_money_as_strings(): void
    {
        $yaml = (string) file_get_contents($this->contractPath());

        foreach (['FeeAssessmentRun', 'FeeAssessmentRunItem', 'FeeAssessment'] as $schema) {
            $block = $this->schemaBlock($yaml, $schema);
            $this->assertStringNotContainsString('schoolId', $block, "{$schema} must not expose schoolId.");
            if (str_contains($block, 'amount:')) {
                $this->assertMatchesRegularExpression('/amount: \{ type: string, pattern:/', $block, "{$schema}.amount is a decimal string.");
                $this->assertStringNotContainsString('type: number', $block, "{$schema} has no numeric money.");
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
