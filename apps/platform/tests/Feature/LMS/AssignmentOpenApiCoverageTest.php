<?php

namespace Tests\Feature\LMS;

use App\Domain\LMS\Http\Controllers\AssignmentController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0I.3 (OpenAPI contract, MANDATORY in this first implementation
 * branch). Bidirectional proof that
 * `packages/contracts/openapi/school-os-api.yaml`'s Assignment paths
 * exactly match the live `/api/v1` routes registered for
 * AssignmentController. Mirrors
 * Tests\Feature\LMS\LearningContentOpenApiCoverageTest's corrected
 * path-block boundary logic exactly.
 */
class AssignmentOpenApiCoverageTest extends TestCase
{
    #[Test]
    public function every_live_assignment_route_is_documented_and_vice_versa(): void
    {
        $live = $this->liveAssignmentOperations();
        $documented = $this->documentedAssignmentOperations();

        $liveButUndocumented = array_values(array_diff($live, $documented));
        $documentedButNonexistent = array_values(array_diff($documented, $live));

        $this->assertSame([], $liveButUndocumented,
            'Live Assignment route(s) missing from the OpenAPI contract: '.implode(', ', $liveButUndocumented));
        $this->assertSame([], $documentedButNonexistent,
            'OpenAPI Assignment path(s)/operation(s) with no matching live route: '.implode(', ', $documentedButNonexistent));

        $this->assertCount(6, $live, 'Expected exactly 6 live Assignment routes -- update this pin (and the contract) deliberately.');
        $this->assertCount(6, $documented);
    }

    #[Test]
    public function the_contract_documents_no_delete_or_submission_operation(): void
    {
        foreach ($this->documentedAssignmentOperations() as $operation) {
            $this->assertStringNotContainsStringIgnoringCase('DELETE ', $operation);
            $this->assertStringNotContainsStringIgnoringCase('submit', $operation);
            $this->assertStringNotContainsStringIgnoringCase('grade', $operation);
        }
    }

    #[Test]
    public function the_assignment_schema_exposes_exactly_the_intended_properties(): void
    {
        $yaml = file_get_contents($this->contractPath());
        $resource = $this->schemaBlock($yaml, '    Assignment:', '    AssignmentCreateInput:');

        $properties = [];
        foreach (explode("\n", $resource) as $line) {
            if (preg_match('/^        (\w+):/', $line, $m)) {
                $properties[] = $m[1];
            }
        }

        $this->assertSame(
            ['id', 'subjectOfferingId', 'title', 'instructions', 'dueOn', 'status'],
            $properties,
            'Assignment exposes exactly six non-personal, non-grading fields.',
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
    private function liveAssignmentOperations(): array
    {
        $operations = [];

        collect(Route::getRoutes())->each(function ($route) use (&$operations): void {
            $action = $route->getAction('controller');
            if (! is_string($action)) {
                return;
            }

            [$controller] = explode('@', $action, 2) + [null];
            if ($controller !== AssignmentController::class) {
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
    private function documentedAssignmentOperations(): array
    {
        $lines = file($this->contractPath(), FILE_IGNORE_NEW_LINES);
        $operations = [];
        $currentPath = null;

        foreach ($lines as $line) {
            // Matches assignment paths but EXCLUDES the Documents
            // owner-arm extension path (`.../documents`) -- that route
            // lives on DocumentController, not AssignmentController.
            if (preg_match('#^  (/schools/\{schoolId\}/(?:subject-offerings/\{subjectOfferingId\}/assignments|assignments/\{assignmentId\}(?:/publish|/close)?)):$#', $line, $m)) {
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
