<?php

namespace Tests\Feature\Timetable;

use App\Domain\Timetable\Http\Controllers\TimetableEntryController;
use App\Domain\Timetable\Http\Controllers\TimetablePeriodController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0H.1 (OpenAPI contract closure): a narrow, Timetable-scoped
 * proof that `packages/contracts/openapi/school-os-api.yaml`'s
 * Timetable paths exactly match the live routes registered for
 * `TimetablePeriodController`/`TimetableEntryController` in
 * `routes/api.php` -- zero live-but-undocumented routes, zero
 * documented-but-nonexistent paths.
 *
 * No repo-wide OpenAPI coverage mechanism exists yet in this codebase
 * (none was found when this test was written -- see
 * `apps/platform/tests/` having no prior `OpenApiCoverageTest`-shaped
 * file anywhere), so this is deliberately scoped to Timetable only,
 * per this checkpoint's brief, rather than inventing an ambitious
 * repo-wide crawler.
 *
 * No YAML parser is available in this project's installed composer
 * dependency set (composer.lock carries no installed `symfony/yaml`
 * package -- only version-constraint mentions inside other packages'
 * own `suggest`/`conflict` blocks -- and CLAUDE.md rule 2 forbids
 * adding a package for a need that doesn't exist yet just for one
 * test), so the YAML file is instead parsed with a small, deliberately
 * narrow regex tailored to this contract file's own consistent
 * two-space path-key / four-space operation-key indentation --
 * `documentedTimetableOperations()`'s own docblock explains exactly
 * what it relies on. This is a pragmatic trade-off scoped to this one
 * test, not a general YAML parser.
 */
class TimetableOpenApiCoverageTest extends TestCase
{
    #[Test]
    public function every_live_timetable_route_is_documented_and_vice_versa(): void
    {
        $live = $this->liveTimetableOperations();
        $documented = $this->documentedTimetableOperations();

        $liveButUndocumented = array_values(array_diff($live, $documented));
        $documentedButNonexistent = array_values(array_diff($documented, $live));

        $this->assertSame(
            [],
            $liveButUndocumented,
            'Live Timetable route(s) missing from the OpenAPI contract: '.implode(', ', $liveButUndocumented)
        );
        $this->assertSame(
            [],
            $documentedButNonexistent,
            'OpenAPI Timetable path(s)/operation(s) with no matching live route: '.implode(', ', $documentedButNonexistent)
        );

        // Pinned so a silent drift (e.g. a route AND its doc entry both
        // quietly removed together, which the diff assertions above
        // cannot catch on their own) fails loudly too.
        $this->assertCount(17, $live, 'Expected exactly 17 live Timetable routes -- update this pin (and the OpenAPI contract) deliberately if this ever changes.');
        $this->assertCount(17, $documented);
    }

    /**
     * @return list<string> "METHOD /normalized/path" strings, one per
     *                      live route whose controller is
     *                      TimetablePeriodController or
     *                      TimetableEntryController. HEAD is dropped
     *                      (Laravel auto-registers it alongside every
     *                      GET route; it carries no separate OpenAPI
     *                      operation). Every `{param}` path segment is
     *                      normalized to the literal string `{param}`
     *                      so a Laravel route-parameter name
     *                      (`{timetablePeriod}`) and the contract's
     *                      own parameter name (`{timetablePeriodId}`)
     *                      are treated as equivalent -- this test
     *                      proves path/method coverage, not parameter
     *                      naming, a deliberately narrower scope than
     *                      a full contract-conformance test would
     *                      need.
     */
    private function liveTimetableOperations(): array
    {
        $controllers = [TimetablePeriodController::class, TimetableEntryController::class];
        $operations = [];

        // collect(Route::getRoutes()) -- not a raw foreach -- mirrors
        // Tests\Feature\Canteen\CanteenArchitectureGuardTest's own
        // established route-introspection pattern.
        collect(Route::getRoutes())->each(function ($route) use ($controllers, &$operations): void {
            $action = $route->getAction('controller');
            if (! is_string($action)) {
                return;
            }

            [$controller] = explode('@', $action, 2) + [null];
            if (! in_array($controller, $controllers, true)) {
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
     * Regex-based, deliberately narrow to this contract file's own
     * formatting: every Timetable path key is a 2-space-indented line
     * of the exact shape `  /schools/{schoolId}/timetable-...:`
     * (a unique prefix -- cannot collide with any other module's
     * paths), whose HTTP-method operation keys immediately follow as
     * 4-space-indented `    get:`/`post:`/`patch:` lines up until the
     * next path key (or the `components:` root key ends the whole
     * `paths:` map). Path parameters are normalized to the literal
     * `{param}` exactly like liveTimetableOperations(), for the same
     * reason.
     *
     * @return list<string> "METHOD /normalized/path" strings
     */
    private function documentedTimetableOperations(): array
    {
        $yamlPath = base_path('../../packages/contracts/openapi/school-os-api.yaml');
        $this->assertFileExists($yamlPath, 'OpenAPI contract file not found at expected path.');

        $lines = file($yamlPath, FILE_IGNORE_NEW_LINES);
        $operations = [];
        $currentPath = null;

        foreach ($lines as $line) {
            if (preg_match('#^  (/schools/\{schoolId\}/timetable-\S*):$#', $line, $m)) {
                $currentPath = preg_replace('/\{[^}]+\}/', '{param}', $m[1]);

                continue;
            }

            // Any unindented (column-0) line ends the whole `paths:`
            // map for our purposes (the only such line after this
            // contract's Timetable block is `components:`).
            if ($currentPath !== null && preg_match('/^\S/', $line)) {
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
