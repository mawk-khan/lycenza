<?php

namespace App\Http\Controllers\App;

use App\Domain\Communications\Application\Policy\CommunicationConsentService;
use App\Domain\Communications\Application\Policy\CommunicationDomainPreferenceService;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 5D.2 §29/§30 -- administrative recording of a Guardian's
 * domain communication preference/consent. No Guardian self-service
 * portal exists (brief §33); this is the first-generation
 * administrative surface only, reached from the Guardian detail page
 * (App\Http\Controllers\App\GuardianController::show()).
 *
 * Gated by BOTH `communications.manage` AND `guardians.manage` --
 * recording a consent decision is more sensitive than either an
 * ordinary Communication Hub setting or an ordinary Guardian-record
 * edit alone (brief §30), mirroring the layered-capability precedent
 * `hr.employees.sensitive.manage` already established in this
 * codebase (never satisfied by the base capability alone). Never a
 * role-name check.
 *
 * Only `email` is a meaningful channel today (brief §8/§38 -- never
 * `in_app`, which remains exclusively governed by the linked
 * SchoolMembership's own CommunicationPreference).
 */
class GuardianCommunicationPreferenceController extends Controller
{
    use AuthorizesCapability;

    private function authorizeManage(TenantContext $context): void
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.manage', $school);
        $this->authorizeCapability('guardians.manage', $school);
    }

    public function updatePreference(Request $request, TenantContext $context, CommunicationDomainPreferenceService $service, string $guardian): RedirectResponse
    {
        $this->authorizeManage($context);
        $school = $context->requireSchool();

        $validated = $request->validate([
            'channel' => ['required', Rule::in(['email'])],
            'enabled' => ['required', 'boolean'],
        ]);

        $model = Guardian::query()->findOrFail($guardian);

        $service->setPreferenceForGuardian(
            $school,
            $model,
            $context->actor(),
            CommunicationChannel::from($validated['channel']),
            (bool) $validated['enabled'],
        );

        return redirect("/app/guardians/{$model->id}");
    }

    public function recordConsent(Request $request, TenantContext $context, CommunicationConsentService $service, string $guardian): RedirectResponse
    {
        $this->authorizeManage($context);
        $school = $context->requireSchool();

        $validated = $request->validate([
            'channel' => ['required', Rule::in(['email'])],
            'status' => ['required', Rule::in(['granted', 'withdrawn'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $model = Guardian::query()->findOrFail($guardian);
        $channel = CommunicationChannel::from($validated['channel']);
        $actor = $context->actor();

        if ($validated['status'] === 'granted') {
            $service->recordGrantForGuardian($school, $model, $actor, $channel, source: 'admin_recorded', note: $validated['note'] ?? null);
        } else {
            $service->recordWithdrawalForGuardian($school, $model, $actor, $channel, source: 'admin_recorded', note: $validated['note'] ?? null);
        }

        return redirect("/app/guardians/{$model->id}");
    }
}
