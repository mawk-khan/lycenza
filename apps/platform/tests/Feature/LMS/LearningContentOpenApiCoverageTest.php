<?php

namespace Tests\Feature\LMS;

use App\Domain\LMS\Http\Controllers\LearningContentController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0I.2 (OpenAPI contract, MANDATORY in this first implementation
 * branch). Bidirectional proof that
 * `packages/contracts/openapi/school-os-api.yaml`'s Learning Content
 * paths exactly match the live `/api/v1` routes registered for
 * LearningContentController: zero live-but-undocumented operations,
 * zero documented-but-nonexistent ones. Mirrors
 * Tests\Feature\Syllabus\SyllabusOpenApiCoverageTest's corrected
 * path-block boundary logic exactly.
 */
class LearningContentOpenApiCoverageTest extends TestCase
{
    #[Test]
    public function every_live_learning_content_route_is_documented_and_vice_versa(): void
    {
        $live = $this->liveLearningContentOperations();
        $documented = $this->documentedLearningContentOperations();

        $liveButUndocumented = array_values(array_diff($live, $documented));
        $documentedButNonexistent = array_values(array_diff($documented, $live));

        $this->assertSame([], $liveButUndocumented,
            'Live Learning Content route(s) missing from the OpenAPI contract: '.implode(', ', $liveButUndocumented));
        $this->assertSame([], $documentedButNonexistent,
            'OpenAPI Learning Content path(s)/operation(s) with no matching live route: '.implode(', ', $documentedButNonexistent));

        $this->assertCount(6, $live, 'Expected exactly 6 live Learning Content routes -- update this pin (and the contract) deliberately.');
        $this->assertCount(6, $documented);
    }

    #[Test]
    public function the_contract_documents_no_delete_operation(): void
    {
        foreach ($this->documentedLearningContentOperations() as $operation) {
            $this->assertStringNotContainsStringIgnoringCase('DELETE ', $operation);
        }
    }

    #[Test]
    public function the_learning_content_schema_exposes_exactly_the_intended_properties(): void
    {
        $yaml = file_get_contents($this->contractPath());
        $resource = $this->schemaBlock($yaml, '    LearningContent:', '    LearningContentCreateInput:');

        $properties = [];
        foreach (explode("\n", $resource) as $line) {
            if (preg_match('/^        (\w+):/', $line, $m)) {
                $properties[] = $m[1];
            }
        }

        $this->assertSame(
            ['id', 'subjectOfferingId', 'title', 'description', 'sequence', 'status'],
            $properties,
            'LearningContent exposes exactly six non-personal, non-grading fields.',
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
    private function liveLearningContentOperations(): array
    {
        $operations = [];

        collect(Route::getRoutes())->each(function ($route) use (&$operations): void {
            $action = $route->getAction('controller');
            if (! is_string($action)) {
                return;
            }

            [$controller] = explode('@', $action, 2) + [null];
            if ($controller !== LearningContentController::class) {
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
    private function documentedLearningContentOperations(): array
    {
        $lines = file($this->contractPath(), FILE_IGNORE_NEW_LINES);
        $operations = [];
        $currentPath = null;

        foreach ($lines as $line) {
            // Matches learning-content paths but EXCLUDES the Documents
            // owner-arm extension path (`.../documents`) -- that route
            // lives on DocumentController, not LearningContentController,
            // and is out of scope for this specific bidirectional proof
            // (matching the codebase's existing Documents-module
            // convention of not yet having its own coverage test).
            if (preg_match('#^  (/schools/\{schoolId\}/(?:subject-offerings/\{subjectOfferingId\}/learning-content|learning-content/\{learningContentId\}(?:/publish|/archive)?)):$#', $line, $m)) {
                $currentPath = preg_replace('/\{[^}]+\}/', '{param}', $m[1]);

                continue;
            }

            // A column-0 line ends the whole `paths:` map.
            if ($currentPath !== null && preg_match('/^\S/', $line)) {
                $currentPath = null;

                continue;
            }

            // ANY other 2-space-indented path key also ends this block.
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
