<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\Policy\SchoolConversationPolicyService;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Phase 5D.1 §16 -- School-level administration of the private
 * conversation safeguarding policy. Gated by `communications.manage`,
 * matching CommunicationChannelPolicyController's exact precedent (a
 * School-wide Communication Hub setting, not a per-actor capability).
 * The write-only half of the channel-policy/timing-policy/approval-
 * policy split this settings page already established -- READ goes
 * through CommunicationChannelPolicyController::show()'s aggregated
 * Inertia payload (Phase 5D.1b §16), not a separate endpoint here.
 */
class CommunicationConversationPolicyController extends Controller
{
    use AuthorizesCapability;

    public function update(Request $request, TenantContext $context, SchoolConversationPolicyService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.manage', $school);

        $validated = $request->validate([
            'allow_guardian_conversations' => ['required', 'boolean'],
            'allow_student_conversations' => ['required', 'boolean'],
        ]);

        $service->setPolicy(
            $school,
            $context->actor(),
            (bool) $validated['allow_guardian_conversations'],
            (bool) $validated['allow_student_conversations'],
        );

        return redirect('/app/communications/settings/channels');
    }
}
