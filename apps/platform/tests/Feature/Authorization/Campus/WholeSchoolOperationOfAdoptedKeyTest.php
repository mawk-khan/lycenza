<?php

namespace Tests\Feature\Authorization\Campus;

use App\Models\School;
use App\Models\User;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Support\Authorization\CoverageGuard;
use Tests\Support\Authorization\SchoolRouteCoverage;
use Tests\TestCase;

/**
 * ORG.7D-0 (ADR 0076 §36): the declared whole-School-only operation of a
 * Campus-adopted key. Probe routes live on this test's application only.
 *
 * - A whole-School floor on an adopted key is refused unless the exact route
 *   is declared; removing the declaration or the floor fails the guard.
 * - Declarations are exact, School-facing, adopted, reasoned, and never
 *   combined with a Campus floor or an any-of floor.
 * - At runtime the declared route's ordinary floor (the `capability` Gate)
 *   never admits a Campus-only holder, and admits a whole-School holder.
 */
class WholeSchoolOperationOfAdoptedKeyTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const PROBE = 'org7d0.probe.whole-school';

    private const REASON = 'Probe: a School-wide directory that never takes a Campus.';

    private function probe(string $name = self::PROBE, string $floor = 'capability:visitor.visits.view'): void
    {
        Route::middleware(array_values(array_filter(['api', 'auth:sanctum', 'school-membership', $floor])))
            ->get('/api/v1/schools/{school}/__'.str_replace('.', '-', $name), fn () => response()->json(['ok' => true]))
            ->name($name);
    }

    /** @param  array<string, array{capability: string, reason: string}>  $declared */
    private function problems(array $declared): string
    {
        $router = app(Router::class);
        $router->getRoutes()->refreshNameLookups();

        return implode("\n", (new CoverageGuard($router->getRoutes()->getRoutes(), SchoolRouteCoverage::entries(), $declared))->adoptedWholeSchool());
    }

    #[Test]
    public function an_undeclared_whole_school_floor_on_an_adopted_key_fails_and_a_declared_one_passes(): void
    {
        $this->probe();

        $this->assertStringContainsString('['.self::PROBE.'] checks Campus-adopted [visitor.visits.view] with a whole-School floor but is not a declared whole-School-only operation', $this->problems([]));
        $this->assertStringNotContainsString(self::PROBE, $this->problems([self::PROBE => ['capability' => 'visitor.visits.view', 'reason' => self::REASON]]));
        $this->assertStringContainsString('['.self::PROBE.'] checks Campus-adopted', $this->problems([self::PROBE => ['capability' => 'visitor.visits.manage', 'reason' => self::REASON]]), 'the declaration names the exact key');
    }

    #[Test]
    public function a_declaration_without_its_ordinary_floor_or_with_a_campus_floor_fails(): void
    {
        $this->probe('org7d0.probe.no-floor', '');
        $this->probe('org7d0.probe.campus', 'capability:visitor.visits.view,campus');
        $this->probe('org7d0.probe.any-of', 'capability:visitor.visits.view|school.settings.view');
        $this->probe('org7d0.probe.odd-mode', 'capability:visitor.visits.view,Campus');
        $declare = fn (string $route) => [$route => ['capability' => 'visitor.visits.view', 'reason' => self::REASON]];

        $this->assertStringContainsString('[org7d0.probe.no-floor] whole-School-only operation does not carry its ordinary `capability:visitor.visits.view` floor', $this->problems($declare('org7d0.probe.no-floor')));
        $campus = $this->problems($declare('org7d0.probe.campus'));
        $this->assertStringContainsString('[org7d0.probe.campus] whole-School-only operation does not carry its ordinary', $campus);
        $this->assertStringContainsString('[org7d0.probe.campus] whole-School-only operation also carries a Campus floor or C6 contract', $campus);
        $anyOf = $this->problems($declare('org7d0.probe.any-of'));
        $this->assertStringContainsString('[org7d0.probe.any-of] checks Campus-adopted [visitor.visits.view] with a whole-School floor', $anyOf, 'an any-of floor is never a declared operation');
        $this->assertStringContainsString('[org7d0.probe.any-of] whole-School-only operation does not carry its ordinary', $anyOf);
        // An unknown mode is the whole-School Gate in EnsureCapability: flagged, and never the ordinary floor.
        $this->assertStringContainsString('[org7d0.probe.odd-mode] checks Campus-adopted [visitor.visits.view] with a whole-School floor', $this->problems([]));
        $this->assertStringContainsString('[org7d0.probe.odd-mode] whole-School-only operation does not carry its ordinary', $this->problems($declare('org7d0.probe.odd-mode')));
    }

    #[Test]
    public function a_whole_school_floor_on_a_route_that_is_not_school_facing_is_also_caught(): void
    {
        Route::middleware(['web', 'auth', 'capability:inventory.stock.manage'])->get('/__org7d0-not-school-facing', fn () => 'probe')->name('org7d0.probe.not-school-facing');

        $this->assertStringContainsString('[org7d0.probe.not-school-facing] checks Campus-adopted [inventory.stock.manage] with a whole-School floor', $this->problems([]));
    }

    #[Test]
    public function stale_pattern_unadopted_and_unreasoned_declarations_fail(): void
    {
        $this->probe('org7d0.probe.unadopted', 'capability:students.view');

        $problems = $this->problems([
            'org7d0.no-such-route' => ['capability' => 'visitor.visits.view', 'reason' => self::REASON],
            'org7d0.probe.*' => ['capability' => 'visitor.visits.view', 'reason' => self::REASON],
            'org7d0.probe.unadopted' => ['capability' => 'students.view', 'reason' => ' '],
        ]);

        $this->assertStringContainsString('[org7d0.no-such-route] whole-School-only operation is not a registered route', $problems);
        $this->assertStringContainsString('[org7d0.probe.*] whole-School-only operation looks like a pattern', $problems);
        $this->assertStringContainsString('[org7d0.probe.unadopted] whole-School-only operation names [students.view], which is not Campus-adopted', $problems);
        $this->assertStringContainsString('[org7d0.probe.unadopted] whole-School-only operation needs its reason', $problems);
    }

    private function holder(School $school, ?string $campusId): User
    {
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $school), 'front_office', $campusId);

        return $user;
    }

    private function probeStatus(User $user, School $school): int
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
            ->getJson('/api/v1/schools/'.$school->id.'/__'.str_replace('.', '-', self::PROBE))->status();
    }

    #[Test]
    public function a_campus_only_holder_never_enters_a_declared_operation_and_a_whole_school_holder_does(): void
    {
        $this->probe();
        $school = $this->createSchool();
        $campus = $this->createCampus($school);

        $this->assertSame(403, $this->probeStatus($this->holder($school, $campus->id), $school), 'a Campus grant never satisfies the whole-School floor');
        $this->assertSame(200, $this->probeStatus($this->holder($school, null), $school));
    }
}
