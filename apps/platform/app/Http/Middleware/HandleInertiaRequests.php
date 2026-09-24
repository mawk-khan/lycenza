<?php

namespace App\Http\Middleware;

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

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null,
            ],
        ];
    }
}
