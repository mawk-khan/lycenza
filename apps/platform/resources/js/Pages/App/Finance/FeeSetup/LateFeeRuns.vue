<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import { formatMoney } from '../../../../money';

interface RunRow {
    id: string;
    ruleName: string | null;
    evaluationDate: string;
    status: string;
    currency: string;
    preview: { readyCount: number; readyAmount: string };
    execution: { succeededCount: number; failedCount: number; assessedAmount: string };
}

interface Props {
    runs: RunRow[];
    activeRules: Array<{ id: string; name: string }>;
    today: string;
    canRun: boolean;
}

const props = defineProps<Props>();
const page = usePage();
const actionError = computed(() => (page.props.errors as Record<string, string>).action);
const form = useForm({ late_fee_rule_id: '', evaluation_date: props.today });

function create(): void {
    form.post('/app/finance/late-fee-runs');
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance">← Finance</a>
        <h1 class="mt-2 text-xl font-semibold">Late-fee runs</h1>
        <p class="mt-1 text-sm text-slate-500">
            A run applies one active rule at an evaluation date. A charge is eligible only after its
            due date plus the grace days. Preview first -- a preview never creates a charge;
            execution recalculates from what is outstanding at that moment.
        </p>

        <p v-if="actionError" role="alert" class="mt-4 rounded bg-red-50 p-3 text-sm text-red-700">
            {{ actionError }}
        </p>

        <form
            v-if="canRun"
            class="mt-6 grid gap-3 rounded border border-slate-200 p-4 md:grid-cols-3"
            @submit.prevent="create"
        >
            <label class="text-sm md:col-span-2">
                <span class="block text-xs text-slate-500">Active late-fee rule</span>
                <select
                    v-model="form.late_fee_rule_id"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                    required
                >
                    <option value="" disabled>Choose…</option>
                    <option v-for="r in activeRules" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
                <span v-if="form.errors.late_fee_rule_id" class="text-xs text-red-600">{{
                    form.errors.late_fee_rule_id
                }}</span>
            </label>
            <label class="text-sm">
                <span class="block text-xs text-slate-500">Evaluation date</span>
                <input
                    v-model="form.evaluation_date"
                    type="date"
                    :max="today"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                />
                <span v-if="form.errors.evaluation_date" class="text-xs text-red-600">{{
                    form.errors.evaluation_date
                }}</span>
            </label>
            <div class="md:col-span-3">
                <button
                    type="submit"
                    class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Create run
                </button>
            </div>
        </form>

        <EmptyState
            v-if="runs.length === 0"
            class="mt-6"
            title="No late-fee runs yet"
            description="Activate a late-fee rule, then create a run."
        />

        <table v-else class="mt-6 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">Rule</th>
                    <th scope="col" class="py-2 font-medium">Evaluation date</th>
                    <th scope="col" class="py-2 font-medium">Status</th>
                    <th scope="col" class="py-2 text-right font-medium">Ready / assessed</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="r in runs" :key="r.id">
                    <td class="py-3">
                        <a class="underline" :href="`/app/finance/late-fee-runs/${r.id}`">{{
                            r.ruleName ?? r.id
                        }}</a>
                    </td>
                    <td class="py-3">{{ r.evaluationDate }}</td>
                    <td class="py-3 capitalize">{{ r.status.replaceAll('_', ' ') }}</td>
                    <td class="py-3 text-right">
                        <template v-if="['completed', 'completed_with_errors'].includes(r.status)">
                            {{ r.execution.succeededCount }} charged ·
                            {{ formatMoney(r.execution.assessedAmount, r.currency) }}
                        </template>
                        <template v-else>
                            {{ r.preview.readyCount }} ready ·
                            {{ formatMoney(r.preview.readyAmount, r.currency) }}
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>
    </main>
</template>
