<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService;
use App\Domain\Communications\Application\Policy\SchoolChannelPolicyService;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Infrastructure\CommunicationApprovalPolicy;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryTimingPolicy;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 5A.5 §26/§29 -- School-level channel policy administration.
 * Gated by `communications.manage` (brief §8's own capability, reused
 * rather than adding a new one). Only IN_APP and EMAIL are exposed --
 * brief §54: "do not expose SMS/WhatsApp/Push as functional preference
 * controls" applies equally to this settings screen.
 */
class CommunicationChannelPolicyController extends Controller
{
    use AuthorizesCapability;

    private const MANAGEABLE_CHANNELS = [CommunicationChannel::InApp, CommunicationChannel::Email];

    public function show(TenantContext $context, CommunicationChannelPolicyService $channelPolicy): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.manage', $school);

        $policies = [];
        foreach (self::MANAGEABLE_CHANNELS as $channel) {
            $view = $channelPolicy->policyFor($school, $channel);
            $policies[] = [
                'channel' => $channel->value,
                'optionalAllowed' => $view->optionalAllowed,
                'requiredAllowed' => $view->requiredAllowed,
                'recipientCanOptOut' => $view->recipientCanOptOut,
                'isOverride' => $view->isOverride,
            ];
        }

        // Phase 5A.9 -- read side of the Email quiet-hours settings
        // section on this same page; the write side is a separate
        // controller (CommunicationDeliveryTimingPolicyController)
        // since it has its own validation/service, mirroring how
        // channel policy and timing policy are two distinct write
        // paths despite sharing one settings page.
        $timing = CommunicationDeliveryTimingPolicy::query()
            ->where('school_id', $school->id)
            ->where('channel', CommunicationChannel::Email->value)
            ->first();

        return Inertia::render('App/Communications/Settings/Channels', [
            'policies' => $policies,
            'emailChannelEnabled' => (bool) config('communications.channels.email.enabled'),
            'timingPolicy' => [
                'channel' => CommunicationChannel::Email->value,
                'enabled' => $timing !== null && $timing->enabled,
                'quietHoursStart' => $timing?->quiet_hours_start !== null ? substr((string) $timing->quiet_hours_start, 0, 5) : null,
                'quietHoursEnd' => $timing?->quiet_hours_end !== null ? substr((string) $timing->quiet_hours_end, 0, 5) : null,
                'emergencyBypassAllowed' => $timing !== null && $timing->emergency_bypass_allowed,
            ],
            // Phase 5A.12 §41: read side of the Approval Workflow
            // settings section on this same page -- the write side is
            // a separate controller
            // (CommunicationApprovalPolicyController), mirroring the
            // channel-policy/timing-policy split above exactly.
            'approvalPolicy' => $this->presentApprovalPolicy($school),
            'schoolTimezone' => $school->timezone,
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function presentApprovalPolicy(School $school): array
    {
        $policy = CommunicationApprovalPolicy::query()->where('school_id', $school->id)->first();

        return [
            'requireSchoolWideApproval' => $policy !== null && $policy->require_school_wide_approval,
            'requireRequiredCommunicationApproval' => $policy !== null && $policy->require_required_communication_approval,
            'requireNonPrivilegedSenderApproval' => $policy !== null && $policy->require_non_privileged_sender_approval,
        ];
    }

    public function update(Request $request, TenantContext $context, SchoolChannelPolicyService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.manage', $school);

        // Brief §10/§26: IN_APP is structurally canonical --
        // App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService::evaluate()
        // never even consults a School's IN_APP policy row before
        // returning ALLOW, so accepting a write for it here would
        // create a meaningless override nothing ever reads. Only
        // `email` is a real, effective write target today.
        $validated = $request->validate([
            'channel' => ['required', 'string', Rule::in(['email'])],
            'optional_allowed' => ['required', 'boolean'],
            'required_allowed' => ['required', 'boolean'],
            'recipient_can_opt_out' => ['required', 'boolean'],
        ]);

        $service->setPolicy(
            $school,
            $context->actor(),
            CommunicationChannel::from($validated['channel']),
            (bool) $validated['optional_allowed'],
            (bool) $validated['required_allowed'],
            (bool) $validated['recipient_can_opt_out'],
        );

        return redirect('/app/communications/settings/channels');
    }
}
