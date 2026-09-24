<?php

namespace App\Http\Controllers\App\Automation;

use App\Domain\Automation\Application\AutomationReadService;
use App\Domain\Automation\Application\AutomationRuleService;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0L.6 -- the Automation page (ADR 0043). Thin: the School is the
 * trusted session context (none selected -> 403, no implicit platform or
 * cross-School access); `automation.view` is checked by
 * AutomationReadService, `automation.manage` by AutomationRuleService for
 * every mutation. The rule type comes from the URL and must be in the code
 * catalog (404 otherwise). No rule builder, no manual run.
 *
 * The three mutations are idempotent by construction (they set a state,
 * so a retried duplicate changes nothing), so they do not use the
 * `idempotent` middleware (CLAUDE.md rule 29 reviewed).
 */
class AutomationController extends Controller
{
    public function index(Request $request, AutomationReadService $read): Response
    {
        return Inertia::render('App/Automation/Index', [
            'automation' => $read->overview($this->school(), $request->user()),
        ]);
    }

    public function enable(Request $request, string $ruleType, AutomationRuleService $rules): RedirectResponse
    {
        $rules->enable($this->school(), $ruleType, $request->user());

        return redirect('/app/automation');
    }

    public function disable(Request $request, string $ruleType, AutomationRuleService $rules): RedirectResponse
    {
        $rules->disable($this->school(), $ruleType, $request->user());

        return redirect('/app/automation');
    }

    public function takeOwnership(Request $request, string $ruleType, AutomationRuleService $rules): RedirectResponse
    {
        $rules->takeOwnership($this->school(), $ruleType, $request->user());

        return redirect('/app/automation');
    }

    private function school(): School
    {
        $school = app(TenantContext::class)->school();
        abort_if($school === null, 403);

        return $school;
    }
}
