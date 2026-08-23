<?php

namespace App\Http\Middleware;

use App\Models\SchoolMembership;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TEST/DEV-ONLY. Honours an X-School-Id header for local development
 * convenience and for tests that need to set up School context without
 * a full domain/session flow. Double-guarded (config flag AND runtime
 * environment check) so a misconfigured production environment
 * variable alone cannot enable it -- see config/tenancy.php and
 * docs/architecture/TENANCY.md ("School domain resolution").
 *
 * Still validated against a real active membership, same as
 * ResolveSchoolContext's session path -- this shortcuts *which* School
 * gets selected for convenience, it never grants access the user
 * doesn't otherwise have.
 */
class DevOnlySchoolHeaderResolver
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('tenancy.allow_dev_header_override') || ! app()->environment(['local', 'testing'])) {
            return $next($request);
        }

        $schoolId = $request->header('X-School-Id');
        $user = $request->user();

        if ($schoolId !== null && $user !== null) {
            $membership = SchoolMembership::query()
                ->where('user_id', $user->id)
                ->where('school_id', $schoolId)
                ->where('status', 'active')
                ->first();

            if ($membership !== null && $membership->school->isActive()) {
                app(TenantContext::class)->set($membership->school);
            }
        }

        return $next($request);
    }
}
