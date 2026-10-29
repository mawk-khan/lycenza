<?php

namespace App\Http\Controllers\App;

use App\Domain\Schools\Application\SchoolPresentationService;
use App\Http\Controllers\Controller;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Navigation\NavigationPresentation;
use App\Support\Navigation\NavigationPresentationCatalogue as Catalogue;
use App\Support\Portal\PortalAvailability;
use App\Support\Presentation\BrandResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ORG.12F (ADR 0080 §11.5): the School's presentation profile -- Branding,
 * Staff navigation and (where the surface exists) Guardian portal
 * navigation. View: `school.settings.view`; change:
 * `school.settings.manage` (route middleware, and re-read under the School
 * lock by SchoolPresentationService). Presentation never changes access:
 * the editor lists code-owned items and offers order, hide (not for required
 * items) and a plain-text label -- never a capability, route or module.
 */
class SchoolPresentationController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SchoolPresentationService $presentation,
    ) {}

    public function show(Request $request, BrandResolver $brands, NavigationPresentation $navigation, CapabilityResolver $capabilities): Response
    {
        $school = $this->context->requireSchool();
        $overrides = $navigation->overrides($school);
        $audiences = PortalAvailability::isAvailable() ? Catalogue::AUDIENCES : [Catalogue::STAFF_WEB];

        $profiles = [];
        foreach ($audiences as $audience) {
            $catalogue = Catalogue::items($audience);
            $stored = $overrides[$audience] ?? [];
            $profiles[$audience] = [
                'fingerprint' => $this->presentation->navigationFingerprint($school, $audience),
                'stale' => count(array_diff_key($stored, $catalogue)),
                'items' => array_values(array_map(fn (array $item): array => [
                    'key' => $item['key'],
                    'group' => $item['group'],
                    'groupLabel' => $item['groupLabel'],
                    'defaultLabel' => $item['label'],
                    'defaultOrder' => $item['order'],
                    'hideable' => $item['hideable'],
                    'required' => $item['required'],
                    'hidden' => (bool) ($stored[$item['key']]->hidden ?? false),
                    'order' => $stored[$item['key']]->sort_order ?? $item['order'],
                    'label' => $stored[$item['key']]->label_override ?? null,
                ], $catalogue)),
            ];
        }

        return Inertia::render('App/SchoolSetup/Presentation', [
            'school' => ['name' => $school->name],
            'canManage' => in_array(SchoolPresentationService::MANAGE, $capabilities->schoolCapabilities($request->user(), $school), true),
            // Never `brand`/`navigation`: those are shared shell props (HandleInertiaRequests).
            'brandProfile' => [...$brands->forSchool($school), 'fingerprint' => $this->presentation->brandFingerprint($school)],
            'brandSources' => $this->presentation->eligibleBrandSources($school),
            'profiles' => $profiles,
        ]);
    }

    public function updateNavigation(Request $request, string $audience): RedirectResponse
    {
        $data = $request->validate([
            'fingerprint' => ['required', 'string'],
            'items' => ['present', 'array', 'max:200'],
        ]);
        $this->presentation->saveNavigation($request->user(), $this->context->requireSchool(), $audience, array_values($data['items']), $data['fingerprint']);

        return back()->with('status', 'Navigation saved. Permissions are unchanged.');
    }

    public function resetNavigation(Request $request, string $audience): RedirectResponse
    {
        $data = $request->validate(['fingerprint' => ['required', 'string']]);
        $this->presentation->resetNavigation($request->user(), $this->context->requireSchool(), $audience, $data['fingerprint']);

        return back()->with('status', 'Navigation reset to the defaults.');
    }

    public function updateBrand(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fingerprint' => ['required', 'string'],
            'brand_source_group_id' => ['nullable', 'uuid'],
            'display_label' => ['nullable', 'string', 'max:200'],
            'primary_accent' => ['nullable', 'string', 'max:20'],
        ]);
        $this->presentation->saveBrand($request->user(), $this->context->requireSchool(), $data['brand_source_group_id'] ?? null,
            $data['display_label'] ?? null, $data['primary_accent'] ?? null, $data['fingerprint']);

        return back()->with('status', 'Branding saved.');
    }
}
