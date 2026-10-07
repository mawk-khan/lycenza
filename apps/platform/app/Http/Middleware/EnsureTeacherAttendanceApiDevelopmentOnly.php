<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * E33 / TCH-L1 (ADR 0063 section 43): the owned teacher Attendance bearer-token
 * routes are DEVELOPMENT ONLY. A bearer token cannot carry MFA assurance
 * (ADR 0049) and no equivalent control is approved, so production teacher
 * Attendance is the session surface with MFA. Double-guarded -- config flag
 * AND `local`/`testing` -- exactly like DevOnlySchoolHeaderResolver, so a
 * misconfigured production variable alone cannot open it. Refused before any
 * capability, identity or ownership work, with one fixed body that names no
 * School, class or Student.
 */
class EnsureTeacherAttendanceApiDevelopmentOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('attendance.teacher_api_development_enabled') || ! app()->environment(['local', 'testing'])) {
            return response()->json(['error' => [
                'code' => 'TEACHER_ATTENDANCE_API_UNAVAILABLE',
                'message' => 'Teacher Attendance is available only in the signed-in web app with multi-factor authentication.',
                'status' => 403,
            ]], 403);
        }

        return $next($request);
    }
}
