<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';
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
    isSelfPrepared: boolean;
    canPost: boolean;
    accountingReadiness: AccountingReadiness | null;
    canReverse: boolean;
}

const props = defineProps<Props>();

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

// -- Approve --------------------------------------------------------------

const approving = ref(false);
function approve(): void {
    if (!window.confirm('Approve this payroll run? Once approved, results become immutable.'))
        return;

    approving.value = true;
    const key = idempotencyKey(`payroll-approve-${props.run.id}`);
    router.post(
        `/app/payroll/runs/${props.run.id}/approve`,
        {},
        {
            headers: { 'Idempotency-Key': key },
            onSuccess: () => clearIdempotencyKey(`payroll-approve-${props.run.id}`),
            onFinish: () => (approving.value = false),
        },
    );
}

// -- Post -------------------------------------------------------------------

const posting = ref(false);
function post(): void {
    if (!window.confirm('Post this payroll run to Finance? This creates a real journal entry.'))
        return;

    posting.value = true;
    const key = idempotencyKey(`payroll-post-${props.run.id}`);
    router.post(
        `/app/payroll/runs/${props.run.id}/post`,
        {},
        {
            headers: { 'Idempotency-Key': key },
            onSuccess: () => clearIdempotencyKey(`payroll-post-${props.run.id}`),
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
        { reason: reverseReason.value || undefined },
        {
            headers: { 'Idempotency-Key': key },
            onSuccess: () => clearIdempotencyKey(`payroll-reverse-${props.run.id}`),
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
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/payroll/periods">← Periods &amp; Runs</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">
                    {{ run.runKind === 'regular' ? 'Regular payroll run' : 'Correction run' }}
                </h1>
                <p v-if="run.correctsPayrollRunId" class="mt-1 text-sm text-slate-500">
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
            <dt class="text-slate-500">Prepared by</dt>
            <dd>{{ run.preparedByName ?? '—' }}</dd>
            <dt class="text-slate-500">Approved by</dt>
            <dd>{{ run.approvedByName ?? '—' }}</dd>
            <dt class="text-slate-500">Posted by</dt>
            <dd>{{ run.postedByName ?? '—' }}</dd>
            <dt class="text-slate-500">Posted at</dt>
            <dd>{{ run.postedAt ?? '—' }}</dd>
        </dl>

        <!-- Postings / Finance linkage -->
        <section v-if="postings.length > 0" class="mt-6 border-t border-slate-200 pt-4">
            <h2 class="text-sm font-medium text-slate-900">Finance posting</h2>
            <p v-if="originalPosting" class="mt-1 text-sm text-slate-600">
                Original journal entry:
                <span class="font-mono text-xs">{{ originalPosting.journalEntryId }}</span>
            </p>
            <p v-if="reversalPosting" class="mt-1 text-sm text-amber-700">
                Reversed by journal entry
                <span class="font-mono text-xs">{{ reversalPosting.journalEntryId }}</span>
                <span v-if="reversalPosting.reason"> — {{ reversalPosting.reason }}</span>
            </p>
        </section>

        <!-- Calculate -->
        <section
            v-if="canPrepare && run.status === 'draft'"
            class="mt-6 border-t border-slate-200 pt-4"
        >
            <h2 class="text-sm font-medium text-slate-900">Calculation</h2>
            <button
                type="button"
                :disabled="calculating"
                class="mt-2 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                @click="calculate"
            >
                {{ calculating ? 'Calculating…' : 'Calculate' }}
            </button>

            <div v-if="calculationOutcome" class="mt-4 rounded border border-slate-200 p-4 text-sm">
                <p>{{ calculationOutcome.resolvedCount }} EmploymentRecord(s) resolved.</p>
                <p
                    v-if="calculationOutcome.transitionedToCalculated"
                    class="mt-1 font-medium text-emerald-700"
                >
                    Every eligible EmploymentRecord was resolved -- this run is now Calculated and
                    ready for review/approval.
                </p>
                <p v-else class="mt-1 font-medium text-amber-700">
                    Manual payroll input required for
                    {{ unresolvedEmployees.length }} EmploymentRecord(s) before this run can be
                    calculated. The run stays in draft until every one is resolved.
                </p>
            </div>

            <div v-if="unresolvedEmployees.length > 0" class="mt-4 space-y-3">
                <div
                    v-for="e in unresolvedEmployees"
                    :key="e.employmentRecordId"
                    class="rounded border border-amber-200 bg-amber-50 p-4"
                >
                    <p class="text-sm font-medium text-amber-900">
                        Manual payroll input required —
                        {{ e.employeeFullName ?? e.employmentRecordId }}
                        <span v-if="e.employeeNumber" class="text-xs text-amber-700"
                            >({{ e.employeeNumber }})</span
                        >
                    </p>
                    <p class="mt-1 text-xs text-amber-700">
                        This EmploymentRecord had a mid-period hire, termination, or compensation
                        change -- the exact eligible amounts for each component must be entered
                        directly. No proration is computed automatically.
                    </p>

                    <button
                        v-if="overrideTarget !== e.employmentRecordId"
                        type="button"
                        class="mt-2 rounded border border-amber-400 px-2 py-1 text-xs font-medium text-amber-900"
                        @click="startOverride(e.employmentRecordId)"
                    >
                        Record manual result
                    </button>

                    <div v-else class="mt-3 space-y-2">
                        <div v-for="c in components" :key="c.id" class="flex items-center gap-2">
                            <label class="w-48 text-xs text-slate-600"
                                >{{ c.code }} — {{ c.name }} ({{ c.type }})</label
                            >
                            <input
                                v-model="overrideForm[e.employmentRecordId].amounts[c.id]"
                                type="text"
                                placeholder="0.00"
                                class="w-32 rounded border border-slate-300 px-2 py-1 text-sm"
                            />
                        </div>
                        <div>
                            <label class="block text-xs text-slate-600">Reason</label>
                            <input
                                v-model="overrideForm[e.employmentRecordId].reason"
                                type="text"
                                maxlength="1000"
                                class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                            />
                        </div>
                        <button
                            type="button"
                            :disabled="overrideSubmitting"
                            class="rounded bg-slate-900 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50"
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
            class="mt-6 border-t border-slate-200 pt-4"
        >
            <h2 class="text-sm font-medium text-slate-900">Correction deltas</h2>
            <p class="mt-1 text-xs text-slate-500">
                Each line is a signed delta against one component -- an increase or a decrease,
                never an absolute replacement value. A correction with zero net effect is rejected.
            </p>

            <form class="mt-3 space-y-3" @submit.prevent="submitCorrectionDelta">
                <div>
                    <label class="block text-sm text-slate-600">EmploymentRecord</label>
                    <select
                        v-model="correctionDeltaForm.employment_record_id"
                        class="mt-1 w-full max-w-md rounded border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="" disabled>Select…</option>
                        <option
                            v-for="c in correctionCandidates"
                            :key="c.employmentRecordId"
                            :value="c.employmentRecordId"
                        >
                            {{ c.employeeFullName ?? c.employmentRecordId }}
                        </option>
                    </select>
                    <p
                        v-if="correctionDeltaForm.errors.employment_record_id"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ correctionDeltaForm.errors.employment_record_id }}
                    </p>
                </div>

                <div
                    v-for="(line, i) in correctionDeltaForm.lines"
                    :key="i"
                    class="flex flex-wrap items-center gap-2"
                >
                    <select
                        v-model="line.salary_component_id"
                        class="rounded border border-slate-300 px-2 py-1.5 text-sm"
                    >
                        <option value="" disabled>Component…</option>
                        <option v-for="c in components" :key="c.id" :value="c.id">
                            {{ c.code }} — {{ c.name }}
                        </option>
                    </select>
                    <select
                        v-model="line.effect"
                        class="rounded border border-slate-300 px-2 py-1.5 text-sm"
                    >
                        <option value="increase">Increase</option>
                        <option value="decrease">Decrease</option>
                    </select>
                    <input
                        v-model="line.amount"
                        type="text"
                        placeholder="0.00"
                        class="w-28 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    />
                </div>
                <button type="button" class="text-xs underline" @click="addDeltaLine">
                    Add another line
                </button>

                <div>
                    <label class="block text-sm text-slate-600">Reason</label>
                    <input
                        v-model="correctionDeltaForm.reason"
                        type="text"
                        maxlength="1000"
                        class="mt-1 w-full max-w-md rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <p v-if="correctionDeltaForm.errors.reason" class="mt-1 text-sm text-red-600">
                        {{ correctionDeltaForm.errors.reason }}
                    </p>
                </div>

                <button
                    type="submit"
                    :disabled="correctionDeltaForm.processing"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                >
                    {{ correctionDeltaForm.processing ? 'Recording…' : 'Record correction delta' }}
                </button>
            </form>
        </section>

        <!-- Sensitive results -->
        <section class="mt-6 border-t border-slate-200 pt-4">
            <h2 class="text-sm font-medium text-slate-900">Calculation results</h2>
            <p v-if="!canViewSensitive" class="mt-1 text-sm text-slate-500">
                You don't hold payroll.compensation.sensitive.view -- financial results are not
                shown.
            </p>
            <table v-else-if="results && results.length > 0" class="mt-3 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Employee</th>
                        <th scope="col" class="py-2 text-right font-medium">Gross</th>
                        <th scope="col" class="py-2 text-right font-medium">Deductions</th>
                        <th scope="col" class="py-2 text-right font-medium">Net</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="r in results" :key="r.employmentRecordId">
                        <td class="py-2">
                            {{ r.employeeFullName ?? r.employmentRecordId }}
                            <span v-if="r.employeeNumber" class="text-xs text-slate-500"
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
                    </tr>
                </tbody>
            </table>
            <p v-else class="mt-1 text-sm text-slate-500">No results calculated yet.</p>
        </section>

        <!-- Approve -->
        <section v-if="run.status === 'calculated'" class="mt-6 border-t border-slate-200 pt-4">
            <h2 class="text-sm font-medium text-slate-900">Approval</h2>
            <p v-if="isSelfPrepared" class="mt-1 text-sm text-amber-700">
                A different authorized user must approve this payroll run. The preparer of a run may
                never approve it themselves, regardless of what capabilities they hold.
            </p>
            <button
                v-else-if="canApprove"
                type="button"
                :disabled="approving"
                class="mt-2 rounded bg-emerald-700 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                @click="approve"
            >
                {{ approving ? 'Approving…' : 'Approve run' }}
            </button>
            <p v-else class="mt-1 text-sm text-slate-500">
                You don't hold payroll.runs.approve -- you cannot approve this run.
            </p>
        </section>

        <!-- Post -->
        <section
            v-if="run.status === 'approved' && !run.isReversed"
            class="mt-6 border-t border-slate-200 pt-4"
        >
            <h2 class="text-sm font-medium text-slate-900">Posting</h2>
            <div
                v-if="canPost && accountingReadiness"
                class="mt-2 rounded border border-slate-200 p-4 text-sm"
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
                    class="mt-2 text-amber-700"
                >
                    Missing deduction ledger mapping for:
                    {{ accountingReadiness.missingDeductionMappings.join(', ') }}
                </p>
                <button
                    type="button"
                    :disabled="posting || !accountingReadiness.readyToPost"
                    class="mt-3 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                    @click="post"
                >
                    {{ posting ? 'Posting…' : 'Post to Finance' }}
                </button>
                <p v-if="!accountingReadiness.readyToPost" class="mt-2 text-xs text-slate-500">
                    Posting is disabled until accounting configuration is complete.
                    <a class="underline" href="/app/payroll/accounting">Configure accounting</a>.
                </p>
            </div>
            <p v-else-if="!canPost" class="mt-1 text-sm text-slate-500">
                You don't hold payroll.runs.post -- you cannot post this run.
            </p>
        </section>

        <!-- Reverse -->
        <section
            v-if="run.status === 'posted' && !run.isReversed && canReverse"
            class="mt-6 border-t border-slate-200 pt-4"
        >
            <h2 class="text-sm font-medium text-slate-900">Reverse posting</h2>
            <button
                v-if="!showReverseForm"
                type="button"
                class="mt-2 rounded border border-red-300 px-3 py-2 text-sm font-medium text-red-700"
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
                    class="w-full max-w-md rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <button
                    type="button"
                    :disabled="reversing"
                    class="rounded bg-red-700 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                    @click="reverse"
                >
                    {{ reversing ? 'Reversing…' : 'Confirm reversal' }}
                </button>
            </div>
        </section>

        <!-- Create correction run -->
        <section
            v-if="canPrepare && run.status === 'posted' && !run.isReversed"
            class="mt-6 border-t border-slate-200 pt-4"
        >
            <h2 class="text-sm font-medium text-slate-900">Create correction run</h2>
            <p class="mt-1 text-xs text-slate-500">
                A correction run is a separate, new run -- this original run stays exactly as
                posted, permanently.
            </p>
            <button
                v-if="!showCorrectionForm"
                type="button"
                class="mt-2 rounded border border-slate-300 px-3 py-2 text-sm"
                @click="showCorrectionForm = true"
            >
                Create correction run
            </button>
            <div v-else class="mt-2 space-y-2">
                <select
                    v-model="correctionPeriodId"
                    class="rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="" disabled>Select an open period…</option>
                    <option v-for="p in openPeriods" :key="p.id" :value="p.id">
                        {{ p.periodMonth }}
                    </option>
                </select>
                <button
                    type="button"
                    :disabled="creatingCorrection || !correctionPeriodId"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                    @click="createCorrection"
                >
                    {{ creatingCorrection ? 'Creating…' : 'Create' }}
                </button>
            </div>
        </section>
    </main>
</template>
