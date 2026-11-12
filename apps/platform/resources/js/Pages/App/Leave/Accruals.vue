<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import {
    accrualOutcomeLabels,
    monthLabel,
    units,
    useLeaveError,
    type LeaveTypeRow,
    type Option,
} from '../../../leave';

/**
 * HR.10 (ADR 0065 §29) — monthly accrual.
 *
 * For one leave type and leave year: the monthly grid (what the ledger holds
 * — a blank cell means nothing is posted, never zero), the runs, and, for a
 * chosen month, the server's preview (every employment's outcome and the
 * complete counts). A run credits each employment's assigned MONTHLY version
 * once per whole month it was employed and assigned; a part month is never
 * prorated and is listed for you to handle explicitly. A run can be
 * repeated or resumed safely: a month is never credited twice.
 */
interface YearRow {
    id: string;
    label: string;
    startsOn: string;
    endsOn: string;
}
interface RunRow {
    id: string;
    periodStart: string;
    status: string;
    batchCount: number;
    failureCode: string | null;
    startedAt: string;
    completedAt: string | null;
}
interface PreviewRow {
    employmentRecordId: string;
    employeeNumber: string | null;
    fullName: string | null;
    outcome: string;
    units: number | null;
    postableOn: string | null;
    eligibilityDrift: boolean;
}
interface MonthStatus {
    periodStart: string;
    status: AccrualStatus;
    reason: string;
    counts: Record<string, number> | null;
    runId: string | null;
    runStatus: string | null;
    failureCode: string | null;
    startedAt: string | null;
    completedAt: string | null;
    blockedBy: 'year_closed' | 'type_inactive' | 'type_untracked' | null;
}
interface Props {
    types: LeaveTypeRow[];
    years: YearRow[];
    leaveTypeId: string;
    leaveYearId: string;
    today: string;
    periodStart: string;
    grid: {
        months: string[];
        rows: {
            employmentRecordId: string;
            employeeNumber: string | null;
            fullName: string | null;
            employmentStatus: string;
            months: (number | null)[];
            allocatedUnits: number | null;
            balanceUnits: number | null;
        }[];
        nextAfter: string | null;
    } | null;
    runs: RunRow[] | null;
    preview: {
        period: { start: string; end: string };
        counts: Record<string, number>;
        examined: number;
        rows: PreviewRow[];
        nextAfter: string | null;
    } | null;
    run:
        | (RunRow & {
              counts: Record<string, number>;
              examined: number;
              items: (PreviewRow & { units: number | null })[];
              nextAfter: string | null;
          })
        | null;
    outcome: string;
    outcomes: Option[];
    error: string | null;
    canManage: boolean;
    monthStatus: MonthStatus | null;
    monthStatuses: MonthStatus[] | null;
}

const props = defineProps<Props>();

// HR.R1-C32 (WO-9, D-3 option 3): read-only month status. Nothing here runs accrual.
type AccrualStatus =
    | 'not_due'
    | 'running'
    | 'failed'
    | 'missed'
    | 'incomplete'
    | 'complete'
    | 'nothing_to_credit'
    | 'unknown';
const STATUS_BADGE = {
    not_due: 'accrual_not_due',
    running: 'accrual_running',
    failed: 'accrual_failed',
    missed: 'accrual_missed',
    incomplete: 'accrual_incomplete',
    complete: 'accrual_complete',
    nothing_to_credit: 'accrual_nothing_to_credit',
    unknown: 'accrual_unknown',
} as const;
function when(iso: string | null): string {
    return iso === null ? '' : `${iso.slice(0, 16).replace('T', ' ')} UTC`;
}
const BLOCKED: Record<NonNullable<MonthStatus['blockedBy']>, string> = {
    year_closed: 'The leave year is closed, so the month can no longer be run.',
    type_inactive: 'The leave type is inactive, so the month cannot be run.',
    type_untracked: 'The leave type does not track a balance, so the month cannot be run.',
};
function explain(s: MonthStatus): string {
    const text = reasonText(s);
    return s.blockedBy ? `${text} ${BLOCKED[s.blockedBy]}` : text;
}
function reasonText(s: MonthStatus): string {
    const wouldCredit = s.counts?.credited ?? 0;
    // Only a manager can run a month: a viewer is told who can (review LOW-4).
    const runIt = props.canManage
        ? 'Run the month'
        : 'Someone who manages leave needs to run the month';
    switch (s.reason) {
        case 'month_not_started':
            return 'The month has not started.';
        case 'version_not_yet_postable':
            return 'Some staff are credited in arrears and are not due yet.';
        case 'run_processing':
            return `A run started ${when(s.startedAt)} and has not finished. ${runIt} again to continue it.`;
        case 'run_failed':
            return `The last run failed (${s.failureCode ?? 'no code'}). ${runIt} again to resume it.`;
        case 'never_run':
            return `No run has completed, and ${wouldCredit} employment(s) would be credited. ${runIt}.`;
        case 'would_still_credit':
            return `A run completed, but ${wouldCredit} employment(s) would still be credited (for example, added after the run). ${runIt} again: nothing is credited twice.`;
        case 'run_completed':
            return 'A run completed and nobody is left to credit.';
        case 'no_credit_due':
            return 'Nobody has anything to credit this month.';
        case 'credits_without_completed_run':
            return 'Credits exist but no completed run was found. Check the run history.';
        case 'runs_unreadable':
            return 'The run history could not be read. Treat the month as unchecked and try again.';
        default:
            return 'The status could not be worked out. Treat the month as unchecked and try again.';
    }
}
function checkMonths(): void {
    router.get(
        '/app/leave/accruals',
        // Not the selected month: checking the year does not also re-run that month's preview (review LOW-3).
        { ...base(), check_months: '1' },
        { preserveScroll: true },
    );
}
const leaveError = useLeaveError();

const filters = ref({ leave_type_id: props.leaveTypeId, leave_year_id: props.leaveYearId });
const month = ref(props.periodStart);
const runForm = useForm({ leave_type_id: '', leave_year_id: '', period_start: '' });
const confirming = ref(false);
const confirmHeading = ref<HTMLElement | null>(null);

const year = computed(() => props.years.find((y) => y.id === props.leaveYearId) ?? null);
const months = computed(() => props.grid?.months ?? []);

function base(): Record<string, string | undefined> {
    return {
        leave_type_id: filters.value.leave_type_id || undefined,
        leave_year_id: filters.value.leave_year_id || undefined,
    };
}

function show(): void {
    router.get('/app/leave/accruals', base(), { preserveScroll: true });
}

function previewMonth(): void {
    router.get(
        '/app/leave/accruals',
        { ...base(), period_start: month.value || undefined },
        { preserveScroll: true },
    );
}

function nextGridPage(after: string): void {
    router.get(
        '/app/leave/accruals',
        { ...base(), period_start: props.periodStart || undefined, after },
        { preserveScroll: true },
    );
}

function nextPreviewPage(after: string): void {
    router.get(
        '/app/leave/accruals',
        { ...base(), period_start: props.periodStart, preview_after: after },
        { preserveScroll: true },
    );
}

function openRun(id: string, outcome = '', itemAfter?: string): void {
    router.get(
        '/app/leave/accruals',
        { ...base(), run: id, outcome: outcome || undefined, item_after: itemAfter },
        { preserveScroll: true },
    );
}

async function askToRun(): Promise<void> {
    confirming.value = true;
    await nextTick();
    confirmHeading.value?.focus();
}

function executeRun(): void {
    runForm
        .transform(() => ({
            leave_type_id: props.leaveTypeId,
            leave_year_id: props.leaveYearId,
            period_start: props.periodStart,
        }))
        .post('/app/leave/accrual-runs', {
            preserveScroll: true,
            onFinish: () => {
                confirming.value = false;
            },
        });
}

function cell(value: number | null): string {
    return value === null ? '' : String(value);
}
</script>

<template>
    <main class="mx-auto max-w-7xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/leave/entitlements">← Leave entitlements</a>
        <h1 class="mt-2 text-xl font-semibold">Monthly accrual</h1>
        <p class="mt-1 text-sm text-ink-muted">
            Units are half-days (2 = one day). Only policy versions set to monthly accrual are
            credited here; annual policies keep the annual allocation run.
        </p>
        <p
            v-if="leaveError || error"
            role="alert"
            class="mt-3 rounded-xs border border-danger/40 bg-danger-tint p-3 text-sm text-danger"
        >
            {{ leaveError ?? error }}
        </p>

        <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="show">
            <label class="text-sm"
                >Leave type
                <select v-model="filters.leave_type_id" class="input ml-1 w-auto">
                    <option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
            </label>
            <label class="text-sm"
                >Leave year
                <select v-model="filters.leave_year_id" class="input ml-1 w-auto">
                    <option v-for="y in years" :key="y.id" :value="y.id">{{ y.label }}</option>
                </select>
            </label>
            <button type="submit" class="underline">Show</button>
        </form>
        <p v-if="types.length === 0 || years.length === 0" class="mt-4 text-sm text-ink-muted">
            Create a balance-tracked leave type and open a leave year first.
        </p>

        <section v-if="year" class="mt-6" aria-labelledby="status-heading">
            <h2 id="status-heading" class="text-sm font-semibold">Accrual status</h2>
            <p class="mt-1 text-sm text-ink-muted">
                Whether each month that is due has been run and fully credited. This only reads; it
                never runs a month.
            </p>
            <p v-if="monthStatus" class="mt-2 text-sm" aria-live="polite">
                {{ monthLabel(monthStatus.periodStart) }}:
                <StatusBadge :status="STATUS_BADGE[monthStatus.status]" />
                {{ explain(monthStatus) }}
            </p>
            <button type="button" class="mt-2 underline" @click="checkMonths">
                Check every month of this year
            </button>
            <table v-if="monthStatuses" class="mt-3 w-full text-left text-sm">
                <caption class="sr-only">
                    Accrual status of each month
                </caption>
                <thead>
                    <tr>
                        <th scope="col" class="py-1">Month</th>
                        <th scope="col" class="py-1">Status</th>
                        <th scope="col" class="py-1">What it means</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="s in monthStatuses"
                        :key="s.periodStart"
                        class="border-t border-line"
                    >
                        <th scope="row" class="py-1 font-normal">
                            {{ monthLabel(s.periodStart) }}
                        </th>
                        <td class="py-1"><StatusBadge :status="STATUS_BADGE[s.status]" /></td>
                        <td class="py-1 text-ink-muted">{{ explain(s) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section v-if="canManage && year" class="mt-6" aria-labelledby="month-heading">
            <h2 id="month-heading" class="text-sm font-semibold">Credit a month</h2>
            <div class="mt-2 flex flex-wrap items-end gap-3">
                <label class="text-sm"
                    >Month
                    <select v-model="month" class="input ml-1 w-auto">
                        <option value="">Choose…</option>
                        <option v-for="m in months" :key="m" :value="m" :disabled="m > today">
                            {{ monthLabel(m) }}{{ m > today ? ' (not started)' : '' }}
                        </option>
                    </select>
                </label>
                <button type="button" class="underline" :disabled="!month" @click="previewMonth">
                    Preview
                </button>
            </div>

            <div v-if="preview" class="mt-3 text-sm" aria-live="polite">
                <p>
                    {{ monthLabel(preview.period.start) }}: {{ preview.examined }} employment(s)
                    examined —
                    <span v-for="o in outcomes" :key="o.value"
                        >{{ o.label }}: {{ preview.counts[o.value] ?? 0 }};
                    </span>
                </p>
                <table v-if="preview.rows.length > 0" class="mt-2 w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-control-line">
                            <th scope="col" class="py-1 pr-2">Employee</th>
                            <th scope="col" class="py-1 pr-2">Outcome</th>
                            <th scope="col" class="py-1">Units</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in preview.rows"
                            :key="r.employmentRecordId"
                            class="border-b border-line"
                        >
                            <td class="py-1 pr-2">
                                {{ r.fullName ?? 'Unnamed' }} ({{ r.employeeNumber ?? '—' }})
                            </td>
                            <td class="py-1 pr-2">
                                {{ accrualOutcomeLabels[r.outcome] ?? r.outcome }}
                                <template v-if="r.outcome === 'not_due'">
                                    (from {{ r.postableOn }})</template
                                >
                                <span v-if="r.eligibilityDrift" class="text-ink">
                                    · no longer matches the eligibility rules</span
                                >
                            </td>
                            <td class="py-1">{{ r.units === null ? '' : units(r.units) }}</td>
                        </tr>
                    </tbody>
                </table>
                <button
                    v-if="preview.nextAfter"
                    type="button"
                    class="mt-2 underline"
                    @click="nextPreviewPage(preview.nextAfter)"
                >
                    Next employments
                </button>
                <div class="mt-3">
                    <button
                        v-if="!confirming"
                        type="button"
                        class="btn btn-primary"
                        :disabled="(preview.counts.credited ?? 0) === 0"
                        @click="askToRun"
                    >
                        Credit {{ preview.counts.credited ?? 0 }} employment(s)
                    </button>
                    <div
                        v-else
                        role="alertdialog"
                        aria-labelledby="run-confirm-heading"
                        class="rounded-xs border border-control-line p-3"
                    >
                        <h3
                            id="run-confirm-heading"
                            ref="confirmHeading"
                            tabindex="-1"
                            class="font-medium"
                        >
                            Credit {{ monthLabel(preview.period.start) }}?
                        </h3>
                        <p class="mt-1">
                            Each eligible employment is re-checked at the moment of posting; a month
                            already credited is never credited again.
                        </p>
                        <div class="mt-2 flex gap-3">
                            <button
                                type="button"
                                class="underline"
                                :disabled="runForm.processing"
                                @click="executeRun"
                            >
                                Confirm
                            </button>
                            <button type="button" class="underline" @click="confirming = false">
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section v-if="run" class="mt-6" aria-labelledby="run-heading">
            <h2 id="run-heading" class="text-sm font-semibold">
                Run for {{ monthLabel(run.periodStart) }} — {{ run.status }}
            </h2>
            <p class="mt-1 text-sm">
                {{ run.examined }} examined =
                <span v-for="o in outcomes" :key="o.value"
                    >{{ o.label }} {{ run.counts[o.value] ?? 0 }};
                </span>
                <template v-if="run.failureCode"> Stopped: {{ run.failureCode }}.</template>
            </p>
            <p v-if="run.status !== 'completed'" class="text-sm text-ink">
                Not finished: preview the month again and credit it to continue from where it
                stopped.
            </p>
            <label class="mt-2 block text-sm"
                >Show
                <select
                    :value="outcome"
                    class="input ml-1 w-auto"
                    @change="openRun(run.id, ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">Every outcome</option>
                    <option v-for="o in outcomes" :key="o.value" :value="o.value">
                        {{ o.label }}
                    </option>
                </select>
            </label>
            <ul class="mt-2 text-sm">
                <li v-for="i in run.items" :key="i.employmentRecordId">
                    {{ i.fullName ?? 'Unnamed' }} ({{ i.employeeNumber ?? '—' }}) ·
                    {{ accrualOutcomeLabels[i.outcome] ?? i.outcome }}
                    <template v-if="i.units !== null"> · {{ units(i.units) }}</template>
                    <span v-if="i.eligibilityDrift" class="text-ink">
                        · no longer matches the eligibility rules</span
                    >
                </li>
                <li v-if="run.items.length === 0" class="text-ink-muted">None.</li>
            </ul>
            <button
                v-if="run.nextAfter"
                type="button"
                class="mt-2 underline"
                @click="openRun(run.id, outcome, run.nextAfter)"
            >
                Next employments
            </button>
        </section>

        <section v-if="runs" class="mt-6" aria-labelledby="runs-heading">
            <h2 id="runs-heading" class="text-sm font-semibold">Runs</h2>
            <p v-if="runs.length === 0" class="mt-1 text-sm text-ink-muted">No run yet.</p>
            <ul class="mt-1 text-sm">
                <li v-for="r in runs" :key="r.id">
                    <button type="button" class="underline" @click="openRun(r.id)">
                        {{ monthLabel(r.periodStart) }}
                    </button>
                    · {{ r.status }} · {{ r.batchCount }} batch(es)
                    <template v-if="r.failureCode"> · {{ r.failureCode }}</template>
                </li>
            </ul>
        </section>

        <section v-if="grid" class="mt-6" aria-labelledby="grid-heading">
            <h2 id="grid-heading" class="text-sm font-semibold">Monthly grid (posted units)</h2>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Posted monthly accrual per employment; a blank cell means nothing is posted
                        for that month.
                    </caption>
                    <thead>
                        <tr class="border-b border-control-line">
                            <th scope="col" class="py-1 pr-2">Employee</th>
                            <th v-for="m in grid.months" :key="m" scope="col" class="py-1 pr-2">
                                {{ monthLabel(m).slice(0, 3) }}
                            </th>
                            <th scope="col" class="py-1 pr-2">Annual allocation</th>
                            <th scope="col" class="py-1">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in grid.rows"
                            :key="r.employmentRecordId"
                            class="border-b border-line"
                        >
                            <th scope="row" class="py-1 pr-2 font-normal">
                                <a
                                    class="underline"
                                    :href="`/app/leave/entitlements?employment_record_id=${r.employmentRecordId}&leave_year_id=${leaveYearId}`"
                                    >{{ r.fullName ?? 'Unnamed' }}</a
                                >
                                ({{ r.employeeNumber ?? '—' }})
                            </th>
                            <td v-for="(v, i) in r.months" :key="i" class="py-1 pr-2">
                                {{ cell(v) }}
                            </td>
                            <td class="py-1 pr-2">{{ cell(r.allocatedUnits) }}</td>
                            <td class="py-1">{{ cell(r.balanceUnits) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="grid.rows.length === 0" class="mt-1 text-sm text-ink-muted">
                No employment in this leave year.
            </p>
            <button
                v-if="grid.nextAfter"
                type="button"
                class="mt-2 underline"
                @click="nextGridPage(grid.nextAfter)"
            >
                Next employments
            </button>
        </section>
    </main>
</template>
