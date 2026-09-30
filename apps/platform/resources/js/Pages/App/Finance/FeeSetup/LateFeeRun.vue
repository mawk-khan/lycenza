<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Pagination from '../../../../Components/Pagination.vue';
import { formatMoney } from '../../../../money';

interface Item {
    id: string;
    sourceChargeId: string;
    studentName: string | null;
    studentNumber: string | null;
    feeHeadCode: string | null;
    billingPeriodKey: string;
    dueDate: string;
    finalGraceDate: string;
    outstandingAmount: string;
    calculatedAmount: string;
    finalAmount: string;
    capApplied: boolean;
    currency: string;
    previewResult: string;
    reason: string | null;
    executionStatus: string | null;
    failureReason: string | null;
    executedOutstandingAmount: string | null;
    executedAmount: string | null;
    lateFeeAssessmentId: string | null;
    lateFeeChargeId: string | null;
    lateFeeVoided: boolean;
}

interface Props {
    run: {
        id: string;
        evaluationDate: string;
        status: string;
        currency: string;
        preview: {
            readyCount: number;
            readyAmount: string;
            notEligibleCount: number;
            alreadyAssessedCount: number;
        };
        execution: {
            succeededCount: number;
            skippedCount: number;
            failedCount: number;
            assessedAmount: string;
        };
        rule: {
            name: string;
            kind: string;
            fixedAmount: string | null;
            percentage: string | null;
            maxAmount: string | null;
            graceDays: number;
        } | null;
    };
    items: { data: Item[]; links: Array<{ url: string | null; label: string; active: boolean }> };
    filters: { preview_result: string };
    canRun: boolean;
    canVoid: boolean;
}

const props = defineProps<Props>();
const page = usePage();
const actionError = computed(() => (page.props.errors as Record<string, string>).action);
const base = `/app/finance/late-fee-runs/${props.run.id}`;

function act(action: string, question?: string): void {
    if (question && !window.confirm(question)) return;
    router.post(`${base}/${action}`, {}, { preserveScroll: true });
}

function voidLateFee(item: Item): void {
    if (
        window.confirm(
            'Void this late fee? Its charge is reversed; refused if a payment was applied to it.',
        )
    ) {
        router.post(
            `/app/finance/late-fee-assessments/${item.lateFeeAssessmentId}/void`,
            {},
            { preserveScroll: true },
        );
    }
}

function filter(result: string): void {
    router.get(base, { preview_result: result || undefined }, { preserveState: true });
}
</script>

<template>
    <main class="mx-auto max-w-6xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance/late-fee-runs">← Late-fee runs</a>
        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ run.rule?.name ?? 'Late-fee run' }}</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Evaluated on {{ run.evaluationDate }} ·
                    <span class="capitalize">{{ run.status.replaceAll('_', ' ') }}</span>
                    <template v-if="run.rule">
                        ·
                        {{
                            run.rule.kind === 'fixed'
                                ? formatMoney(run.rule.fixedAmount ?? '0.00', run.currency)
                                : `${run.rule.percentage}% of outstanding`
                        }}
                        <template v-if="run.rule.maxAmount"
                            >, capped at
                            {{ formatMoney(run.rule.maxAmount, run.currency) }}</template
                        >
                        · {{ run.rule.graceDays }} grace day(s)
                    </template>
                </p>
            </div>
            <div v-if="canRun" class="flex gap-2">
                <button
                    v-if="['draft', 'previewed'].includes(run.status)"
                    type="button"
                    class="rounded border border-slate-300 px-3 py-1.5 text-sm"
                    @click="act('preview')"
                >
                    {{ run.status === 'draft' ? 'Preview' : 'Preview again' }}
                </button>
                <button
                    v-if="run.status === 'previewed'"
                    type="button"
                    class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white"
                    @click="
                        act(
                            'execute',
                            `Charge ${run.preview.readyCount} late fee(s)? Amounts are recalculated from what is outstanding now.`,
                        )
                    "
                >
                    Execute
                </button>
                <button
                    v-if="run.status === 'executing'"
                    type="button"
                    class="rounded border border-slate-300 px-3 py-1.5 text-sm"
                    @click="act('resume')"
                >
                    Resume
                </button>
                <button
                    v-if="['draft', 'previewed'].includes(run.status)"
                    type="button"
                    class="rounded border border-red-300 px-3 py-1.5 text-sm text-red-700"
                    @click="act('cancel', 'Cancel this run?')"
                >
                    Cancel run
                </button>
            </div>
        </div>

        <p v-if="actionError" role="alert" class="mt-4 rounded bg-red-50 p-3 text-sm text-red-700">
            {{ actionError }}
        </p>

        <dl class="mt-6 grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-slate-500">Ready</dt>
                <dd>
                    {{ run.preview.readyCount }} ·
                    {{ formatMoney(run.preview.readyAmount, run.currency) }}
                </dd>
            </div>
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-slate-500">Not eligible / already charged</dt>
                <dd>{{ run.preview.notEligibleCount }} / {{ run.preview.alreadyAssessedCount }}</dd>
            </div>
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-slate-500">Charged</dt>
                <dd>
                    {{ run.execution.succeededCount }} ·
                    {{ formatMoney(run.execution.assessedAmount, run.currency) }}
                </dd>
            </div>
            <div class="rounded border border-slate-200 p-3">
                <dt class="text-slate-500">Skipped / failed</dt>
                <dd>{{ run.execution.skippedCount }} / {{ run.execution.failedCount }}</dd>
            </div>
        </dl>

        <div class="mt-6 flex gap-2 text-sm">
            <button
                v-for="f in ['', 'ready', 'not_eligible', 'already_assessed']"
                :key="f"
                type="button"
                class="rounded-full border px-3 py-1"
                :class="
                    filters.preview_result === f
                        ? 'border-slate-900 bg-slate-900 text-white'
                        : 'border-slate-300'
                "
                @click="filter(f)"
            >
                {{ f === '' ? 'All' : f.replaceAll('_', ' ') }}
            </button>
        </div>

        <table class="mt-4 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">Student</th>
                    <th scope="col" class="py-2 font-medium">Source charge</th>
                    <th scope="col" class="py-2 text-right font-medium">Outstanding</th>
                    <th scope="col" class="py-2 text-right font-medium">Late fee</th>
                    <th scope="col" class="py-2 pl-4 font-medium">Result</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="i in items.data" :key="i.id">
                    <td class="py-2">
                        {{ i.studentName ?? 'Student' }}
                        <span v-if="i.studentNumber" class="block text-xs text-slate-500">{{
                            i.studentNumber
                        }}</span>
                    </td>
                    <td class="py-2">
                        <a class="underline" :href="`/app/finance/charges/${i.sourceChargeId}`"
                            >{{ i.feeHeadCode }} · {{ i.billingPeriodKey }}</a
                        >
                        <span class="block text-xs text-slate-500"
                            >due {{ i.dueDate }}, grace to {{ i.finalGraceDate }}</span
                        >
                    </td>
                    <td class="py-2 text-right font-mono">
                        {{
                            formatMoney(
                                i.executedOutstandingAmount ?? i.outstandingAmount,
                                i.currency,
                            )
                        }}
                    </td>
                    <td class="py-2 text-right font-mono">
                        {{ formatMoney(i.executedAmount ?? i.finalAmount, i.currency) }}
                        <span v-if="i.capApplied" class="block text-xs text-slate-500"
                            >capped from {{ formatMoney(i.calculatedAmount, i.currency) }}</span
                        >
                    </td>
                    <td class="py-2 pl-4 text-xs">
                        <span class="capitalize">{{
                            (i.executionStatus ?? i.previewResult).replaceAll('_', ' ')
                        }}</span>
                        <span v-if="i.failureReason ?? i.reason" class="block text-slate-500">{{
                            (i.failureReason ?? i.reason ?? '').replaceAll('_', ' ')
                        }}</span>
                        <a
                            v-if="i.lateFeeChargeId"
                            class="block underline"
                            :href="`/app/finance/charges/${i.lateFeeChargeId}`"
                            >late-fee charge</a
                        >
                        <span v-if="i.lateFeeVoided" class="block text-slate-500">voided</span>
                        <button
                            v-else-if="canVoid && i.lateFeeAssessmentId"
                            type="button"
                            class="block text-red-700 underline"
                            @click="voidLateFee(i)"
                        >
                            Void
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
        <Pagination class="mt-4" :links="items.links" />
    </main>
</template>
