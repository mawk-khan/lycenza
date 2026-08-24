<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\Policy\SchoolDeliveryTimingPolicyService;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;

/**
 * Phase 5A.9 -- write side of the Email quiet-hours settings section
 * on the SAME page CommunicationChannelPolicyController renders
 * (`/app/communications/settings/channels`) -- a separate controller
 * only because this is a genuinely distinct write path/validation
 * shape, same reasoning SchoolChannelPolicyService/
 * CommunicationChannelPolicyService already split read vs write.
 * Gated by `communications.manage`, the same capability every other
 * Communication Hub administrative surface uses (brief §47's own
 * precedent, applied here).
 */
class CommunicationDeliveryTimingPolicyController extends Controller
{
    use AuthorizesCapability;

    public function update(Request $request, TenantContext $context, SchoolDeliveryTimingPolicyService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.manage', $school);

        $validated = $request->validate([
            'channel' => ['required', 'string', Rule::in(['email'])],
            'enabled' => ['required', 'boolean'],
            // Brief §13: reject an equal start/end rather than silently
            // interpreting it as a 24-hour block; only required when
            // the policy is being enabled.
            'quiet_hours_start' => [new RequiredIf((bool) $request->boolean('enabled')), 'nullable', 'date_format:H:i'],
            'quiet_hours_end' => [new RequiredIf((bool) $request->boolean('enabled')), 'nullable', 'date_format:H:i', 'different:quiet_hours_start'],
            // Phase 5A.10 §37: defaults to false whenever omitted --
            // this endpoint can never be used to silently turn bypass
            // on for a School that didn't explicitly request it.
            'emergency_bypass_allowed' => ['sometimes', 'boolean'],
        ]);

        $service->setPolicy(
            $school,
            $context->actor(),
            CommunicationChannel::from($validated['channel']),
            (bool) $validated['enabled'],
            $validated['quiet_hours_start'] ?? null,
            $validated['quiet_hours_end'] ?? null,
            (bool) ($validated['emergency_bypass_allowed'] ?? false),
        );

        return redirect('/app/communications/settings/channels');
    }
}
