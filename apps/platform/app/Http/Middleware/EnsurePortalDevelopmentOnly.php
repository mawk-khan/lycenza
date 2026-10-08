<?php

namespace App\Http\Middleware;

use App\Support\Portal\PortalAvailability;
use App\Support\Portal\PortalUnavailableException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POR (ADR 0070 §18.2): every portal route answers outside `local` /
 * `testing` with one fixed 403, before any capability, Guardian or
 * Communications work -- production waits for POR-L1 (E46). Not
 * configurable; the Application layer enforces the same block again
 * (PortalAvailability).
 */
class EnsurePortalDevelopmentOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! PortalAvailability::isAvailable()) {
            if ($request->expectsJson()) {
                return response()->json(['error' => [
                    'code' => PortalUnavailableException::CODE,
                    'message' => PortalUnavailableException::MESSAGE,
                    'status' => 403,
                ]], 403);
            }

            throw new PortalUnavailableException;
        }

        return $next($request);
    }
}
