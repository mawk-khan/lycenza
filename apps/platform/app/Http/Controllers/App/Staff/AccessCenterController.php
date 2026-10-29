<?php

namespace App\Http\Controllers\App\Staff;

use App\Domain\Identity\Application\Access\AccessCatalogueReader;
use App\Domain\Identity\Application\Access\EffectiveAccessReader;
use App\Domain\Identity\Application\Staff\StaffRoleCatalog;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ORG.12E (ADR 0080 §10.7): the School Access Center -- read-only, in the
 * selected School's context.
 *
 * - Role templates, capabilities by module, the role × module matrix and the
 *   sensitive-data catalogue: `school.members.view` or `school.roles.view`
 *   (route middleware). Code-owned catalogue only -- no School record.
 * - Users & assignments and one person's effective access:
 *   `school.roles.view` (route middleware, SR.2's "role assignments" view).
 *   THIS School's staff only; a membership of another School is a 404.
 * - Nothing here mutates. Granting or revoking a School role stays on Staff
 *   accounts (StaffAccountController: RoleGrantAuthority, fresh MFA, audit);
 *   Group and platform roles are never shown or managed here.
 */
class AccessCenterController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request, AccessCatalogueReader $catalogue, StaffRoleCatalog $staff): Response
    {
        $school = $this->context->requireSchool();

        return Inertia::render('App/AccessCenter/Index', [
            ...$catalogue->catalogue(),
            'canViewAssignments' => $staff->canViewRoles($request->user(), $school),
        ]);
    }

    public function users(Request $request, EffectiveAccessReader $reader): Response
    {
        $school = $this->context->requireSchool();
        $search = is_string($request->query('q')) ? (string) $request->query('q') : '';

        return Inertia::render('App/AccessCenter/Users', [
            'filters' => ['q' => $search],
            'members' => $reader->assignments($school, $search, (int) $request->query('page', 1))->withQueryString(),
        ]);
    }

    public function show(string $membership, EffectiveAccessReader $reader): Response
    {
        $access = $reader->forMembership($this->context->requireSchool(), $membership);
        abort_if($access === null, 404);

        return Inertia::render('App/AccessCenter/Show', $access);
    }
}
