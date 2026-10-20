<?php

namespace Tests\Feature\Authorization\Coverage;

use App\Domain\Visitor\Application\VisitorVisitAccess;
use App\Http\Controllers\App\Domains\SchoolDomainController;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Authorization\Coverage;
use Tests\Support\Authorization\CoverageGuard;
use Tests\Support\Authorization\SchoolRouteCoverage;
use Tests\TestCase;

/**
 * ORG.2 (ADR 0076 §28): the coverage guard itself catches what it exists to
 * catch. Probe routes are registered on this test's own application only --
 * never in routes/*.php.
 */
class AuthorizationCoverageGuardSelfTest extends TestCase
{
    private function guard(array $entries = []): CoverageGuard
    {
        $router = app(Router::class);
        $router->getRoutes()->refreshNameLookups();

        return new CoverageGuard($router->getRoutes()->getRoutes(), [...SchoolRouteCoverage::entries(), ...$entries]);
    }

    #[Test]
    public function a_signed_in_school_web_route_with_only_school_context_is_uncovered(): void
    {
        Route::middleware(['web', 'auth', 'school-context'])->get('/app/__org2-probe', fn () => 'probe')->name('org2.probe.web');

        $this->assertStringContainsString('[org2.probe.web] has no authorization contract', implode("\n", $this->guard()->uncovered()));
    }

    #[Test]
    public function a_school_api_route_with_only_sanctum_and_membership_is_uncovered(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'school-membership'])->get('/api/v1/schools/{school}/__org2-probe', fn () => 'probe')->name('org2.probe.api');

        $this->assertStringContainsString('[org2.probe.api] has no authorization contract', implode("\n", $this->guard()->uncovered()));
    }

    #[Test]
    public function a_new_non_school_route_is_unclassified_until_listed(): void
    {
        Route::get('/__org2-public-probe', fn () => 'probe')->name('org2.probe.public');

        $this->assertStringContainsString('[org2.probe.public] is not classified', implode("\n", $this->guard()->unclassified()));
    }

    #[Test]
    public function a_capability_middleware_route_is_covered_without_an_entry(): void
    {
        Route::middleware(['web', 'auth', 'school-context', 'capability:school.settings.view'])->get('/app/__org2-c1', fn () => 'probe')->name('org2.probe.c1');

        $this->assertStringNotContainsString('org2.probe.c1', implode("\n", $this->guard()->uncovered()));
    }

    #[Test]
    public function stale_duplicate_and_unproven_declarations_are_rejected(): void
    {
        $guard = $this->guard([
            Coverage::gate('org2.no-such-route', 'school.settings.view'),
            Coverage::gate('app.settings.domains.index', 'school.domains.view'),
        ]);

        $this->assertContains('[org2.no-such-route] is not a registered route', $guard->stale());
        $this->assertContains('[app.settings.domains.index] is declared more than once', $guard->duplicates());

        // A declared key the action does not gate on is caught from the code.
        Route::middleware(['web', 'auth', 'school-context'])->get('/app/__org2-wrong-key', [SchoolDomainController::class, 'index'])->name('org2.probe.wrong-key');
        $evidence = implode("\n", $this->guard([Coverage::gate('org2.probe.wrong-key', 'students.view')])->evidence());
        $this->assertStringContainsString('[org2.probe.wrong-key] C2 does not gate on [students.view]', $evidence);

        // A C3 service that does not authorize the actor is caught.
        Route::middleware(['web', 'auth', 'school-context'])->get('/app/__org2-closure', fn () => 'probe')->name('org2.probe.closure');
        $evidence = implode("\n", $this->guard([Coverage::service('org2.probe.closure', SchoolRouteCoverage::class, 'entries')])->evidence());
        $this->assertStringContainsString('[org2.probe.closure] C3 is not a controller action', $evidence);
    }

    #[Test]
    public function a_campus_floor_alone_is_never_a_contract_and_c6_evidence_is_checked(): void
    {
        // ORG.7A: `capability:x,campus` is an entry floor only -- it needs a C6 entry.
        Route::middleware(['web', 'auth', 'school-context', 'capability:visitor.visits.view,campus'])->get('/app/__org7a-floor', fn () => 'probe')->name('org7a.probe.floor');
        $this->assertStringContainsString('[org7a.probe.floor] has a `capability:...,campus` floor but no C6 Campus contract', implode("\n", $this->guard()->uncovered()));

        // A C6 entry on an unadopted capability, on an action that resolves no
        // CampusScope and never calls its access method, is caught from the code.
        Route::middleware(['web', 'auth', 'school-context'])->get('/app/__org7a-wrong', [SchoolDomainController::class, 'index'])->name('org7a.probe.wrong');
        $evidence = implode("\n", $this->guard([Coverage::campus('org7a.probe.wrong', 'guardians.view', VisitorVisitAccess::class, 'visits')])->evidence());
        $this->assertStringContainsString('[org7a.probe.wrong] C6 names [guardians.view], which is not adopted for Campus scope', $evidence);
        $this->assertStringContainsString("C6 does not resolve the actor's CampusScope for [guardians.view]", $evidence);
        $this->assertStringContainsString('[org7a.probe.wrong] C6 never calls visits() of VisitorVisitAccess', $evidence);
    }
}
