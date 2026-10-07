<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Teacher Attendance bearer-token API (E33 / TCH-L1, ADR 0063 section 43)
    |--------------------------------------------------------------------------
    |
    | The E33 determination requires MFA (or a formally approved equivalent)
    | for production teacher Attendance. A bearer token cannot carry MFA
    | assurance (ADR 0049), and no equivalent control is approved, so the
    | owned `/api/v1/schools/{school}/my/attendance-*` routes answer a fixed
    | 403 unless this flag is on AND the application runs in `local` or
    | `testing` (App\Http\Middleware\EnsureTeacherAttendanceApiDevelopmentOnly)
    | -- the DevOnlySchoolHeaderResolver double guard. A production
    | environment variable alone can never enable it. Teacher Attendance in
    | production is the session surface (`/app/my-attendance`, with MFA).
    |
    */

    'teacher_api_development_enabled' => (bool) env('TEACHER_ATTENDANCE_API_DEVELOPMENT_ENABLED', false),

];
