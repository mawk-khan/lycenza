<?php

namespace App\Http\Controllers\App\Platform;

use App\Domain\Platform\Application\Modules\ModuleCatalogue;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlementDeniedException;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlements;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlementService;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Auth\Mfa\MfaReverificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ORG.5 (ADR 0076 §12.6, §31): enable or disable one OPTIONAL module for one
 * School. Context-neutral; no School context, no School authority. The
 * review page needs `platform.school_entitlements.manage` (route
 * middleware); the POST carries none on purpose so a refusal reaches
 * SchoolModuleEntitlementService, which authorizes, re-checks everything
 * and audits the refusal. Disabling removes access only -- never data.
 */
class PlatformSchoolModuleController extends Controller
{
    private const ACTIONS = ['enable', 'disable'];

    private const REASON_LABELS = [
        'onboarding_configuration' => 'Onboarding configuration',
        'school_request' => 'Requested by the School',
        'platform_policy' => 'Platform policy',
        'operational_correction' => 'Operational correction',
    ];

    public function __construct(
        private readonly SchoolModuleEntitlementService $service,
        private readonly SchoolModuleEntitlements $entitlements,
        private readonly MfaReverificationService $mfa,
    ) {}

    public function review(Request $request, School $school, string $module, string $action): Response
    {
        abort_unless(in_array($action, self::ACTIONS, true) && ModuleCatalogue::exists($module) && ! ModuleCatalogue::isCore($module), 404);

        $states = $this->entitlements->optionalStates($school);

        return Inertia::render('App/Platform/Schools/Module', [
            'school' => ['id' => $school->id, 'name' => $school->name, 'slug' => $school->slug, 'status' => $school->status, 'closed' => $school->isClosed()],
            'module' => [
                'key' => $module,
                'label' => ModuleCatalogue::MODULES[$module]['label'],
                'description' => ModuleCatalogue::MODULES[$module]['description'],
                'enabled' => $states[$module],
            ],
            'action' => $action,
            'missingDependencies' => array_values(array_map(
                fn (string $d): string => ModuleCatalogue::MODULES[$d]['label'],
                array_filter(ModuleCatalogue::dependenciesOf($module), fn (string $d): bool => ! ModuleCatalogue::isCore($d) && ! ($states[$d] ?? false)),
            )),
            'impact' => $action === 'disable' ? $this->impactForDisplay($school, $module) : null,
            'reasons' => array_map(fn (string $r): array => ['value' => $r, 'label' => self::REASON_LABELS[$r]], SchoolModuleEntitlementService::REASONS),
            'mfaEnrolled' => $this->mfa->hasActiveFactor($request->user()),
        ]);
    }

    public function perform(Request $request, School $school, string $module, string $action): RedirectResponse
    {
        abort_unless(in_array($action, self::ACTIONS, true), 404);

        $arguments = [$request, $request->user(), $school, $module, $request->input('reason_code'), $request->input('confirmed'), $request->input('mfa_code')];

        try {
            $action === 'enable' ? $this->service->enable(...$arguments) : $this->service->disable(...$arguments);
        } catch (SchoolModuleEntitlementDeniedException $e) {
            if ($e->getStatusCode() === 403 && $e->field !== 'mfa_code') {
                abort(403, $e->getMessage());
            }

            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return redirect()->route('app.platform.schools.show', $school)->with('lifecycleNotice', 'module-'.$action);
    }

    /** @return array{dependentsEnabled: list<string>, capabilityCount: int, activeGrants: int, navigation: list<string>} */
    private function impactForDisplay(School $school, string $module): array
    {
        $impact = $this->service->impact($school, $module);

        return [
            'dependentsEnabled' => array_map(fn (string $d): string => ModuleCatalogue::MODULES[$d]['label'], $impact['dependentsEnabled']),
            'capabilityCount' => count($impact['capabilities']),
            'activeGrants' => $impact['activeGrants'],
            'navigation' => $impact['navigation'],
        ];
    }
}
