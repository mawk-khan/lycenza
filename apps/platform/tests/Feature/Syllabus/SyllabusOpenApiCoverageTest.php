<?php

namespace Tests\Feature\Syllabus;

use App\Domain\Syllabus\Http\Controllers\SyllabusUnitController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0H.3A (OpenAPI contract, MANDATORY in this first
 * implementation branch -- not a later correction pass like Timetable
 * needed). Bidirectional proof that
 * `packages/contracts/openapi/school-os-api.yaml`'s Syllabus paths
 * exactly match the live `/api/v1` routes registered for
 * SyllabusUnitController: zero live-but-undocumented operations, zero
 * documented-but-nonexistent ones.
 *
 * Uses the CORRECTED path-block boundary logic established during
 * Phase 0H.2: a block ends at a column-0 line (e.g. `components:`) OR
 * at any other 2-space-indented path key. The original Timetable
 * parser only had the first check, which silently attributed a later
 * module's operations to the previous module's last path once anything
 * was appended after it.
 */
class SyllabusOpenApiCoverageTest extends TestCase
{
    #[Test]
    public function every_live_syllabus_route_is_documented_and_vice_versa(): void
    {
        $live = $this->liveSyllabusOperations();
        $documented = $this->documentedSyllabusOperations();

        $liveButUndocumented = array_values(array_diff($live, $documented));
        $documentedButNonexistent = array_values(array_diff($documented, $live));

        $this->assertSame([], $liveButUndocumented,
            'Live Syllabus route(s) missing from the OpenAPI contract: '.implode(', ', $liveButUndocumented));
        $this->assertSame([], $documentedButNonexistent,
            'OpenAPI Syllabus path(s)/operation(s) with no matching live route: '.implode(', ', $documentedButNonexistent));

        // Pinned so a silent drift (a route AND its doc entry quietly
        // removed together, which the diffs above cannot catch) fails
        // loudly too.
        $this->assertCount(4, $live, 'Expected exactly 4 live Syllabus routes -- update this pin (and the contract) deliberately.');
        $this->assertCount(4, $documented);
    }

    #[Test]
    public function the_contract_documents_no_delete_or_lifecycle_operation(): void
    {
        foreach ($this->documentedSyllabusOperations() as $operation) {
            $this->assertStringNotContainsStringIgnoringCase('DELETE ', $operation);
            $this->assertStringNotContainsStringIgnoringCase('activate', $operation);
            $this->assertStringNotContainsStringIgnoringCase('deactivate', $operation);
        }
    }

    #[Test]
    public function the_syllabus_schemas_expose_exactly_the_intended_properties(): void
    {
        // Asserting the EXACT property set is stronger than scanning for
        // forbidden words, and avoids false positives from OpenAPI's own
        // `description:` keyword. Any new field -- personal, grading or
        // otherwise -- fails this immediately.
        $yaml = file_get_contents($this->contractPath());
        $resource = $this->schemaBlock($yaml, '    SyllabusUnit:', '    SyllabusUnitCreateInput:');

        $properties = [];
        foreach (explode("\n", $resource) as $line) {
            if (preg_match('/^        (\w+):/', $line, $m)) {
                $properties[] = $m[1];
            }
        }

        $this->assertSame(
            ['id', 'subjectOfferingId', 'code', 'title', 'sequence', 'status'],
            $properties,
            'SyllabusUnit exposes exactly six non-personal, non-grading fields.',
        );
    }

    private function schemaBlock(string $yaml, string $from, string $to): string
    {
        $start = strpos($yaml, $from);
        $end = strpos($yaml, $to);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

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
    private function liveSyllabusOperations(): array
    {
        $operations = [];

        collect(Route::getRoutes())->each(function ($route) use (&$operations): void {
            $action = $route->getAction('controller');
            if (! is_string($action)) {
                return;
            }

            [$controller] = explode('@', $action, 2) + [null];
            if ($controller !== SyllabusUnitController::class) {
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
    private function documentedSyllabusOperations(): array
    {
        $lines = file($this->contractPath(), FILE_IGNORE_NEW_LINES);
        $operations = [];
        $currentPath = null;

        foreach ($lines as $line) {
            if (preg_match('#^  (/schools/\{schoolId\}/\S*syllabus-units\S*):$#', $line, $m)) {
                $currentPath = preg_replace('/\{[^}]+\}/', '{param}', $m[1]);

                continue;
            }

            // A column-0 line ends the whole `paths:` map.
            if ($currentPath !== null && preg_match('/^\S/', $line)) {
                $currentPath = null;

                continue;
            }

            // ANY other 2-space-indented path key also ends this block
            // (the Phase 0H.2 correction).
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
