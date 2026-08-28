<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\PositionService;
use App\Domain\HR\Infrastructure\Position;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\NormalizesCodeInput;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class HrPositionController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.positions.view', $school);

        return Inertia::render('App/HR/Positions/Index', [
            'positions' => Position::query()->orderBy('name')->get(['id', 'name', 'code', 'status'])->all(),
            'canManage' => app(CapabilityResolver::class)->canInSchool($context->actor(), 'hr.positions.manage', $school),
        ]);
    }

    public function store(Request $request, TenantContext $context, PositionService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.positions.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', Rule::unique('positions', 'code')->where('school_id', $school->id)],
        ]);

        $service->create($school, $validated, $context->actor());

        return redirect('/app/hr/positions');
    }

    public function archive(TenantContext $context, PositionService $service, string $position): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.positions.manage', $school);
        abort_if(! Str::isUuid($position), 404);

        $service->archive(Position::query()->findOrFail($position), $context->actor());

        return redirect('/app/hr/positions');
    }

    public function reactivate(TenantContext $context, PositionService $service, string $position): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.positions.manage', $school);
        abort_if(! Str::isUuid($position), 404);

        $service->reactivate(Position::query()->findOrFail($position), $context->actor());

        return redirect('/app/hr/positions');
    }
}
