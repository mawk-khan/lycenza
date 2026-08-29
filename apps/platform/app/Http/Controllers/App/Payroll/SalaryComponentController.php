<?php

namespace App\Http\Controllers\App\Payroll;

use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Domain\Payroll\Application\PayrollStructureReadService;
use App\Domain\Payroll\Application\SalaryComponentSummary;
use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Http\Controllers\Controller;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 9.9 -- session-authenticated Inertia page for the Salary
 * Component catalogue (earning/deduction policy shapes, never an
 * Employee-specific amount -- see `SalaryComponentSummary`'s own
 * docblock). Delegates every read/write to the SAME
 * `PayrollStructureReadService`/`PayrollStructureAdministrationService`
 * the JSON API controller (`App\Domain\Payroll\Http\Controllers\SalaryComponentController`)
 * calls, never a raw Eloquent query or a re-derived rule.
 */
class SalaryComponentController extends Controller
{
    public function index(TenantContext $context, PayrollStructureReadService $service, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $user = $context->actor();

        $components = $service->listComponents($school, $user);

        return Inertia::render('App/Payroll/Components/Index', [
            'components' => array_map(fn (SalaryComponentSummary $c) => $this->present($c), $components),
            'canManage' => $capabilities->canInSchool($user, 'payroll.structures.manage', $school),
        ]);
    }

    public function store(Request $request, TenantContext $context, PayrollStructureAdministrationService $service): RedirectResponse
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['earning', 'deduction'])],
            'liability_ledger_account_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $service->createComponent(
            $school,
            $validated['code'],
            $validated['name'],
            $validated['type'],
            $validated['liability_ledger_account_id'] ?? null,
            $context->actor(),
        );

        return redirect('/app/payroll/components');
    }

    public function deactivate(TenantContext $context, string $salaryComponent, PayrollStructureAdministrationService $service): RedirectResponse
    {
        $context->requireSchool();
        $component = SalaryComponent::query()->findOrFail($salaryComponent);
        $service->deactivateComponent($component, $context->actor());

        return redirect('/app/payroll/components');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SalaryComponentSummary $summary): array
    {
        return [
            'id' => $summary->id,
            'code' => $summary->code,
            'name' => $summary->name,
            'type' => $summary->type,
            'liabilityLedgerAccountId' => $summary->liabilityLedgerAccountId,
            'status' => $summary->status,
        ];
    }
}
