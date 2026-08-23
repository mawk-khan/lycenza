<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dev/Test-Only School Header Override
    |--------------------------------------------------------------------------
    |
    | When true, App\Http\Middleware\DevOnlySchoolHeaderResolver honours
    | an X-School-Id header for an authenticated user's own active
    | membership. It is double-guarded: this flag AND
    | app()->environment(['local', 'testing']) must both be true, so a
    | misconfigured production env var alone cannot enable it. See
    | docs/architecture/TENANCY.md ("School domain resolution") and
    | section 8 of this checkpoint's brief. Never trust this header
    | outside local/testing -- production tenant resolution comes only
    | from verified domain routing or an authorized session-based
    | school switch (App\Http\Middleware\ResolveSchoolContext).
    |
    */

    'allow_dev_header_override' => (bool) env('TENANCY_ALLOW_DEV_HEADER_OVERRIDE', false),

];
