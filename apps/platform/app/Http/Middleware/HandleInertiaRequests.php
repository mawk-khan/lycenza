<?php

namespace App\Http\Middleware;

use App\Support\Navigation\NavigationBuilder;
use App\Support\Presentation\BrandResolver;
use App\Support\ProductIdentity;
use App\Support\Tenancy\ElevationContext;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Every page rendered for a signed-in user is stored in the
     * browser's history encrypted (Inertia history encryption); logout
     * clears the key (LoginController::destroy()), so Back cannot redraw
     * a signed-in page. Guest pages (login, MFA challenge) stay
     * unencrypted. Set explicitly both ways per request: the Inertia
     * response factory is a singleton. See docs/security/AUTHORIZATION.md
     * ("After logout").
     */
    public function handle(Request $request, Closure $next)
    {
        Inertia::encryptHistory($request->user() !== null);

        return parent::handle($request, $next);
    }

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $elevation = app(ElevationContext::class)->elevation();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null,
            ],
            // Phase 0N.3 (ADR 0044 section 12): drives the persistent
            // elevated-access banner on every signed-in page. Only the
            // target School's name and the fixed expiry -- no other School
            // data, and nothing that implies a School permission.
            'elevation' => $user !== null && $elevation !== null ? [
                'schoolName' => $elevation->school?->name,
                'expiresAt' => $elevation->expires_at->toIso8601String(),
                // Phase 0N.5: the authorizing Group for a Group-derived one.
                'groupName' => $elevation->isGroupDerived() ? $elevation->schoolGroup?->name : null,
            ] : null,
            // SWC.1 (ADR 0073 section 7): the user-facing product name.
            'product' => ['name' => ProductIdentity::NAME],
            // SWC.1 (ADR 0073 section 6): the capability-driven navigation.
            // A closure, so it is evaluated when the response is built --
            // after the School context is resolved -- never here in share(),
            // where route middleware has not yet run (rule 61's lesson).
            // Discoverability only: every destination re-checks its own
            // capability (CLAUDE.md rule 6).
            'navigation' => $user !== null ? fn () => app(NavigationBuilder::class)->forRequest($request) : null,
            // ORG.12F (ADR 0080 §11.6): the active School's effective brand
            // (label, accent and its derived readable foreground) -- presentation
            // only; resolved lazily after the School context, never for an
            // elevated session or without a School.
            'brand' => $user !== null ? fn () => ($school = app(TenantContext::class)->school()) !== null && ! app(ElevationContext::class)->isElevated()
                ? array_intersect_key(app(BrandResolver::class)->forSchool($school), array_flip(['label', 'accent', 'foreground', 'source']))
                : null : null,
            // X-01 (OD-V11-7, ADR 0075 section 12): the success message a
            // controller flashed on its redirect. 64 actions flash `flash` or
            // `status`; nothing shared them, so none was ever shown. Namespaced
            // so it can never collide with a page's own `status` prop. Strings
            // only; errors stay with their forms (ValidationException).
            // UAT-C4 (UAT-UX-02, ADR 0075 section 17): the active School's
            // timezone, so every page shows School times the same way
            // (resources/js/datetime.ts). Resolved lazily, after the School
            // context middleware; null outside a School.
            'schoolTimezone' => fn () => ($school = app(TenantContext::class)->school()) === null ? null : SchoolTimezone::resolve($school)->getName(),
            'flash' => fn () => [
                'success' => self::flashedString($request, 'flash') ?? self::flashedString($request, 'status'),
            ],
        ];
    }

    private static function flashedString(Request $request, string $key): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $value = $request->session()->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
