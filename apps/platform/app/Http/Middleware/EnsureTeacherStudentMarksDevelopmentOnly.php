<?php

namespace App\Http\Middleware;

use App\Domain\Examinations\Application\Marks\TeacherStudentMarkAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RES.4 (ADR 0068 §25.3): the teacher marks routes answer outside `local` /
 * `testing` with one fixed 403, before any capability, identity, ownership or
 * marks work -- the interim block while RES-L2 (E37) and the teacher RES-L0
 * re-review (E35) are undetermined. Not configurable. The Application layer
 * enforces the same block again (TeacherStudentMarkAvailability), so a route
 * that forgot this middleware would still be refused.
 */
class EnsureTeacherStudentMarksDevelopmentOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! TeacherStudentMarkAvailability::isAvailable()) {
            return response()->json(['error' => [
                'code' => 'TEACHER_STUDENT_MARKS_UNAVAILABLE',
                'message' => 'Teacher marks entry is not available in this environment.',
                'status' => 403,
            ]], 403);
        }

        return $next($request);
    }
}
