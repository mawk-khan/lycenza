<?php

namespace App\Http\Controllers\App\Fees;

use App\Domain\Library\Application\LibraryFinePolicyService;
use App\Domain\Library\Application\LibraryFineService;
use App\Domain\Library\Infrastructure\LibraryFine;
use App\Domain\Library\Infrastructure\LibraryFinePolicy;
use App\Domain\Library\Infrastructure\LibraryFineVoid;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Http\Controllers\App\Concerns\MapsDomainRefusals;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SWC.3 (ADR 0073 section 19): Library fines on the web, over the OPF.4
 * services `/api/v1` uses (ADR 0067 §30) -- the fine policy (current
 * version, history, publish) and ONE loan's fine (view, void). There is no
 * School-wide fine list (owner decision X2): a fine is seen in its loan's
 * context. Amounts are the stored NUMERIC strings, never recomputed here.
 *
 * Duplicate submissions (rule 29, X5):
 *   - publish carries the version the form was based on; a repeat or stale
 *     publish is refused under the per-School advisory lock
 *     (LibraryFinePolicyChangedException), and `library_fine_policies_one_version`
 *     keeps one row per version, so there is never a duplicate or second
 *     current policy;
 *   - void is a one-way state transition guarded by the loan row lock and
 *     `library_fine_voids_one_per_fine`: a repeat is "already voided".
 *
 * Production use of Library fines remains gated by E34 (ADR 0058), exactly
 * as before; this surface changes no gate.
 */
class LibraryFineWebController extends Controller
{
    use AuthorizesCapability, MapsDomainRefusals;

    public function __construct(
        private readonly OperationalFeePanels $panels,
        private readonly CapabilityResolver $capabilities,
    ) {}

    public function policy(TenantContext $context, LibraryFinePolicyService $policies): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.fines.view', $school);

        $directory = $this->panels->directory($school);
        $versions = $policies->versions($school);
        $canManage = $this->can($school, 'library.fines.manage');

        return Inertia::render('App/Library/FinePolicy', [
            'current' => $versions->isEmpty() ? null : $this->presentPolicy($versions->first(), $directory),
            'versions' => $versions->map(fn (LibraryFinePolicy $p) => $this->presentPolicy($p, $directory))->values()->all(),
            'options' => $this->panels->feeHeadOptions($school, $canManage),
            'canManage' => $canManage,
        ]);
    }

    public function publish(Request $request, TenantContext $context, LibraryFinePolicyService $policies): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.fines.manage', $school);

        $validated = $request->validate([
            // The version the form showed as current (0 = none yet): the X5 guard.
            'expected_version' => ['required', 'integer', 'min:0'],
            'status' => ['required', Rule::in([LibraryFinePolicy::STATUS_ACTIVE, LibraryFinePolicy::STATUS_DISABLED])],
            'fee_head_id' => ['required_if:status,active', 'nullable', 'uuid'],
            'daily_rate' => ['required_if:status,active', 'nullable', 'string', 'max:16'],
            'grace_days' => ['required_if:status,active', 'nullable', 'integer'],
            'max_amount' => ['nullable', 'string', 'max:16'],
        ]);
        $data = $validated;
        unset($data['expected_version']);
        if (isset($data['grace_days'])) {
            $data['grace_days'] = (int) $data['grace_days'];
        }

        $this->withFormErrors('policy', fn () => $policies->publish($school, $data, $context->actor(), (int) $validated['expected_version']));

        return redirect()->route('app.library.fine-policy');
    }

    public function loan(TenantContext $context, string $libraryLoan): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.circulation.view', $school);

        $loan = LibraryLoan::query()->with(['copy.title', 'student'])->findOrFail($libraryLoan);
        $canViewFines = $this->can($school, 'library.fines.view');
        $fine = $canViewFines ? LibraryFine::query()->where('library_loan_id', $loan->id)->first() : null;

        return Inertia::render('App/Library/Circulation/Show', [
            'loan' => [
                'id' => $loan->id,
                'titleName' => $loan->copy->title->title,
                'copyCode' => $loan->copy->code,
                'studentName' => trim($loan->student->first_name.' '.$loan->student->last_name),
                // UAT-LIB-01: calendar dates in the School's timezone.
                'checkedOutAt' => $loan->checked_out_at->copy()->setTimezone(SchoolTimezone::resolve($school))->toDateString(),
                'dueAt' => $loan->due_at->copy()->setTimezone(SchoolTimezone::resolve($school))->toDateString(),
                'returnedAt' => $loan->checked_in_at?->copy()->setTimezone(SchoolTimezone::resolve($school))->toDateString(),
                'status' => $loan->status,
            ],
            'fine' => $fine === null ? null : $this->presentFine($school, $fine),
            'canViewFines' => $canViewFines,
            'canVoid' => $canViewFines && $this->can($school, 'library.fines.void'),
        ]);
    }

    public function void(Request $request, TenantContext $context, LibraryFineService $fines, string $libraryFine): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.fines.void', $school);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $fine = $this->withFormErrors('reason', fn () => $fines->void($school, $libraryFine, $validated['reason'], $context->actor()));

        return redirect()->route('app.library.circulation.show', $fine->library_loan_id);
    }

    /**
     * @param  array<string, array{id: string, code: string, name: string, active: bool}>  $directory
     * @return array<string, mixed>
     */
    private function presentPolicy(LibraryFinePolicy $policy, array $directory): array
    {
        return [
            'id' => $policy->id,
            'version' => $policy->version,
            'status' => $policy->status,
            'feeHead' => $policy->fee_head_id === null ? null : ($directory[$policy->fee_head_id] ?? null),
            'dailyRate' => $policy->daily_rate,
            'graceDays' => $policy->grace_days,
            'maxAmount' => $policy->max_amount,
            'currency' => $policy->currency,
            'publishedAt' => $policy->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentFine(School $school, LibraryFine $fine): array
    {
        $void = LibraryFineVoid::query()->where('library_fine_id', $fine->id)->first();
        $policy = LibraryFinePolicy::query()->find($fine->library_fine_policy_id);

        return [
            'id' => $fine->id,
            'amount' => $fine->amount,
            'currency' => $fine->currency,
            'overdueDays' => $fine->overdue_days,
            'chargeableDays' => $fine->chargeable_days,
            'policyVersion' => $policy?->version,
            'feeHead' => $this->panels->directory($school)[$fine->fee_head_id] ?? null,
            'status' => $void === null ? 'assessed' : 'voided',
            'assessedAt' => $fine->created_at?->toIso8601String(),
            'voidedAt' => $void?->created_at?->toIso8601String(),
            'voidReason' => $void?->reason,
        ];
    }

    private function can(School $school, string $capability): bool
    {
        return $this->capabilities->canInSchool(app(TenantContext::class)->actor(), $capability, $school);
    }
}
