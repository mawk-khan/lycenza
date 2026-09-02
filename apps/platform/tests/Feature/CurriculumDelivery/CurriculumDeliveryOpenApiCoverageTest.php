<?php

namespace Tests\Feature\CurriculumDelivery;

use App\Domain\CurriculumDelivery\Http\Controllers\CurriculumDeliveryController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0H.3B (OpenAPI contract, MANDATORY in this first implementation
 * branch -- not a later correction pass). Bidirectional proof that
 * `packages/contracts/openapi/school-os-api.yaml`'s Curriculum Delivery
 * paths exactly match the live `/api/v1` routes registered for
 * CurriculumDeliveryController: zero live-but-undocumented operations,
 * zero documented-but-nonexistent ones.
 *
 * Uses the CORRECTED path-block boundary logic established during Phase
 * 0H.2 and reused by Phase 0H.3A: a block ends at a column-0 line (e.g.
 * `components:`) OR at any other 2-space-indented path key.
 */
class CurriculumDeliveryOpenApiCoverageTest extends TestCase
{
    #[Test]
    public function every_live_curriculum_delivery_route_is_documented_and_vice_versa(): void
    {
        $live = $this->liveOperations();
        $documented = $this->documentedOperations();

        $liveButUndocumented = array_values(array_diff($live, $documented));
        $documentedButNonexistent = array_values(array_diff($documented, $live));

        $this->assertSame([], $liveButUndocumented,
            'Live Curriculum Delivery route(s) missing from the OpenAPI contract: '.implode(', ', $liveButUndocumented));
        $this->assertSame([], $documentedButNonexistent,
            'OpenAPI Curriculum Delivery path(s)/operation(s) with no matching live route: '.implode(', ', $documentedButNonexistent));

        // Pinned so a silent drift (a route AND its doc entry quietly
        // removed together, which the diffs above cannot catch) fails
        // loudly too.
        $this->assertCount(5, $live, 'Expected exactly 5 live Curriculum Delivery routes -- update this pin (and the contract) deliberately.');
        $this->assertCount(5, $documented);
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
    public function the_curriculum_delivery_schema_exposes_exactly_the_intended_properties(): void
    {
        // Asserting the EXACT property set is stronger than scanning for
        // forbidden words, and avoids false positives from OpenAPI's own
        // `description:` keyword. Any new field -- a teacher, a Student,
        // a mark, or one of the four structural integrity pins -- fails
        // this immediately.
        $yaml = file_get_contents($this->contractPath());
        $resource = $this->schemaBlock($yaml, '    CurriculumDelivery:', '    CurriculumDeliveryCreateInput:');

        $properties = [];
        foreach (explode("\n", $resource) as $line) {
            if (preg_match('/^        (\w+):/', $line, $m)) {
                $properties[] = $m[1];
            }
        }

        $this->assertSame(
            ['id', 'sectionId', 'subjectOfferingId', 'syllabusUnitId', 'startedOn', 'completedOn', 'status'],
            $properties,
            'CurriculumDelivery exposes exactly seven non-personal, non-grading fields.',
        );
    }

    #[Test]
    public function the_documented_status_enum_is_the_closed_two_state_vocabulary(): void
    {
        $yaml = file_get_contents($this->contractPath());
        $enum = $this->schemaBlock($yaml, '    CurriculumDeliveryStatus:', '    CurriculumDelivery:');

        // Assert on the `enum:` LINE, not the whole block: the schema's
        // prose legitimately explains why `not_started` is excluded, and
        // the contract is the enum itself.
        $this->assertSame(1, preg_match('/^      enum: \[(.+)\]$/m', $enum, $m),
            'CurriculumDeliveryStatus must declare a single-line enum.');
        $this->assertSame('in_progress, completed', $m[1]);
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
            if ($controller !== CurriculumDeliveryController::class) {
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
            if (preg_match('#^  (/schools/\{schoolId\}/\S*curriculum-deliveries\S*):$#', $line, $m)) {
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
