<?php

namespace Tests\Feature\Authorization\Coverage;

use App\Http\Middleware\Api\ThrottleFailedApiAuthentication;
use App\Http\Middleware\RequireSchoolContext;
use App\Models\School;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Support\Authorization\Coverage;
use Tests\Support\Authorization\SchoolRouteCoverage;
use Tests\TestCase;

/**
 * ORG.2 (ADR 0076 §28): the runtime half of the coverage guard. Every
 * School-facing route is requested by
 *
 *   - an active member of the School holding NO capability: membership is
 *     not authorization, so every route refuses (403) or conceals (404) --
 *     except a route whose declared authority runs after input validation
 *     (`validatesFirst()`: an empty payload sees the validation error first)
 *     and the few routes open to every member by design (`forEveryMember()`);
 *   - a School Administrator of ANOTHER School: never served, never shown a
 *     validation error (which would mean the request reached the action).
 *
 * Throttling is switched off so every route is reached; nothing else is.
 */
class SchoolRouteRefusalSweepTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleFailedApiAuthentication::class]);
    }

    #[Test]
    public function a_member_without_capabilities_is_refused_on_every_school_route(): void
    {
        $school = $this->createSchool();
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $entries = $this->entries();

        $problems = [];
        foreach ($this->schoolRoutes() as $route) {
            $entry = $entries[$route->getName()] ?? null;
            $response = $this->hit($route, $member, $school, $school);
            $status = $response->getStatusCode();
            $validation = $this->isValidationOutcome($response);

            $allowed = in_array($status, [403, 404], true)
                || ($validation && $entry !== null && ($entry->validatesFirst || $entry->everyMember))
                || ($entry?->everyMember === true && $status < 300);

            if (! $allowed) {
                $problems[] = $this->describe($route, $status, $validation);
            }
            if ($entry?->validatesFirst === true && ! $validation) {
                $problems[] = $this->describe($route, $status, $validation).' is declared validatesFirst() but did not validate first -- remove the flag';
            }
        }

        $this->assertSame([], $problems, "A capability-less member must be refused (403) or concealed (404):\n");
    }

    #[Test]
    public function an_administrator_of_another_school_is_never_served(): void
    {
        [$outsider] = $this->createSchoolAdmin();
        $target = $this->createSchool();

        $problems = [];
        foreach ($this->schoolRoutes() as $route) {
            $response = $this->hit($route, $outsider, $target, $target);
            $status = $response->getStatusCode();
            $validation = $this->isValidationOutcome($response);
            // No School context for a non-member: pages redirect to the landing,
            // anything else gets RequireSchoolContext's fixed 409.
            $noContext = ($status === 302 || $status === RequireSchoolContext::STATUS) && ! $validation;

            if (! in_array($status, [401, 403, 404], true) && ! $noContext) {
                $problems[] = $this->describe($route, $status, $validation);
            }
        }

        $this->assertSame([], $problems, "Cross-School requests must fail closed:\n");
    }

    /** @return array<string, Coverage> */
    private function entries(): array
    {
        $keyed = [];
        foreach (SchoolRouteCoverage::entries() as $entry) {
            $keyed[$entry->route] = $entry;
        }

        return $keyed;
    }

    /** @return list<Route> the School web and API routes (the C5 token routes have their own tests) */
    private function schoolRoutes(): array
    {
        return array_values(array_filter(
            app(Router::class)->getRoutes()->getRoutes(),
            fn (Route $route) => in_array('school-context', $route->gatherMiddleware(), true) || in_array('school-membership', $route->gatherMiddleware(), true),
        ));
    }

    private function hit(Route $route, User $actor, School $sessionSchool, School $routeSchool): TestResponse
    {
        $uri = $route->uri();
        foreach ($route->parameterNames() as $parameter) {
            $value = $parameter === 'school' ? $routeSchool->id : (string) Str::uuid();
            $where = $route->wheres[$parameter] ?? null;
            if ($where !== null && ! preg_match('/^'.$where.'$/', $value)) {
                $first = explode('|', trim($where, '()'))[0];
                $value = preg_match('/^[\w.-]+$/', $first) ? $first : 'x';
            }
            $uri = (string) preg_replace('/\{'.$parameter.'\??\}/', $value, $uri);
        }

        $method = $route->methods()[0];
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        if (in_array('school-membership', $route->gatherMiddleware(), true)) {
            // A session-backed actor: the API credential-scope gate applies only to
            // bearer tokens, so the request reaches the School authorization itself.
            return $this->actingAs($actor)->json($method, '/'.$uri);
        }

        return $this->actingAs($actor)
            ->withSession([RequireSchoolContext::SESSION_KEY => $sessionSchool->id])
            ->call($method, '/'.$uri, [], [], [], ['HTTP_ACCEPT' => 'text/html']);
    }

    private function isValidationOutcome(TestResponse $response): bool
    {
        if ($response->getStatusCode() === 422) {
            return true;
        }

        $errors = session('errors');

        return $response->getStatusCode() === 302 && ($errors instanceof ViewErrorBag ? $errors->any() : ! empty($errors));
    }

    private function describe(Route $route, int $status, bool $validation): string
    {
        return $status.($validation ? ' (validation)' : '').' '.$route->methods()[0].' '.$route->uri().' ['.$route->getName().']';
    }
}
