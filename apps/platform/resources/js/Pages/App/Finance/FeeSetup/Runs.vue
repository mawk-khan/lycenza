<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import { formatMoney } from '../../../../money';

interface RunRow {
    id: string;
    structureLabel: string | null;
    billingPeriodKey: string;
    status: string;
    currency: string;
    preview: { readyCount: number; readyAmount: string };
    execution: { succeededCount: number; failedCount: number; assessedAmount: string };
    createdAt: string;
}

interface StructureOption {
    id: string;
    label: string;
    periods: Array<{ key: string; label: string }>;
}

interface Props {
    runs: RunRow[];
    activeStructures: StructureOption[];
    canRun: boolean;
}

const props = defineProps<Props>();
const page = usePage();
const actionError = computed(() => (page.props.errors as Record<string, string>).action);

const form = useForm({ fee_structure_id: '', billing_period_key: '' });
const periods = computed(
    () => props.activeStructures.find((s) => s.id === form.fee_structure_id)?.periods ?? [],
);

function create(): void {
    form.post('/app/finance/fee-runs');
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance">← Finance</a>
        <h1 class="mt-2 text-xl font-semibold">Fee assessment runs</h1>
        <p class="mt-1 text-sm text-slate-500">
            A run bills one billing period of one active fee structure to its Students. Preview
            first -- a preview never creates a charge. Amounts are the instalment amounts; nothing
            is prorated.
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
                <span class="block text-xs text-slate-500">Active fee structure</span>
                <select
                    v-model="form.fee_structure_id"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                    required
                    @change="form.billing_period_key = ''"
                >
                    <option value="" disabled>Choose…</option>
                    <option v-for="s in activeStructures" :key="s.id" :value="s.id">
                        {{ s.label }}
                    </option>
                </select>
                <span v-if="form.errors.fee_structure_id" class="text-xs text-red-600">{{
                    form.errors.fee_structure_id
                }}</span>
            </label>
            <label class="text-sm">
                <span class="block text-xs text-slate-500">Billing period</span>
                <select
                    v-model="form.billing_period_key"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                    required
                >
                    <option value="" disabled>Choose…</option>
                    <option v-for="p in periods" :key="p.key" :value="p.key">
                        {{ p.label }} ({{ p.key }})
                    </option>
                </select>
                <span v-if="form.errors.billing_period_key" class="text-xs text-red-600">{{
                    form.errors.billing_period_key
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
            title="No assessment runs yet"
            description="Activate a fee structure, then create a run for one of its billing periods."
        />

        <table v-else class="mt-6 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">Structure</th>
                    <th scope="col" class="py-2 font-medium">Period</th>
                    <th scope="col" class="py-2 font-medium">Status</th>
                    <th scope="col" class="py-2 text-right font-medium">Ready / assessed</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="r in runs" :key="r.id">
                    <td class="py-3">
                        <a class="underline" :href="`/app/finance/fee-runs/${r.id}`">{{
                            r.structureLabel ?? r.id
                        }}</a>
                    </td>
                    <td class="py-3 font-mono text-xs">{{ r.billingPeriodKey }}</td>
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
