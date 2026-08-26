<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\Policy\CommunicationConversationPolicyService;
use App\Domain\Communications\Application\Policy\SchoolConversationPolicyService;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Phase 5D.1 §16 -- School-level administration of the private
 * conversation safeguarding policy. Gated by `communications.manage`,
 * matching CommunicationChannelPolicyController's exact precedent (a
 * School-wide Communication Hub setting, not a per-actor capability).
 * No dedicated settings page exists yet in this checkpoint (deferred --
 * see the Phase 5D.1 report's "next" section); `show()` returns JSON
 * for direct/programmatic administration until a UI is wired up.
 */
class CommunicationConversationPolicyController extends Controller
{
    use AuthorizesCapability;

    public function show(TenantContext $context, CommunicationConversationPolicyService $policy): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.manage', $school);

        $view = $policy->policyFor($school);

        return response()->json([
            'allowGuardianConversations' => $view->allowGuardianConversations,
            'allowStudentConversations' => $view->allowStudentConversations,
            'isOverride' => $view->isOverride,
        ]);
    }

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
