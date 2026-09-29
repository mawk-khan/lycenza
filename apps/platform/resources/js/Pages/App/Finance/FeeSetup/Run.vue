<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import Pagination from '../../../../Components/Pagination.vue';
import { formatMoney } from '../../../../money';

interface Run {
    id: string;
    structureLabel: string | null;
    billingPeriodKey: string;
    status: string;
    previewIsCurrent: boolean;
    currency: string;
    preview: {
        readyCount: number;
        readyAmount: string;
        excludedCount: number;
        blockedCount: number;
        alreadyAssessedCount: number;
    };
    execution: {
        succeededCount: number;
        skippedCount: number;
        failedCount: number;
        assessedAmount: string;
    };
    previewedAt: string | null;
    executionStartedAt: string | null;
    completedAt: string | null;
}

interface Item {
    id: string;
    studentName: string | null;
    studentNumber: string | null;
    feeHeadCode: string | null;
    enrollmentStartsOn: string | null;
    amount: string;
    currency: string;
    previewResult: string;
    reason: string | null;
    excludedAt: string | null;
    executionStatus: string | null;
    failureReason: string | null;
    feeAssessmentId: string | null;
}

interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Props {
    run: Run;
    items: Paginated<Item>;
    filters: { preview_result: string };
    canRun: boolean;
    canVoid: boolean;
}

const props = defineProps<Props>();
const page = usePage();
const actionError = computed(() => (page.props.errors as Record<string, string>).action);
const base = computed(() => `/app/finance/fee-runs/${props.run.id}`);
const isOpen = computed(() => ['draft', 'previewed'].includes(props.run.status));

const reasonText: Record<string, string> = {
    optional_not_selected: 'Optional fee not chosen',
    period_before_enrollment: 'Period ended before enrollment',
    enrollment_cancelled: 'Enrollment cancelled',
    student_inactive: 'Student inactive',
    staff_excluded: 'Excluded by staff',
    no_structure: 'No fee structure',
    head_inactive: 'Fee head inactive',
    account_invalid: 'Ledger account invalid',
};

function post(url: string, confirmText?: string, data: Record<string, unknown> = {}): void {
    if (confirmText && !window.confirm(confirmText)) return;
    router.post(url, data as never, { preserveScroll: true });
}

function filter(result: string): void {
    router.get(base.value, result ? { preview_result: result } : {}, { preserveScroll: true });
}

// While executing, refresh the run every few seconds to show progress.
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    if (props.run.status === 'executing') {
        timer = setInterval(() => router.reload({ only: ['run', 'items'] }), 4000);
    }
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance/fee-runs">← Assessment runs</a>
        <h1 class="mt-2 text-xl font-semibold">
            {{ run.structureLabel }} · <span class="font-mono">{{ run.billingPeriodKey }}</span>
        </h1>
        <p class="mt-1 text-sm">
            Status:
            <span class="font-medium capitalize">{{ run.status.replaceAll('_', ' ') }}</span>
            <span v-if="run.status === 'draft' && run.previewedAt" class="ml-2 text-amber-700">
                -- changed since the last preview; preview again before executing.
            </span>
        </p>

        <p v-if="actionError" role="alert" class="mt-4 rounded bg-red-50 p-3 text-sm text-red-700">
            {{ actionError }}
        </p>

        <section class="mt-4 grid gap-3 text-sm md:grid-cols-2">
            <div class="rounded border border-slate-200 p-3">
                <h2 class="font-medium">Preview (no charges created)</h2>
                <p class="mt-1">
                    {{ run.preview.readyCount }} ready ·
                    {{ formatMoney(run.preview.readyAmount, run.currency) }}
                </p>
                <p class="text-slate-600">
                    {{ run.preview.excludedCount }} excluded ·
                    {{ run.preview.blockedCount }} blocked ·
                    {{ run.preview.alreadyAssessedCount }} already assessed
                </p>
            </div>
            <div
                v-if="!isOpen && run.status !== 'cancelled'"
                class="rounded border border-slate-200 p-3"
            >
                <h2 class="font-medium">Execution</h2>
                <p class="mt-1">
                    {{ run.execution.succeededCount }} charged ·
                    {{ formatMoney(run.execution.assessedAmount, run.currency) }}
                </p>
                <p class="text-slate-600">
                    {{ run.execution.skippedCount }} already assessed ·
                    <span :class="run.execution.failedCount ? 'text-red-700' : ''"
                        >{{ run.execution.failedCount }} failed</span
                    >
                </p>
                <p v-if="run.status === 'executing'" class="mt-1 text-amber-700">
                    Running… this page refreshes itself.
                </p>
            </div>
        </section>

        <div v-if="canRun" class="mt-4 flex flex-wrap gap-3 text-sm">
            <button
                v-if="isOpen"
                type="button"
                class="rounded border border-slate-300 px-3 py-1.5"
                @click="post(`${base}/preview`)"
            >
                {{ run.previewedAt ? 'Preview again' : 'Preview' }}
            </button>
            <button
                v-if="run.status === 'previewed' && run.previewIsCurrent"
                type="button"
                class="rounded bg-emerald-700 px-3 py-1.5 text-white"
                @click="
                    post(
                        `${base}/execute`,
                        `Charge ${run.preview.readyCount} ready item(s) now? Each creates a charge and a ledger entry.`,
                    )
                "
            >
                Execute
            </button>
            <button
                v-if="run.status === 'executing'"
                type="button"
                class="rounded border border-slate-300 px-3 py-1.5"
                @click="post(`${base}/resume`)"
            >
                Resume
            </button>
            <button
                v-if="isOpen"
                type="button"
                class="rounded border border-slate-300 px-3 py-1.5"
                @click="post(`${base}/cancel`, 'Cancel this run? Nothing has been charged.')"
            >
                Cancel run
            </button>
        </div>

        <section class="mt-8">
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <h2 class="text-lg font-semibold">Items</h2>
                <select
                    :value="filters.preview_result"
                    class="rounded border border-slate-300 px-2 py-1"
                    @change="filter(($event.target as HTMLSelectElement).value)"
                >
                    <option value="">All</option>
                    <option value="ready">Ready</option>
                    <option value="excluded">Excluded</option>
                    <option value="blocked">Blocked</option>
                    <option value="already_assessed">Already assessed</option>
                </select>
            </div>

            <p v-if="items.data.length === 0" class="mt-3 text-sm text-slate-500">
                {{ run.previewedAt ? 'No items.' : 'Preview the run to see its items.' }}
            </p>

            <table v-else class="mt-3 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Student</th>
                        <th scope="col" class="py-2 font-medium">Fee</th>
                        <th scope="col" class="py-2 text-right font-medium">Amount</th>
                        <th scope="col" class="py-2 font-medium">Preview</th>
                        <th scope="col" class="py-2 font-medium">Execution</th>
                        <th scope="col" class="py-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="i in items.data" :key="i.id">
                        <td class="py-2">
                            {{ i.studentName }}
                            <span class="text-xs text-slate-500">{{ i.studentNumber }}</span>
                            <span v-if="i.enrollmentStartsOn" class="block text-xs text-slate-500"
                                >enrolled from {{ i.enrollmentStartsOn }}</span
                            >
                        </td>
                        <td class="py-2 font-mono text-xs">{{ i.feeHeadCode }}</td>
                        <td class="py-2 text-right">{{ formatMoney(i.amount, i.currency) }}</td>
                        <td class="py-2">
                            <span class="capitalize">{{
                                i.previewResult.replaceAll('_', ' ')
                            }}</span>
                            <span v-if="i.reason" class="block text-xs text-slate-500">{{
                                reasonText[i.reason] ?? i.reason
                            }}</span>
                        </td>
                        <td class="py-2">
                            <span v-if="i.executionStatus" class="capitalize">{{
                                i.executionStatus.replaceAll('_', ' ')
                            }}</span>
                            <span v-if="i.failureReason" class="block text-xs text-red-700">{{
                                i.failureReason.replaceAll('_', ' ')
                            }}</span>
                        </td>
                        <td class="py-2 text-right text-xs">
                            <button
                                v-if="canRun && isOpen && i.previewResult === 'ready'"
                                type="button"
                                class="underline"
                                @click="
                                    post(
                                        `${base}/items/${i.id}/exclude`,
                                        'Exclude this item from the run? The fee structure and amount do not change; the run must be previewed again.',
                                    )
                                "
                            >
                                Exclude
                            </button>
                            <button
                                v-if="
                                    canVoid &&
                                    i.executionStatus === 'succeeded' &&
                                    i.feeAssessmentId
                                "
                                type="button"
                                class="underline"
                                @click="
                                    post(
                                        `/app/finance/fee-assessments/${i.feeAssessmentId}/void`,
                                        'Void this assessment and cancel its charge? Refused if a payment is allocated to it.',
                                    )
                                "
                            >
                                Void
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <Pagination class="mt-4" :links="items.links" />
        </section>
    </main>
</template>
