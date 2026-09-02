<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Http\Controllers\ExaminationPaperController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0H.4B (OpenAPI contract, mandatory in this implementation branch
 * -- CLAUDE.md rule 9/ADR 0009). Bidirectional proof that
 * `packages/contracts/openapi/school-os-api.yaml`'s ExaminationPaper
 * paths exactly match the live `/api/v1` routes registered for
 * ExaminationPaperController. Mirrors
 * Tests\Feature\Examinations\ExaminationOpenApiCoverageTest's corrected
 * path-block boundary logic exactly.
 */
class ExaminationPaperOpenApiCoverageTest extends TestCase
{
    #[Test]
    public function every_live_examination_paper_route_is_documented_and_vice_versa(): void
    {
        $live = $this->liveOperations();
        $documented = $this->documentedOperations();

        $liveButUndocumented = array_values(array_diff($live, $documented));
        $documentedButNonexistent = array_values(array_diff($documented, $live));

        $this->assertSame([], $liveButUndocumented,
            'Live ExaminationPaper route(s) missing from the OpenAPI contract: '.implode(', ', $liveButUndocumented));
        $this->assertSame([], $documentedButNonexistent,
            'OpenAPI ExaminationPaper path(s)/operation(s) with no matching live route: '.implode(', ', $documentedButNonexistent));

        $this->assertCount(4, $live, 'Expected exactly 4 live ExaminationPaper routes -- update this pin (and the contract) deliberately.');
        $this->assertCount(4, $documented);
    }

    #[Test]
    public function the_contract_documents_no_delete_or_lifecycle_operation(): void
    {
        foreach ($this->documentedOperations() as $operation) {
            $this->assertStringNotContainsStringIgnoringCase('DELETE ', $operation);
            $this->assertStringNotContainsStringIgnoringCase('activate', $operation);
            $this->assertStringNotContainsStringIgnoringCase('deactivate', $operation);
            $this->assertStringNotContainsStringIgnoringCase('archive', $operation);
        }
    }

    #[Test]
    public function the_examination_paper_schema_exposes_exactly_the_intended_properties(): void
    {
        $yaml = file_get_contents($this->contractPath());
        $resource = $this->schemaBlock($yaml, '    ExaminationPaper:', '    ExaminationPaperCreateInput:');

        $properties = [];
        foreach (explode("\n", $resource) as $line) {
            if (preg_match('/^        (\w+):/', $line, $m)) {
                $properties[] = $m[1];
            }
        }

        $this->assertSame(
            ['id', 'examinationId', 'subjectOfferingId', 'scheduledOn', 'startsAt', 'endsAt', 'maxMarks', 'status'],
            $properties,
            'ExaminationPaper exposes exactly eight non-personal, non-integrity-pin fields.',
        );
    }

    #[Test]
    public function the_documented_status_enum_is_the_closed_two_value_vocabulary(): void
    {
        $yaml = file_get_contents($this->contractPath());
        $enum = $this->schemaBlock($yaml, '    ExaminationPaperStatus:', '    ExaminationPaper:');

        $this->assertSame(1, preg_match('/^      enum: \[(.+)\]$/m', $enum, $m),
            'ExaminationPaperStatus must declare a single-line enum.');
        $this->assertSame('active, inactive', $m[1]);
    }

    private function schemaBlock(string $yaml, string $from, string $to): string
    {
        $start = strpos($yaml, $from);
        $end = strpos($yaml, $to);
        $this->assertNotFalse($start, "Schema block '{$from}' not found in the contract.");
        $this->assertNotFalse($end, "Schema block terminator '{$to}' not found in the contract.");

        return substr($yaml, $start, $end - $start);
    }

    private function contractPath(): string
    {
        $path = base_path('../../packages/contracts/openapi/school-os-api.yaml');
        $this->assertFileExists($path, 'OpenAPI contract file not found at expected path.');

        return $path;
    }

    /**
     * @return list<string>
     */
    private function liveOperations(): array
    {
        $operations = [];

        collect(Route::getRoutes())->each(function ($route) use (&$operations): void {
            $action = $route->getAction('controller');
            if (! is_string($action)) {
                return;
            }

            [$controller] = explode('@', $action, 2) + [null];
            if ($controller !== ExaminationPaperController::class) {
                return;
            }

            $path = '/'.preg_replace('#^api/v1/#', '', $route->uri());
            $path = preg_replace('/\{[^}]+\}/', '{param}', $path);

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $operations[] = "{$method} {$path}";
            }
        });

        sort($operations);

        return array_values(array_unique($operations));
    }

    /**
     * @return list<string>
     */
    private function documentedOperations(): array
    {
        $lines = file($this->contractPath(), FILE_IGNORE_NEW_LINES);
        $operations = [];
        $currentPath = null;

        foreach ($lines as $line) {
            if (preg_match('#^  (/schools/\{schoolId\}/\S*examination-papers\S*):$#', $line, $m)) {
                $currentPath = preg_replace('/\{[^}]+\}/', '{param}', $m[1]);

                continue;
            }

            if ($currentPath !== null && preg_match('/^\S/', $line)) {
                $currentPath = null;

                continue;
            }

            if ($currentPath !== null && preg_match('#^  /#', $line)) {
                $currentPath = null;

                continue;
            }

            if ($currentPath !== null && preg_match('#^    (get|post|patch|put|delete):$#', $line, $m)) {
                $operations[] = strtoupper($m[1])." {$currentPath}";
            }
        }

        sort($operations);

        return array_values(array_unique($operations));
    }
}
