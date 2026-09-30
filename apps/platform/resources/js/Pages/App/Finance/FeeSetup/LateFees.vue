<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatMoney } from '../../../../money';

interface RuleRow {
    id: string;
    name: string;
    feeStructureId: string;
    feeHeadId: string | null;
    lateFeeHeadId: string;
    graceDays: number;
    kind: 'fixed' | 'percentage';
    fixedAmount: string | null;
    percentage: string | null;
    maxAmount: string | null;
    currency: string;
    status: string;
    structureLabel: string | null;
    feeHeadLabel: string | null;
    lateFeeHeadLabel: string | null;
}

interface Props {
    rules: RuleRow[];
    structures: Array<{ id: string; label: string; headIds: string[] }>;
    feeHeads: Array<{ id: string; label: string }>;
    canManage: boolean;
}

const props = defineProps<Props>();
const page = usePage();
const actionError = computed(() => (page.props.errors as Record<string, string>).action);
const editing = ref<string | null>(null);

const blank = {
    fee_structure_id: '',
    name: '',
    fee_head_id: '',
    late_fee_head_id: '',
    grace_days: 0,
    kind: 'fixed' as 'fixed' | 'percentage',
    fixed_amount: '',
    percentage: '',
    max_amount: '',
};
const form = useForm({ ...blank });
const structureHeads = computed(() => {
    const ids = props.structures.find((s) => s.id === form.fee_structure_id)?.headIds ?? [];
    return props.feeHeads.filter((h) => ids.includes(h.id));
});

function edit(rule: RuleRow): void {
    editing.value = rule.id;
    form.defaults({
        fee_structure_id: rule.feeStructureId,
        name: rule.name,
        fee_head_id: rule.feeHeadId ?? '',
        late_fee_head_id: rule.lateFeeHeadId,
        grace_days: rule.graceDays,
        kind: rule.kind,
        fixed_amount: rule.fixedAmount ?? '',
        percentage: rule.percentage ?? '',
        max_amount: rule.maxAmount ?? '',
    });
    form.reset();
}

function startNew(): void {
    editing.value = null;
    form.defaults({ ...blank });
    form.reset();
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        fee_head_id: data.fee_head_id || null,
        fixed_amount: data.kind === 'fixed' ? data.fixed_amount : null,
        percentage: data.kind === 'percentage' ? data.percentage : null,
        max_amount: data.max_amount || null,
    })).post(editing.value ? `/app/finance/late-fees/${editing.value}` : '/app/finance/late-fees', {
        preserveScroll: true,
        onSuccess: () => startNew(),
    });
}

function setStatus(rule: RuleRow, status: 'active' | 'inactive'): void {
    router.post(`/app/finance/late-fees/${rule.id}/status`, { status }, { preserveScroll: true });
}

function valueLabel(rule: RuleRow): string {
    const base =
        rule.kind === 'fixed'
            ? formatMoney(rule.fixedAmount ?? '0.00', rule.currency)
            : `${rule.percentage}% of outstanding`;
    return rule.maxAmount
        ? `${base}, capped at ${formatMoney(rule.maxAmount, rule.currency)}`
        : base;
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance">← Finance</a>
        <h1 class="mt-2 text-xl font-semibold">Late-fee rules</h1>
        <p class="mt-1 text-sm text-slate-500">
            A rule adds one late fee to an overdue structure charge after its grace days: a fixed
            amount, or a percentage of what is still outstanding, optionally capped. Each charge
            gets at most one late fee per rule. There are no recurring, daily or compounding
            charges, and a late fee never attracts a late fee.
        </p>
        <p class="mt-2 text-sm">
            <a class="underline" href="/app/finance/late-fee-runs">Late-fee runs</a> preview and
            apply a rule.
        </p>

        <p v-if="actionError" role="alert" class="mt-4 rounded bg-red-50 p-3 text-sm text-red-700">
            {{ actionError }}
        </p>

        <table v-if="rules.length" class="mt-6 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">Rule</th>
                    <th scope="col" class="py-2 font-medium">Applies to</th>
                    <th scope="col" class="py-2 font-medium">Late fee</th>
                    <th scope="col" class="py-2 font-medium">Grace</th>
                    <th scope="col" class="py-2 font-medium">Status</th>
                    <th scope="col" class="py-2"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="r in rules" :key="r.id">
                    <td class="py-2">
                        {{ r.name }}
                        <span class="block text-xs text-slate-500"
                            >charged as {{ r.lateFeeHeadLabel }}</span
                        >
                    </td>
                    <td class="py-2">
                        {{ r.structureLabel }}
                        <span class="block text-xs text-slate-500">{{
                            r.feeHeadLabel ?? 'Every fee head'
                        }}</span>
                    </td>
                    <td class="py-2">{{ valueLabel(r) }}</td>
                    <td class="py-2">{{ r.graceDays }} day(s)</td>
                    <td class="py-2 capitalize">{{ r.status }}</td>
                    <td class="py-2 text-right">
                        <template v-if="canManage">
                            <button
                                v-if="r.status === 'inactive'"
                                type="button"
                                class="text-xs underline"
                                @click="edit(r)"
                            >
                                Edit
                            </button>
                            <button
                                type="button"
                                class="ml-2 text-xs underline"
                                @click="setStatus(r, r.status === 'active' ? 'inactive' : 'active')"
                            >
                                {{ r.status === 'active' ? 'Deactivate' : 'Activate' }}
                            </button>
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-else class="mt-6 text-sm text-slate-500">No late-fee rules yet.</p>

        <form
            v-if="canManage"
            class="mt-8 grid gap-3 rounded border border-slate-200 p-4 md:grid-cols-3"
            @submit.prevent="submit"
        >
            <h2 class="text-sm font-medium md:col-span-3">
                {{ editing ? 'Edit rule (inactive rules only)' : 'New rule (created inactive)' }}
            </h2>
            <label class="text-sm">
                <span class="block text-xs text-slate-500">Name</span>
                <input
                    v-model="form.name"
                    maxlength="120"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                    required
                />
                <span v-if="form.errors.name" class="text-xs text-red-600">{{
                    form.errors.name
                }}</span>
            </label>
            <label class="text-sm">
                <span class="block text-xs text-slate-500">Fee structure</span>
                <select
                    v-model="form.fee_structure_id"
                    :disabled="editing !== null"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                    required
                >
                    <option value="" disabled>Choose…</option>
                    <option v-for="s in structures" :key="s.id" :value="s.id">{{ s.label }}</option>
                </select>
                <span v-if="form.errors.fee_structure_id" class="text-xs text-red-600">{{
                    form.errors.fee_structure_id
                }}</span>
            </label>
            <label class="text-sm">
                <span class="block text-xs text-slate-500">Fee head in scope</span>
                <select
                    v-model="form.fee_head_id"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                >
                    <option value="">Every fee head of the structure</option>
                    <option v-for="h in structureHeads" :key="h.id" :value="h.id">
                        {{ h.label }}
                    </option>
                </select>
            </label>
            <label class="text-sm">
                <span class="block text-xs text-slate-500">Charged as (late-fee head)</span>
                <select
                    v-model="form.late_fee_head_id"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                    required
                >
                    <option value="" disabled>Choose…</option>
                    <option v-for="h in feeHeads" :key="h.id" :value="h.id">{{ h.label }}</option>
                </select>
                <span v-if="form.errors.late_fee_head_id" class="text-xs text-red-600">{{
                    form.errors.late_fee_head_id
                }}</span>
            </label>
            <label class="text-sm">
                <span class="block text-xs text-slate-500">Grace days after the due date</span>
                <input
                    v-model.number="form.grace_days"
                    type="number"
                    min="0"
                    max="3650"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                    required
                />
            </label>
            <label class="text-sm">
                <span class="block text-xs text-slate-500">Calculation</span>
                <select
                    v-model="form.kind"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                >
                    <option value="fixed">Fixed amount</option>
                    <option value="percentage">Percentage of outstanding</option>
                </select>
            </label>
            <label v-if="form.kind === 'fixed'" class="text-sm">
                <span class="block text-xs text-slate-500">Amount (INR)</span>
                <input
                    v-model="form.fixed_amount"
                    inputmode="decimal"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1 font-mono"
                    required
                />
                <span v-if="form.errors.fixed_amount" class="text-xs text-red-600">{{
                    form.errors.fixed_amount
                }}</span>
            </label>
            <label v-else class="text-sm">
                <span class="block text-xs text-slate-500">Percentage</span>
                <input
                    v-model="form.percentage"
                    inputmode="decimal"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1 font-mono"
                    required
                />
                <span v-if="form.errors.percentage" class="text-xs text-red-600">{{
                    form.errors.percentage
                }}</span>
            </label>
            <label class="text-sm">
                <span class="block text-xs text-slate-500">Cap (optional, INR)</span>
                <input
                    v-model="form.max_amount"
                    inputmode="decimal"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1 font-mono"
                />
                <span v-if="form.errors.max_amount" class="text-xs text-red-600">{{
                    form.errors.max_amount
                }}</span>
            </label>
            <div class="flex items-end gap-2 md:col-span-3">
                <button
                    type="submit"
                    class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                    :disabled="form.processing"
                >
                    {{ editing ? 'Save rule' : 'Create rule' }}
                </button>
                <button v-if="editing" type="button" class="text-sm underline" @click="startNew">
                    Cancel edit
                </button>
            </div>
        </form>
    </main>
</template>
