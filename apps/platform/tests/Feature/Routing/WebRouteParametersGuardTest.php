<?php

namespace Tests\Feature\Routing;

use App\Support\Routing\WebRouteParameters;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LC-1 (Local Product Completion M1): every parameter of a signed-in web
 * route is classified -- a UUID record id carries the WebRouteParameters
 * constraint (a malformed id is a 404, never a database error), or it is
 * one of the named non-UUID parameters. A new page with a new parameter
 * fails here until it is classified.
 */
class WebRouteParametersGuardTest extends TestCase
{
    /**
     * @return list<Route>
     */
    private function signedInWebRoutes(): array
    {
        return array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            fn (Route $route) => in_array('web', $route->gatherMiddleware(), true)
                && in_array('auth', $route->gatherMiddleware(), true),
        ));
    }

    #[Test]
    public function every_signed_in_route_parameter_is_classified_and_uuid_ids_are_constrained(): void
    {
        $routes = $this->signedInWebRoutes();
        $this->assertGreaterThan(500, count($routes));
        $this->assertSame([], array_intersect(WebRouteParameters::UUID, WebRouteParameters::NOT_UUID));

        foreach ($routes as $route) {
            foreach ($route->parameterNames() as $name) {
                $this->assertTrue(
                    in_array($name, WebRouteParameters::UUID, true) || in_array($name, WebRouteParameters::NOT_UUID, true),
                    "{$route->uri()}: classify {{$name}} in App\\Support\\Routing\\WebRouteParameters",
                );
                if (in_array($name, WebRouteParameters::UUID, true)) {
                    $this->assertArrayHasKey($name, $route->wheres, "{$route->uri()}: {{$name}} must be UUID-constrained");
                    $this->assertMatchesRegularExpression('#^'.$route->wheres[$name].'$#', '01a148e5-2d1e-79ca-8867-7b77636bba9e');
                    $this->assertDoesNotMatchRegularExpression('#^'.$route->wheres[$name].'$#', 'settings');
                }
            }
        }
    }
}
