<script setup lang="ts">
// OD-HR-1 / OD-HR-2 (owner decisions 2026-11-05): the payroll approval review.
// Everything shown is the server's purpose-limited packet (Payroll's own
// results, never an HR profile), already cut to this checker. The approval
// names the calculation shown here; if the run was recalculated since, the
// server refuses it as stale. Amounts are never computed in the browser.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MfaCodeField from '../../../../Components/MfaCodeField.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';
import { formatMoney } from '../../../../money';
import { idempotencyKey, clearIdempotencyKey } from '../../../../idempotency';
import { formatDateTime } from '../../../../datetime';

interface Totals {
    gross: string;
    deductions: string;
    net: string;
    entries: number;
    reconciles: boolean;
}
interface Pay {
    gross: string;
    deductions: string;
    net: string;
}
interface Person {
    name: string;
    number: string;
}
interface Page<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
}
interface Issue {
    code: string;
    message: string;
}

const props = defineProps<{
    review: {
        run: {
            id: string;
            kind: string;
            status: 'draft' | 'calculated' | 'approved' | 'posted';
            periodMonth: string | null;
            periodStartsOn: string | null;
            periodEndsOn: string | null;
            preparedBy: string | null;
            approvedBy: string | null;
            approvedAt: string | null;
            approvedCalculationId: string | null;
            approvalEvidence: 'bound' | 'legacy' | null;
        };
        calculation: {
            id: string;
            ordinal: number;
            calculatedAt: string | null;
            calculatedBy: string | null;
            complete: boolean;
            adjustmentCount: number;
            compensationSourcesRecorded: boolean;
        } | null;
        previousRun: { id: string; periodMonth: string | null; status: string } | null;
        totals: Totals;
        previousTotals: Totals | null;
        components: {
            code: string;
            name: string;
            type: string;
            effect: string;
            amount: string;
            currency: string;
        }[];
        approvable: boolean;
        blocking: Issue[];
        warnings: Issue[];
        changes: Page<{
            key: string;
            employee: Person | null;
            kind: 'new' | 'left' | 'changed';
            previous: Pay | null;
            current: Pay | null;
        }>;
        adjustments: Page<{
            key: string;
            employee: Person | null;
            mode: string;
            component: string;
            effect: string | null;
            amount: string;
            reason: string;
            author: string | null;
            recordedAt: string;
        }>;
        compensation: Page<{
            key: string;
            employee: Person | null;
            structure: string;
            effectiveFrom: string;
            effectiveTo: string | null;
            recordedAt: string;
            author: string | null;
        }>;
    };
    hasMfaFactor: boolean;
}>();

const currency = computed(() => props.review.components[0]?.currency ?? 'INR');
const money = (amount: string | null | undefined) =>
    amount == null ? '—' : formatMoney(amount, currency.value);
const kindLabel: Record<string, string> = {
    new: 'New (not in the previous run)',
    left: 'Not in this run',
    changed: 'Changed',
};
const modeLabel: Record<string, string> = {
    manual_override: 'Manual override',
    correction_delta: 'Correction',
};

function pageUrl(section: string, page: number): string {
    const params = new URLSearchParams(window.location.search);
    params.set(`${section}_page`, String(page));
    return `/app/payroll/runs/${props.review.run.id}/review?${params.toString()}`;
}

const status = computed(
    () => (usePage().props.flash as { success?: string | null } | undefined)?.success ?? null,
);
const mfaCode = ref('');
const mfaError = ref<string | null>(null);
const refusal = ref<string | null>(null);
const approving = ref(false);
function approve(): void {
    if (!props.review.calculation) return;
    if (
        !window.confirm(
            'Approve this payroll run as reviewed? Once approved, its results are final.',
        )
    )
        return;
    approving.value = true;
    const scope = `payroll-approve-${props.review.run.id}`;
    router.post(
        `/app/payroll/runs/${props.review.run.id}/approve`,
        { mfa_code: mfaCode.value, calculation_id: props.review.calculation.id },
        {
            headers: { 'Idempotency-Key': idempotencyKey(scope) },
            onSuccess: () => {
                clearIdempotencyKey(scope);
                mfaCode.value = '';
            },
            onError: (errors: Record<string, string>) => {
                mfaError.value = errors.mfa_code ?? null;
                refusal.value = errors.payroll ?? errors.calculation_id ?? errors.message ?? null;
                mfaCode.value = '';
            },
            onFinish: () => (approving.value = false),
        },
    );
}
</script>

<template>
    <Head title="Payroll approval review" />
    <main class="mx-auto max-w-6xl px-4 py-6 font-sans text-ink sm:p-8">
        <Link class="text-sm underline" :href="`/app/payroll/runs/${review.run.id}`"
            >← Payroll run</Link
        >
        <h1 class="mt-2 text-xl font-semibold">
            Payroll approval review · {{ review.run.periodMonth }}
        </h1>
        <p class="mt-1 text-sm text-ink-muted">
            The run's totals and everything that changed since the previous approved run, from the
            stored payroll results. Unchanged pay is not repeated; the full results stay on the run
            page. Opening this page is recorded.
        </p>
        <p v-if="status" class="notice-success mt-4" role="status">{{ status }}</p>

        <section class="mt-6" aria-labelledby="review-run">
            <h2 id="review-run" class="text-sm font-semibold">Run</h2>
            <dl class="mt-2 grid gap-2 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-ink-muted">Status</dt>
                    <dd><StatusBadge :status="review.run.status" /></dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Period</dt>
                    <dd>{{ review.run.periodStartsOn }} – {{ review.run.periodEndsOn }}</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Prepared by</dt>
                    <dd>{{ review.run.preparedBy ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Calculation reviewed</dt>
                    <dd v-if="review.calculation">
                        No. {{ review.calculation.ordinal }} ·
                        {{ review.calculation.calculatedBy ?? '—' }} ·
                        {{ formatDateTime(review.calculation.calculatedAt) }}
                    </dd>
                    <dd v-else>Not calculated</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Compared with</dt>
                    <dd>
                        {{
                            review.previousRun
                                ? `the ${review.previousRun.periodMonth} run`
                                : 'no earlier approved run'
                        }}
                    </dd>
                </div>
                <div v-if="review.run.approvedBy">
                    <dt class="text-ink-muted">Approved</dt>
                    <dd>
                        {{ review.run.approvedBy }} · {{ formatDateTime(review.run.approvedAt) }}
                        <span
                            v-if="review.run.approvalEvidence === 'legacy'"
                            class="block text-ink-muted"
                        >
                            Approved before approvals recorded the reviewed calculation; which
                            calculation was reviewed is not known.
                        </span>
                        <span
                            v-else-if="review.run.approvalEvidence === 'bound'"
                            class="block text-ink-muted"
                        >
                            Approved against the calculation recorded for this run.
                        </span>
                    </dd>
                </div>
            </dl>
        </section>

        <section class="mt-6" aria-labelledby="review-totals">
            <h2 id="review-totals" class="text-sm font-semibold">Totals</h2>
            <div class="relative mt-2 overflow-x-auto">
                <table class="data-table w-full min-w-[32rem] text-sm">
                    <caption class="sr-only">
                        Run totals beside the previous approved run
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col" class="text-left">Measure</th>
                            <th scope="col" class="text-right">This run</th>
                            <th scope="col" class="text-right">Previous run</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in ['gross', 'deductions', 'net'] as const" :key="m">
                            <th scope="row" class="text-left font-normal">
                                {{ { gross: 'Gross', deductions: 'Deductions', net: 'Net' }[m] }}
                            </th>
                            <td class="text-right tabular-nums">{{ money(review.totals[m]) }}</td>
                            <td class="text-right tabular-nums">
                                {{ money(review.previousTotals?.[m]) }}
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" class="text-left font-normal">Entries</th>
                            <td class="text-right tabular-nums">{{ review.totals.entries }}</td>
                            <td class="text-right tabular-nums">
                                {{ review.previousTotals?.entries ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-sm">
                {{
                    review.totals.reconciles
                        ? 'Gross minus deductions equals net over the run.'
                        : 'Gross minus deductions does NOT equal net over the run.'
                }}
            </p>
            <h3 class="mt-4 text-sm font-medium">By salary component</h3>
            <ul class="mt-1 text-sm">
                <li v-for="c in review.components" :key="`${c.code}-${c.effect}`">
                    {{ c.code }} — {{ c.name }} ({{
                        c.type === 'deduction' ? 'deduction' : 'earning'
                    }}{{ c.effect === 'decrease' ? ', reduced' : '' }}):
                    <span class="tabular-nums">{{ money(c.amount) }}</span>
                </li>
            </ul>
        </section>

        <section class="mt-6" aria-labelledby="review-changes">
            <h2 id="review-changes" class="text-sm font-semibold">
                Changes since the previous run ({{ review.changes.total }})
            </h2>
            <p v-if="review.changes.total === 0" class="mt-1 text-sm text-ink-muted">
                Nothing changed.
            </p>
            <div v-else class="relative mt-2 overflow-x-auto">
                <table class="data-table w-full min-w-[40rem] text-sm">
                    <caption class="sr-only">
                        Entries whose pay differs from the previous approved run, page
                        {{
                            review.changes.current_page
                        }}
                        of
                        {{
                            review.changes.last_page
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col" class="text-left">Staff member</th>
                            <th scope="col" class="text-left">Change</th>
                            <th scope="col" class="text-right">Previous net</th>
                            <th scope="col" class="text-right">Net now</th>
                            <th scope="col" class="text-right">Gross now</th>
                            <th scope="col" class="text-right">Deductions now</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in review.changes.data" :key="r.key">
                            <td>
                                {{ r.employee?.name ?? '—' }}
                                <span class="block text-xs text-ink-muted">{{
                                    r.employee?.number
                                }}</span>
                            </td>
                            <td>{{ kindLabel[r.kind] }}</td>
                            <td class="text-right tabular-nums">{{ money(r.previous?.net) }}</td>
                            <td class="text-right tabular-nums">{{ money(r.current?.net) }}</td>
                            <td class="text-right tabular-nums">{{ money(r.current?.gross) }}</td>
                            <td class="text-right tabular-nums">
                                {{ money(r.current?.deductions) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav
                v-if="review.changes.last_page > 1"
                class="mt-2 flex gap-4 text-sm"
                aria-label="Changes pages"
            >
                <span aria-live="polite"
                    >Page {{ review.changes.current_page }} of {{ review.changes.last_page }}</span
                >
                <a
                    v-if="review.changes.current_page > 1"
                    class="underline"
                    :href="pageUrl('changes', review.changes.current_page - 1)"
                    >Previous page</a
                >
                <a
                    v-if="review.changes.current_page < review.changes.last_page"
                    class="underline"
                    :href="pageUrl('changes', review.changes.current_page + 1)"
                    >Next page</a
                >
            </nav>
        </section>

        <section class="mt-6" aria-labelledby="review-adjustments">
            <h2 id="review-adjustments" class="text-sm font-semibold">
                Overrides and corrections ({{ review.adjustments.total }})
            </h2>
            <p v-if="review.adjustments.total === 0" class="mt-1 text-sm text-ink-muted">
                None recorded.
            </p>
            <div v-else class="relative mt-2 overflow-x-auto">
                <table class="data-table w-full min-w-[44rem] text-sm">
                    <caption class="sr-only">
                        Manual overrides and correction deltas of this run
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col" class="text-left">Staff member</th>
                            <th scope="col" class="text-left">Kind</th>
                            <th scope="col" class="text-left">Component</th>
                            <th scope="col" class="text-right">Amount</th>
                            <th scope="col" class="text-left">Reason</th>
                            <th scope="col" class="text-left">Recorded by</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in review.adjustments.data" :key="a.key">
                            <td>
                                {{ a.employee?.name ?? '—' }}
                                <span class="block text-xs text-ink-muted">{{
                                    a.employee?.number
                                }}</span>
                            </td>
                            <td>
                                {{ modeLabel[a.mode] ?? a.mode
                                }}{{
                                    a.effect
                                        ? ` (${a.effect === 'increase' ? 'increases' : 'reduces'} the component)`
                                        : ''
                                }}
                            </td>
                            <td>{{ a.component }}</td>
                            <td class="text-right tabular-nums">{{ money(a.amount) }}</td>
                            <td>{{ a.reason }}</td>
                            <td>{{ a.author ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav
                v-if="review.adjustments.last_page > 1"
                class="mt-2 flex gap-4 text-sm"
                aria-label="Override pages"
            >
                <span
                    >Page {{ review.adjustments.current_page }} of
                    {{ review.adjustments.last_page }}</span
                >
                <a
                    v-if="review.adjustments.current_page > 1"
                    class="underline"
                    :href="pageUrl('adjustments', review.adjustments.current_page - 1)"
                    >Previous page</a
                >
                <a
                    v-if="review.adjustments.current_page < review.adjustments.last_page"
                    class="underline"
                    :href="pageUrl('adjustments', review.adjustments.current_page + 1)"
                    >Next page</a
                >
            </nav>
        </section>

        <section class="mt-6" aria-labelledby="review-compensation">
            <h2 id="review-compensation" class="text-sm font-semibold">
                Compensation changes this run pays first ({{ review.compensation.total }})
            </h2>
            <p class="mt-1 text-sm text-ink-muted">
                Changes no earlier approved run has paid. Whoever recorded one cannot approve this
                run.
            </p>
            <p v-if="review.compensation.total === 0" class="mt-1 text-sm text-ink-muted">
                None recorded.
            </p>
            <div v-else class="relative mt-2 overflow-x-auto">
                <table class="data-table w-full min-w-[40rem] text-sm">
                    <caption class="sr-only">
                        Compensation changes this run pays first
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col" class="text-left">Staff member</th>
                            <th scope="col" class="text-left">Salary structure</th>
                            <th scope="col" class="text-left">Effective</th>
                            <th scope="col" class="text-left">Recorded by</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in review.compensation.data" :key="c.key">
                            <td>
                                {{ c.employee?.name ?? '—' }}
                                <span class="block text-xs text-ink-muted">{{
                                    c.employee?.number
                                }}</span>
                            </td>
                            <td>{{ c.structure }}</td>
                            <td>
                                {{ c.effectiveFrom
                                }}{{ c.effectiveTo ? ` – ${c.effectiveTo}` : '' }}
                            </td>
                            <td>{{ c.author ?? 'Not recorded' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav
                v-if="review.compensation.last_page > 1"
                class="mt-2 flex gap-4 text-sm"
                aria-label="Compensation pages"
            >
                <span
                    >Page {{ review.compensation.current_page }} of
                    {{ review.compensation.last_page }}</span
                >
                <a
                    v-if="review.compensation.current_page > 1"
                    class="underline"
                    :href="pageUrl('compensation', review.compensation.current_page - 1)"
                    >Previous page</a
                >
                <a
                    v-if="review.compensation.current_page < review.compensation.last_page"
                    class="underline"
                    :href="pageUrl('compensation', review.compensation.current_page + 1)"
                    >Next page</a
                >
            </nav>
        </section>

        <section class="mt-8 border-t border-line pt-4" aria-labelledby="review-decision">
            <h2 id="review-decision" class="text-sm font-semibold">Decision</h2>
            <ul v-if="review.warnings.length" class="mt-2 space-y-1 text-sm">
                <li v-for="w in review.warnings" :key="w.code" class="notice-attention">
                    {{ w.message }}
                </li>
            </ul>
            <ul
                v-if="review.blocking.length"
                class="mt-2 space-y-1 text-sm"
                aria-label="Why this run cannot be approved"
            >
                <li v-for="b in review.blocking" :key="b.code" class="notice-error">
                    {{ b.message }}
                </li>
            </ul>
            <div v-if="review.approvable && review.calculation" class="mt-3 space-y-2">
                <p class="text-sm">
                    You are approving calculation no. {{ review.calculation.ordinal }} exactly as
                    shown. If the run is recalculated before you confirm, the approval is refused.
                </p>
                <MfaCodeField
                    id="payroll-review-mfa"
                    v-model="mfaCode"
                    :has-mfa-factor="hasMfaFactor"
                    action="Approving a payroll run"
                    :error="mfaError"
                />
                <p v-if="refusal" class="text-sm text-danger" role="alert">{{ refusal }}</p>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="approving || !hasMfaFactor"
                    @click="approve"
                >
                    {{ approving ? 'Approving…' : 'Approve this run' }}
                </button>
            </div>
        </section>
    </main>
</template>
