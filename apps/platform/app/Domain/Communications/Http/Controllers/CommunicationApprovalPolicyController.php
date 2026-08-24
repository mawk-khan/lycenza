<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\Approval\SchoolApprovalPolicyService;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Phase 5A.12 §41 -- write side of the Approval Workflow section on
 * the Communication settings page. `communications.manage`-gated,
 * matching CommunicationChannelPolicyController/
 * CommunicationDeliveryTimingPolicyController exactly -- approval
 * POLICY administration is a School-governance action, distinct from
 * `communications.approve` (the capability to DECIDE an individual
 * request, brief §12).
 */
class CommunicationApprovalPolicyController extends Controller
{
    use AuthorizesCapability;

    public function update(Request $request, TenantContext $context, SchoolApprovalPolicyService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.manage', $school);

        $validated = $request->validate([
            'require_school_wide_approval' => ['required', 'boolean'],
            'require_required_communication_approval' => ['required', 'boolean'],
            'require_non_privileged_sender_approval' => ['required', 'boolean'],
        ]);

        $service->setPolicy(
            $school,
            $context->actor(),
            (bool) $validated['require_school_wide_approval'],
            (bool) $validated['require_required_communication_approval'],
            (bool) $validated['require_non_privileged_sender_approval'],
        );

        return redirect('/app/communications/settings/channels');
    }
}
