<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import MfaCodeField from '../../../../Components/MfaCodeField.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';
import { formatDateTime } from '../../../../datetime';
import { formatMoney } from '../../../../money';
import { idempotencyKey, clearIdempotencyKey } from '../../../../idempotency';

interface Run {
    id: string;
    payrollPeriodId: string;
    runKind: 'regular' | 'correction';
    correctsPayrollRunId: string | null;
    status: 'draft' | 'calculated' | 'approved' | 'posted';
    preparedByUserId: string;
    preparedByName: string | null;
    approvedByUserId: string | null;
    approvedByName: string | null;
    postedByUserId: string | null;
    postedByName: string | null;
    approvedAt: string | null;
    postedAt: string | null;
    isReversed: boolean;
    resultsExpiredAt: string | null;
}

interface Posting {
    id: string;
    postingKind: 'original' | 'reversal';
    journalEntryId: string;
    reversalOfPayrollRunPostingId: string | null;
    reason: string | null;
}

interface ResultRow {
    employmentRecordId: string;
    employeeId: string;
    employeeFullName: string | null;
    employeeNumber: string | null;
    grossAmount: string;
    totalDeductions: string;
    netAmount: string;
}

interface UnresolvedEmployee {
    employmentRecordId: string;
    employeeId: string;
    employeeFullName: string | null;
    employeeNumber: string | null;
}

interface Component {
    id: string;
    code: string;
    name: string;
    type: 'earning' | 'deduction';
}

interface AccountingReadiness {
    configured: boolean;
    salaryExpenseLedgerAccountLabel: string | null;
    salaryPayableLedgerAccountLabel: string | null;
    missingDeductionMappings: string[];
    readyToPost: boolean;
}

interface Props {
    run: Run;
    postings: Posting[];
    canViewSensitive: boolean;
    sensitiveNeedsMfa: boolean;
    hasMfaFactor: boolean;
    results: ResultRow[] | null;
    calculationOutcome: {
        resolvedCount: number;
        unresolvedEmploymentRecordIds: string[];
        transitionedToCalculated: boolean;
    } | null;
    unresolvedEmployees: UnresolvedEmployee[];
    correctionCandidates: Array<{ employmentRecordId: string; employeeFullName: string | null }>;
    openPeriods: Array<{ id: string; periodMonth: string }>;
    components: Component[];
    canPrepare: boolean;
    canApprove: boolean;
    canReview: boolean;
    isSelfPrepared: boolean;
    canPost: boolean;
    accountingReadiness: AccountingReadiness | null;
    canReverse: boolean;
    postingIndependence: {
        phase: 'before_posting' | 'after_posting' | 'attested';
        blockedReason: 'not_independent' | 'cannot_review' | 'needs_mfa' | null;
        evidenceVersion: string | null;
        attestation: { attestedBy: string | null; attestedAt: string | null } | null;
        details: {
            approvedBy: string | null;
            reversedRuns: { id: string; periodMonth: string; reversedAt: string }[];
            changes: {
                total: number;
                page: number;
                lastPage: number;
                data: {
                    key: string;
                    employeeNumber: string;
                    employee: string;
                    effectiveFrom: string;
                    structure: string;
                }[];
            };
        } | null;
    } | null;
    hrxInputs: HrxInputs | null;
}

/** HRX.5 -- integer half-day units of HRX absence EVIDENCE; never a deduction or NCP figure. */
interface HrxUnits {
    requiredWorkingHalfUnits: number | null;
    approvedPaidLeaveHalfUnits: number;
    approvedUnpaidLeaveHalfUnits: number;
    recordedAbsenceHalfUnits: number;
    recordedPresenceHalfUnits: number;
    unresolvedWorkingHalfUnits: number;
}

interface HrxInputs {
    contractVersion: string;
    payrollPolicy: string;
    frozen: boolean;
    differenceState: 'none' | 'source_changed_after_approval' | 'recalculate_to_refresh';
    inputs: Array<{
        employmentRecordId: string;
        employee: { employeeNumber: string | null; fullName: string | null };
        fingerprint: string;
        completeness: 'complete' | 'input_incomplete';
        incompleteReasons: string[];
        coveredFrom: string | null;
        coveredTo: string | null;
        units: HrxUnits;
        sourceChanged: boolean;
        current: { fingerprint: string; units: HrxUnits };
    }>;
}

const props = defineProps<Props>();

const checkingHrx = ref(false);
function checkHrxDifferences(): void {
    checkingHrx.value = true;
    router.post(
        `/app/payroll/runs/${props.run.id}/hrx-inputs/difference-checks`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (checkingHrx.value = false),
        },
    );
}

// Phase 9.10 -- a payslip is only ever eligible once the run has
// crossed the SAME immutability boundary PayslipReadService itself
// enforces server-side (approved/posted); below that, the table below
// is an editable, recalculable "Calculation preview", never a payslip.
const isFinal = computed(() => props.run.status === 'approved' || props.run.status === 'posted');

const calculating = ref(false);
function calculate(): void {
    calculating.value = true;
    router.post(
        `/app/payroll/runs/${props.run.id}/calculate`,
        {},
        { onFinish: () => (calculating.value = false) },
    );
}

// -- Manual override (partial-period) ----------------------------------

const overrideTarget = ref<string | null>(null);
const overrideForm = reactive<Record<string, { amounts: Record<string, string>; reason: string }>>(
    {},
);

function startOverride(employmentRecordId: string): void {
    overrideTarget.value = employmentRecordId;
    overrideForm[employmentRecordId] ??= { amounts: {}, reason: '' };
}

const overrideSubmitting = ref(false);
function submitOverride(employmentRecordId: string): void {
    const state = overrideForm[employmentRecordId];
    overrideSubmitting.value = true;
    router.post(
        `/app/payroll/runs/${props.run.id}/manual-overrides`,
        {
            employment_record_id: employmentRecordId,
            component_amounts: state.amounts,
            reason: state.reason,
        },
        {
            onFinish: () => (overrideSubmitting.value = false),
            onSuccess: () => (overrideTarget.value = null),
        },
    );
}

// -- Correction delta ----------------------------------------------------

const correctionDeltaForm = useForm({
    employment_record_id: '',
    lines: [{ salary_component_id: '', amount: '', effect: 'increase' as 'increase' | 'decrease' }],
    reason: '',
});

function addDeltaLine(): void {
    correctionDeltaForm.lines.push({ salary_component_id: '', amount: '', effect: 'increase' });
}

function submitCorrectionDelta(): void {
    correctionDeltaForm.post(`/app/payroll/runs/${props.run.id}/correction-deltas`, {
        onSuccess: () => correctionDeltaForm.reset(),
    });
}

// SR.4 (ADR 0071 §26.7): approve, post and reverse each need a fresh code.
const mfaCode = ref('');
const mfaError = ref<string | null>(null);
function mfaOptions(scope: string) {
    return {
        onSuccess: () => {
            clearIdempotencyKey(scope);
            mfaCode.value = '';
            mfaError.value = null;
        },
        onError: (errors: Record<string, string>) => {
            mfaError.value = errors.mfa_code ?? null;
            mfaCode.value = '';
        },
    };
}

// -- Post -------------------------------------------------------------------

const posting = ref(false);
// HR.R1-C4 (PAY-16): never ticked for the user, and never implied by pressing Post.
const attestationAccepted = ref(false);
const postRefusal = ref<string | null>(null);
const independence = computed(() => props.postingIndependence);
const needsAttestation = computed(() => independence.value?.phase === 'before_posting');
const mayPost = computed(
    () =>
        !needsAttestation.value ||
        (independence.value?.blockedReason === null && attestationAccepted.value),
);
function post(): void {
    if (!window.confirm('Post this payroll run to Finance? This creates a real journal entry.'))
        return;

    posting.value = true;
    postRefusal.value = null;
    const scope = `payroll-post-${props.run.id}`;
    const key = idempotencyKey(scope);
    const options = mfaOptions(scope);
    router.post(
        `/app/payroll/runs/${props.run.id}/post`,
        {
            mfa_code: mfaCode.value,
            ...(needsAttestation.value && independence.value?.evidenceVersion
                ? {
                      attestation_version: independence.value.evidenceVersion,
                      attestation_accepted: attestationAccepted.value,
                  }
                : {}),
        },
        {
            headers: { 'Idempotency-Key': key },
            ...options,
            onError: (errors: Record<string, string>) => {
                options.onError(errors);
                postRefusal.value = errors.payroll ?? null;
                clearIdempotencyKey(scope);
                attestationAccepted.value = false;
                // A refusal may mean the evidence changed: show the current evidence, never the old.
                router.reload({ only: ['postingIndependence'] });
            },
            onFinish: () => (posting.value = false),
        },
    );
}

// -- Reverse ------------------------------------------------------------------

const showReverseForm = ref(false);
const reverseReason = ref('');
const reversing = ref(false);
function reverse(): void {
    if (
        !window.confirm(
            'Reverse this posting? This creates a NEW, inverse journal entry -- the original is preserved.',
        )
    )
        return;

    reversing.value = true;
    const key = idempotencyKey(`payroll-reverse-${props.run.id}`);
    router.post(
        `/app/payroll/runs/${props.run.id}/reverse`,
        { reason: reverseReason.value || undefined, mfa_code: mfaCode.value },
        {
            headers: { 'Idempotency-Key': key },
            ...mfaOptions(`payroll-reverse-${props.run.id}`),
            onFinish: () => (reversing.value = false),
        },
    );
}

// -- Create correction run ------------------------------------------------

const showCorrectionForm = ref(false);
const correctionPeriodId = ref('');
const creatingCorrection = ref(false);
function createCorrection(): void {
    creatingCorrection.value = true;
    const key = idempotencyKey(`payroll-create-correction-${props.run.id}`);
    router.post(
        `/app/payroll/runs/${props.run.id}/correction`,
        { payroll_period_id: correctionPeriodId.value },
        {
            headers: { 'Idempotency-Key': key },
            onSuccess: () => clearIdempotencyKey(`payroll-create-correction-${props.run.id}`),
            onFinish: () => (creatingCorrection.value = false),
        },
    );
}

const originalPosting = computed(
    () => props.postings.find((p) => p.postingKind === 'original') ?? null,
);
const reversalPosting = computed(
    () => props.postings.find((p) => p.postingKind === 'reversal') ?? null,
);
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/payroll/periods">← Periods &amp; Runs</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">
                    {{ run.runKind === 'regular' ? 'Regular payroll run' : 'Correction run' }}
                </h1>
                <p v-if="run.correctsPayrollRunId" class="mt-1 text-sm text-ink-muted">
                    Corrects
                    <a class="underline" :href="`/app/payroll/runs/${run.correctsPayrollRunId}`"
                        >another run</a
                    >
                    -- the original remains immutable and unaffected.
                </p>
            </div>
            <div class="flex gap-2">
                <StatusBadge :status="run.status" />
                <StatusBadge v-if="run.isReversed" status="reversed" />
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
            <dt class="text-ink-muted">Prepared by</dt>
            <dd>{{ run.preparedByName ?? '—' }}</dd>
            <dt class="text-ink-muted">Approved by</dt>
            <dd>{{ run.approvedByName ?? '—' }}</dd>
            <dt class="text-ink-muted">Posted by</dt>
            <dd>{{ run.postedByName ?? '—' }}</dd>
            <dt class="text-ink-muted">Posted at</dt>
            <dd>{{ run.postedAt ?? '—' }}</dd>
        </dl>

        <p
            v-if="run.resultsExpiredAt"
            class="mt-4 rounded-xs border border-line bg-graphite-100 p-3 text-sm text-ink-muted"
        >
            Under the retention policy, the results of separated staff whose retention period ended
            were deleted from this run. Its Finance posting and totals are unchanged; the remaining
            results and statutory exports no longer cover the whole run.
        </p>

        <!-- Postings / Finance linkage -->
        <section v-if="postings.length > 0" class="mt-6 border-t border-line pt-4">
            <h2 class="text-sm font-medium text-ink">Finance posting</h2>
            <p v-if="originalPosting" class="mt-1 text-sm text-ink-muted">
                Original journal entry:
                <span class="font-mono text-xs">{{ originalPosting.journalEntryId }}</span>
            </p>
            <p v-if="reversalPosting" class="mt-1 text-sm text-ink">
                Reversed by journal entry
                <span class="font-mono text-xs">{{ reversalPosting.journalEntryId }}</span>
                <span v-if="reversalPosting.reason"> — {{ reversalPosting.reason }}</span>
            </p>
        </section>

        <!-- Calculate -->
        <section v-if="canPrepare && run.status === 'draft'" class="mt-6 border-t border-line pt-4">
            <h2 class="text-sm font-medium text-ink">Calculation</h2>
            <button
                type="button"
                :disabled="calculating"
                class="btn btn-primary mt-2"
                @click="calculate"
            >
                {{ calculating ? 'Calculating…' : 'Calculate' }}
            </button>

            <div v-if="calculationOutcome" class="mt-4 rounded-xs border border-line p-4 text-sm">
                <p>{{ calculationOutcome.resolvedCount }} EmploymentRecord(s) resolved.</p>
                <p
                    v-if="calculationOutcome.transitionedToCalculated"
                    class="mt-1 font-medium text-ink"
                >
                    Every eligible EmploymentRecord was resolved -- this run is now Calculated and
                    ready for review/approval.
                </p>
                <p v-else class="mt-1 font-medium text-ink">
                    Manual payroll input required for
                    {{ unresolvedEmployees.length }} EmploymentRecord(s) before this run can be
                    calculated. The run stays in draft until every one is resolved.
                </p>
            </div>

            <div v-if="unresolvedEmployees.length > 0" class="mt-4 space-y-3">
                <div
                    v-for="e in unresolvedEmployees"
                    :key="e.employmentRecordId"
                    class="rounded-xs border border-accent-300 bg-accent-100 p-4"
                >
                    <p class="text-sm font-medium text-ink">
                        Manual payroll input required —
                        {{ e.employeeFullName ?? 'Employee record unavailable' }}
                        <span v-if="e.employeeNumber" class="text-xs text-ink"
                            >({{ e.employeeNumber }})</span
                        >
                    </p>
                    <p class="mt-1 text-xs text-ink">
                        This EmploymentRecord had a mid-period hire, termination, or compensation
                        change -- the exact eligible amounts for each component must be entered
                        directly. No proration is computed automatically.
                    </p>

                    <button
                        v-if="overrideTarget !== e.employmentRecordId"
                        type="button"
                        class="mt-2 rounded-xs border border-accent-300 px-2 py-1 text-xs font-medium text-ink"
                        @click="startOverride(e.employmentRecordId)"
                    >
                        Record manual result
                    </button>

                    <div v-else class="mt-3 space-y-2">
                        <div v-for="c in components" :key="c.id" class="flex items-center gap-2">
                            <label class="w-48 text-xs text-ink-muted"
                                >{{ c.code }} — {{ c.name }} ({{ c.type }})</label
                            >
                            <input
                                v-model="overrideForm[e.employmentRecordId].amounts[c.id]"
                                type="text"
                                placeholder="0.00"
                                class="input w-32"
                            />
                        </div>
                        <div>
                            <label class="block text-xs text-ink-muted">Reason</label>
                            <input
                                v-model="overrideForm[e.employmentRecordId].reason"
                                type="text"
                                maxlength="1000"
                                class="input mt-1"
                            />
                        </div>
                        <button
                            type="button"
                            :disabled="overrideSubmitting"
                            class="btn btn-primary text-xs"
                            @click="submitOverride(e.employmentRecordId)"
                        >
                            {{ overrideSubmitting ? 'Saving…' : 'Save manual result' }}
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Correction delta entry -->
        <section
            v-if="canPrepare && run.runKind === 'correction' && run.status === 'draft'"
            class="mt-6 border-t border-line pt-4"
        >
            <h2 class="text-sm font-medium text-ink">Correction deltas</h2>
            <p class="mt-1 text-xs text-ink-muted">
                Each line is a signed delta against one component -- an increase or a decrease,
                never an absolute replacement value. A correction with zero net effect is rejected.
            </p>

            <form class="mt-3 space-y-3" @submit.prevent="submitCorrectionDelta">
                <div>
                    <label class="block text-sm text-ink-muted">EmploymentRecord</label>
                    <select
                        v-model="correctionDeltaForm.employment_record_id"
                        class="input mt-1 max-w-md"
                    >
                        <option value="" disabled>Select…</option>
                        <option
                            v-for="c in correctionCandidates"
                            :key="c.employmentRecordId"
                            :value="c.employmentRecordId"
                        >
                            {{ c.employeeFullName ?? 'Employee record unavailable' }}
                        </option>
                    </select>
                    <p
                        v-if="correctionDeltaForm.errors.employment_record_id"
                        class="mt-1 text-sm text-danger"
                    >
                        {{ correctionDeltaForm.errors.employment_record_id }}
                    </p>
                </div>

                <div
                    v-for="(line, i) in correctionDeltaForm.lines"
                    :key="i"
                    class="flex flex-wrap items-center gap-2"
                >
                    <select v-model="line.salary_component_id" class="input w-auto">
                        <option value="" disabled>Component…</option>
                        <option v-for="c in components" :key="c.id" :value="c.id">
                            {{ c.code }} — {{ c.name }}
                        </option>
                    </select>
                    <select v-model="line.effect" class="input w-auto">
                        <option value="increase">Increase</option>
                        <option value="decrease">Decrease</option>
                    </select>
                    <input
                        v-model="line.amount"
                        type="text"
                        placeholder="0.00"
                        class="input w-28"
                    />
                </div>
                <button type="button" class="text-xs underline" @click="addDeltaLine">
                    Add another line
                </button>

                <div>
                    <label class="block text-sm text-ink-muted">Reason</label>
                    <input
                        v-model="correctionDeltaForm.reason"
                        type="text"
                        maxlength="1000"
                        class="input mt-1 max-w-md"
                    />
                    <p v-if="correctionDeltaForm.errors.reason" class="mt-1 text-sm text-danger">
                        {{ correctionDeltaForm.errors.reason }}
                    </p>
                </div>

                <button
                    type="submit"
                    :disabled="correctionDeltaForm.processing"
                    class="btn btn-primary"
                >
                    {{ correctionDeltaForm.processing ? 'Recording…' : 'Record correction delta' }}
                </button>
            </form>
        </section>

        <!-- Sensitive results -->
        <section class="mt-6 border-t border-line pt-4">
            <h2 class="text-sm font-medium text-ink">
                {{ isFinal ? 'Calculation results' : 'Calculation preview' }}
            </h2>
            <p v-if="sensitiveNeedsMfa" class="mt-1 text-sm text-ink">
                Financial results need a recent multi-factor sign-in. Sign out and sign in again
                with your authentication code to see them.
            </p>
            <p v-else-if="!canViewSensitive" class="mt-1 text-sm text-ink-muted">
                You don't hold payroll.compensation.sensitive.view -- financial results are not
                shown.
            </p>
            <p v-else-if="!isFinal" class="mt-1 text-sm text-ink">
                This is a calculation preview from an editable, recalculable run -- not a final
                payslip. It can change on the next recalculation and is not eligible for payslip
                rendering until the run is approved.
            </p>
            <table v-else-if="results && results.length > 0" class="mt-3 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-line text-xs text-ink-muted">
                        <th scope="col" class="py-2 font-medium">Employee</th>
                        <th scope="col" class="py-2 text-right font-medium">Gross</th>
                        <th scope="col" class="py-2 text-right font-medium">Deductions</th>
                        <th scope="col" class="py-2 text-right font-medium">Net</th>
                        <th scope="col" class="py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="r in results" :key="r.employmentRecordId">
                        <td class="py-2">
                            {{ r.employeeFullName ?? 'Employee record unavailable' }}
                            <span v-if="r.employeeNumber" class="text-xs text-ink-muted"
                                >({{ r.employeeNumber }})</span
                            >
                        </td>
                        <td class="py-2 text-right font-mono">
                            {{ formatMoney(r.grossAmount, '') }}
                        </td>
                        <td class="py-2 text-right font-mono">
                            {{ formatMoney(r.totalDeductions, '') }}
                        </td>
                        <td class="py-2 text-right font-mono">
                            {{ formatMoney(r.netAmount, '') }}
                        </td>
                        <td class="py-2 text-right">
                            <Link
                                :href="`/app/payroll/runs/${run.id}/payslips/${r.employmentRecordId}`"
                                class="text-xs font-medium text-ink underline"
                            >
                                View payslip
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="mt-1 text-sm text-ink-muted">No results calculated yet.</p>
        </section>

        <!-- Approve -->
        <section v-if="run.status === 'calculated'" class="mt-6 border-t border-line pt-4">
            <h2 class="text-sm font-medium text-ink">Approval</h2>
            <p v-if="isSelfPrepared" class="mt-1 text-sm text-ink">
                A different authorized user must approve this payroll run. The preparer of a run may
                never approve it themselves, regardless of what capabilities they hold.
            </p>
            <!-- OD-HR-1: approval happens on the review page, which shows what is approved. -->
            <p v-else-if="canReview" class="mt-2 text-sm">
                <Link class="underline" :href="`/app/payroll/runs/${run.id}/review`"
                    >Review and approve this run</Link
                >
            </p>
            <p v-else-if="canApprove" class="mt-1 text-sm text-ink">
                Approving a run also needs sight of its pay: an administrator must grant you the
                Payroll Approval Reviewer role before you can review and approve it.
            </p>
            <p v-else class="mt-1 text-sm text-ink-muted">
                You don't hold payroll.runs.approve -- you cannot approve this run.
            </p>
        </section>

        <!-- Post -->
        <section
            v-if="run.status === 'approved' && !run.isReversed"
            class="mt-6 border-t border-line pt-4"
        >
            <h2 class="text-sm font-medium text-ink">Posting</h2>
            <section
                v-if="needsAttestation && independence"
                class="notice-attention mt-2 text-sm"
                aria-labelledby="posting-independence"
            >
                <h3 id="posting-independence" class="font-semibold">
                    The evidence behind this approval changed
                </h3>
                <p class="mt-1">
                    A payroll run this approval relied on was reversed. The person who approved this
                    run also recorded compensation changes that no standing independent approval now
                    covers. The approval stays on record. It cannot be withdrawn, and this is not a
                    new approval.
                </p>
                <p
                    v-if="independence.blockedReason === 'not_independent'"
                    class="mt-2"
                    role="alert"
                >
                    You took part in this run, so you cannot attest to it. An independent person
                    with payroll.runs.post must post it.
                </p>
                <p
                    v-else-if="independence.blockedReason === 'cannot_review'"
                    class="mt-2"
                    role="alert"
                >
                    Attesting needs sight of the compensation evidence. An independent person who
                    holds payroll.compensation.sensitive.view must post this run.
                </p>
                <p v-else-if="independence.blockedReason === 'needs_mfa'" class="mt-2" role="alert">
                    Verify your identity to review this evidence before you can attest.
                </p>
                <template v-if="independence.details">
                    <p class="mt-2">Approved by {{ independence.details.approvedBy ?? '—' }}.</p>
                    <ul class="mt-1 list-disc pl-5">
                        <li v-for="r in independence.details.reversedRuns" :key="r.id">
                            Run for {{ r.periodMonth }} was reversed on
                            {{ formatDateTime(r.reversedAt) }}.
                        </li>
                    </ul>
                    <div
                        class="relative mt-2 overflow-x-auto"
                        tabindex="0"
                        role="region"
                        aria-label="Compensation changes affected (scrolls sideways on small screens)"
                    >
                        <table class="data-table w-full min-w-[32rem] text-sm">
                            <caption class="sr-only">
                                Compensation changes this approval no longer independently covers
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">Employee</th>
                                    <th scope="col">Structure</th>
                                    <th scope="col">Effective from</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in independence.details.changes.data" :key="c.key">
                                    <td>{{ c.employeeNumber }} · {{ c.employee }}</td>
                                    <td>{{ c.structure }}</td>
                                    <td>{{ c.effectiveFrom }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-1 text-xs text-ink-muted">
                        Page {{ independence.details.changes.page }} of
                        {{ independence.details.changes.lastPage }} ·
                        {{ independence.details.changes.total }} changes
                        <a
                            v-if="independence.details.changes.page > 1"
                            class="underline"
                            :href="`?independence_page=${independence.details.changes.page - 1}`"
                            >Previous</a
                        >
                        <a
                            v-if="
                                independence.details.changes.page <
                                independence.details.changes.lastPage
                            "
                            class="underline"
                            :href="`?independence_page=${independence.details.changes.page + 1}`"
                            >Next</a
                        >
                    </p>
                </template>
                <label v-if="independence.evidenceVersion" class="mt-3 flex items-start gap-2">
                    <input v-model="attestationAccepted" type="checkbox" class="mt-1" />
                    <span>
                        I am independent of this run. I have reviewed this changed evidence and I
                        accept it before posting. I am not saying the original approver was
                        independent.
                    </span>
                </label>
            </section>
            <p v-if="postRefusal" class="mt-2 text-sm text-danger" role="alert">
                {{ postRefusal }}
            </p>
            <div
                v-if="canPost && accountingReadiness"
                class="mt-2 rounded-xs border border-line p-4 text-sm"
            >
                <p>
                    Salary expense account:
                    {{ accountingReadiness.salaryExpenseLedgerAccountLabel ?? 'Not configured' }}
                </p>
                <p>
                    Salary payable account:
                    {{ accountingReadiness.salaryPayableLedgerAccountLabel ?? 'Not configured' }}
                </p>
                <p
                    v-if="accountingReadiness.missingDeductionMappings.length > 0"
                    class="mt-2 text-ink"
                >
                    Missing deduction ledger mapping for:
                    {{ accountingReadiness.missingDeductionMappings.join(', ') }}
                </p>
                <div class="mt-3">
                    <MfaCodeField
                        id="payroll-post-mfa"
                        v-model="mfaCode"
                        :has-mfa-factor="hasMfaFactor"
                        action="Posting a payroll run"
                        :error="mfaError"
                    />
                </div>
                <button
                    type="button"
                    :disabled="
                        posting || !accountingReadiness.readyToPost || !hasMfaFactor || !mayPost
                    "
                    class="btn btn-primary mt-3"
                    @click="post"
                >
                    {{ posting ? 'Posting…' : 'Post to Finance' }}
                </button>
                <p v-if="!accountingReadiness.readyToPost" class="mt-2 text-xs text-ink-muted">
                    Posting is disabled until accounting configuration is complete.
                    <a class="underline" href="/app/payroll/accounting">Configure accounting</a>.
                </p>
            </div>
            <p v-else-if="!canPost" class="mt-1 text-sm text-ink-muted">
                You don't hold payroll.runs.post -- you cannot post this run.
            </p>
        </section>

        <section
            v-if="independence && independence.phase !== 'before_posting'"
            class="mt-6 border-t border-line pt-4 text-sm"
            aria-labelledby="posted-independence"
        >
            <h2 id="posted-independence" class="font-medium text-ink">Approval independence</h2>
            <p v-if="independence.phase === 'attested'" class="mt-1">
                Posted with an independent attestation, because a run this approval relied on was
                reversed first.
                <template v-if="independence.attestation">
                    Attested by {{ independence.attestation.attestedBy ?? '—' }} on
                    {{ formatDateTime(independence.attestation.attestedAt) }}.
                </template>
            </p>
            <p v-else class="notice-attention mt-1">
                A run this approval relied on was reversed after this run was posted. Nobody
                attested to the change. It is recorded for reconciliation.
            </p>
        </section>

        <!-- Reverse -->
        <section
            v-if="run.status === 'posted' && !run.isReversed && canReverse"
            class="mt-6 border-t border-line pt-4"
        >
            <h2 class="text-sm font-medium text-ink">Reverse posting</h2>
            <button
                v-if="!showReverseForm"
                type="button"
                class="mt-2 rounded-xs border border-danger/40 px-3 py-2 text-sm font-medium text-danger"
                @click="showReverseForm = true"
            >
                Reverse this posting
            </button>
            <div v-else class="mt-2 space-y-2">
                <input
                    v-model="reverseReason"
                    type="text"
                    maxlength="255"
                    placeholder="Reason (optional)"
                    class="input max-w-md"
                />
                <MfaCodeField
                    id="payroll-reverse-mfa"
                    v-model="mfaCode"
                    :has-mfa-factor="hasMfaFactor"
                    action="Reversing a payroll posting"
                    :error="mfaError"
                />
                <button
                    type="button"
                    :disabled="reversing || !hasMfaFactor"
                    class="btn btn-danger"
                    @click="reverse"
                >
                    {{ reversing ? 'Reversing…' : 'Confirm reversal' }}
                </button>
            </div>
        </section>

        <!-- Create correction run -->
        <!-- HRX.5: HRX absence evidence (ADR 0065 §26) -->
        <section v-if="hrxInputs" class="mt-6 border-t border-line pt-4">
            <h2 class="text-sm font-medium text-ink">HRX absence evidence</h2>
            <p class="mt-1 text-xs text-ink-muted">
                Leave and staff attendance evidence captured with this calculation, in half-day
                units ({{ hrxInputs.contractVersion }}). Evidence only: payroll policy pending legal
                review (HRX-L4) -- these units do not change any pay figure or ECR NCP days.
            </p>
            <p
                v-if="hrxInputs.differenceState === 'source_changed_after_approval'"
                role="status"
                class="mt-2 rounded-xs border border-accent-300 bg-accent-100 p-3 text-sm text-ink"
            >
                HRX source changed after approval. This run stays exactly as it is; any pay
                correction is a separate correction run.
                <button
                    type="button"
                    class="ml-2 underline"
                    :disabled="checkingHrx"
                    @click="checkHrxDifferences"
                >
                    Record difference check
                </button>
            </p>
            <p
                v-else-if="hrxInputs.differenceState === 'recalculate_to_refresh'"
                class="mt-2 rounded-xs border border-control-line bg-graphite-100 p-3 text-sm"
            >
                HRX evidence changed since this calculation. Recalculate to refresh it.
            </p>
            <p v-if="hrxInputs.inputs.length === 0" class="mt-2 text-sm text-ink-muted">
                No HRX evidence has been captured yet (it is captured when results are calculated).
            </p>
            <table v-else class="mt-2 w-full border-collapse text-xs">
                <thead>
                    <tr class="border-b border-line text-left text-ink-muted">
                        <th class="py-1">Employee</th>
                        <th class="py-1">Required</th>
                        <th class="py-1">Paid leave</th>
                        <th class="py-1">Unpaid leave</th>
                        <th class="py-1">Recorded absence</th>
                        <th class="py-1">Recorded presence</th>
                        <th class="py-1">Unrecorded</th>
                        <th class="py-1">Evidence</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="i in hrxInputs.inputs"
                        :key="i.employmentRecordId"
                        class="border-b border-line"
                    >
                        <td class="py-1">
                            {{ i.employee.fullName ?? '—' }} ({{
                                i.employee.employeeNumber ?? '—'
                            }})
                        </td>
                        <td class="py-1">{{ i.units.requiredWorkingHalfUnits ?? '—' }}</td>
                        <td class="py-1">{{ i.units.approvedPaidLeaveHalfUnits }}</td>
                        <td class="py-1">{{ i.units.approvedUnpaidLeaveHalfUnits }}</td>
                        <td class="py-1">{{ i.units.recordedAbsenceHalfUnits }}</td>
                        <td class="py-1">{{ i.units.recordedPresenceHalfUnits }}</td>
                        <td class="py-1">{{ i.units.unresolvedWorkingHalfUnits }}</td>
                        <td class="py-1">
                            {{ i.completeness === 'complete' ? 'Complete' : 'Incomplete' }}
                            <span v-if="i.sourceChanged" class="ml-1 text-ink"
                                >· source changed</span
                            >
                            <span class="ml-1 font-mono text-ink-muted">{{
                                i.fingerprint.slice(0, 8)
                            }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section
            v-if="canPrepare && run.status === 'posted' && !run.isReversed"
            class="mt-6 border-t border-line pt-4"
        >
            <h2 class="text-sm font-medium text-ink">Create correction run</h2>
            <p class="mt-1 text-xs text-ink-muted">
                A correction run is a separate, new run -- this original run stays exactly as
                posted, permanently.
            </p>
            <button
                v-if="!showCorrectionForm"
                type="button"
                class="btn btn-secondary mt-2"
                @click="showCorrectionForm = true"
            >
                Create correction run
            </button>
            <div v-else class="mt-2 space-y-2">
                <select v-model="correctionPeriodId" class="input w-auto">
                    <option value="" disabled>Select an open period…</option>
                    <option v-for="p in openPeriods" :key="p.id" :value="p.id">
                        {{ p.periodMonth }}
                    </option>
                </select>
                <button
                    type="button"
                    :disabled="creatingCorrection || !correctionPeriodId"
                    class="btn btn-primary"
                    @click="createCorrection"
                >
                    {{ creatingCorrection ? 'Creating…' : 'Create' }}
                </button>
            </div>
        </section>
    </main>
</template>
