<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SWC.1 (ADR 0073 section 7.1): the public `/` -- a neutral, login-directed
 * product entry, the same on every host (`''` is admitted on School custom
 * hosts, ADR 0054 section 8.2, so it shows no School data before sign-in).
 * A signed-in visitor goes to the /app landing. It renders no environment,
 * request id, build information or roadmap; the product name is the shared
 * `product` prop. No business logic -- the shape every controller here
 * stays close to (CLAUDE.md rule 3).
 */
class HomeController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('app.dashboard');
        }

        return Inertia::render('Home');
    }
}
