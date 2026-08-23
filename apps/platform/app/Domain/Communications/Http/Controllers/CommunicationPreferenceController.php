<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\Policy\CommunicationPreferenceService;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Infrastructure\CommunicationPreference;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 5A.5 §27/§29/§30 -- the current authenticated actor's OWN
 * preference for their current active School membership. Self-service
 * only: `show()`/`update()` always resolve `$membership` from
 * `$context->actor()` + the current School, exactly like
 * App\Http\Middleware\ResolveSchoolContext's own membership lookup --
 * never from a client-supplied membership id. There is no
 * administrative override path here (brief §30).
 *
 * Deliberately NOT gated by a `communications.*` capability -- unlike
 * every other action in this module, this one is not "Communication
 * Hub privilege," it is "control your own notification settings,"
 * which every active member of a School has regardless of role
 * (root CLAUDE.md rule 24 cuts the other way here: a role-based
 * capability check would be the wrong tool, not the right one, for a
 * setting that is inherently self-scoped and cannot affect anyone
 * else). `currentMembership()`'s own 403 is the real gate: only a
 * genuine active member of the current School can reach this at all.
 */
class CommunicationPreferenceController extends Controller
{
    public function show(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $membership = $this->currentMembership($context, $school);

        $preference = CommunicationPreference::query()
            ->where('school_membership_id', $membership->id)
            ->where('channel', CommunicationChannel::Email->value)
            ->first();

        return Inertia::render('App/Communications/Preferences', [
            // null means "inherit" -- brief §12/§14: row absence is
            // never rendered as a false "Off" toggle.
            'emailPreference' => $preference?->preference,
            'emailChannelEnabled' => (bool) config('communications.channels.email.enabled'),
        ]);
    }

    public function update(Request $request, TenantContext $context, CommunicationPreferenceService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $membership = $this->currentMembership($context, $school);

        // Brief §13: only `email` is a real, storable preference today
        // -- in_app (canonical, brief §10) and every unimplemented
        // channel are rejected here, never silently accepted.
        $validated = $request->validate([
            'channel' => ['required', 'string', Rule::in(['email'])],
            'enabled' => ['required', 'boolean'],
        ]);

        $service->setPreference(
            $membership,
            $context->actor(),
            CommunicationChannel::from($validated['channel']),
            (bool) $validated['enabled'],
        );

        return redirect('/app/communications/preferences');
    }

    private function currentMembership(TenantContext $context, School $school): SchoolMembership
    {
        $membership = SchoolMembership::query()
            ->where('user_id', $context->actor()->id)
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->first();

        abort_if($membership === null, 403);

        return $membership;
    }
}
