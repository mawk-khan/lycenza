<?php

namespace App\Http\Middleware;

use App\Domain\Examinations\Application\Marks\StudentMarkAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RES.5 (ADR 0068 §27): every StudentMark route answers outside `local` /
 * `testing` with one fixed 403, before any capability, MFA or marks work --
 * production waits for RES-L1 (E36). Not configurable; the Application layer
 * enforces the same block again (StudentMarkAvailability).
 */
class EnsureStudentMarksDevelopmentOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! StudentMarkAvailability::isAvailable()) {
            return response()->json(['error' => [
                'code' => 'STUDENT_MARKS_UNAVAILABLE',
                'message' => 'Student marks are not available in this environment.',
                'status' => 403,
            ]], 403);
        }

        return $next($request);
    }
}
