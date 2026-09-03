<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface StructureComponent {
    id: string;
    salaryComponentId: string;
    salaryComponentCode: string | null;
    salaryComponentName: string | null;
    calculationType: 'fixed_amount' | 'percentage_of_base';
    baseComponentId: string | null;
    rate: string | null;
    displayOrder: number;
}

interface Props {
    structure: {
        id: string;
        code: string;
        version: number;
        name: string;
        status: 'draft' | 'active' | 'inactive';
        components: StructureComponent[];
    };
    revisions: Array<{ id: string; code: string; version: number; name: string; status: string }>;
    availableComponents: Array<{ id: string; code: string; name: string; type: string }>;
    canManage: boolean;
}

const props = defineProps<Props>();

const showForm = ref(false);
const form = useForm({
    salary_component_id: '',
    calculation_type: 'fixed_amount' as 'fixed_amount' | 'percentage_of_base',
    base_component_id: '',
    rate: '',
    display_order: props.structure.components.length + 1,
});

function submit(): void {
    form.post(`/app/payroll/structures/${props.structure.id}/components`, {
        onSuccess: () => {
            showForm.value = false;
            form.reset();
            form.display_order = props.structure.components.length + 1;
        },
    });
}

const activating = ref(false);

function activate(): void {
    if (
        !window.confirm(
            'Activate this structure revision? Once active it becomes immutable -- components can never be added, changed, or reordered again.',
        )
    )
        return;

    activating.value = true;
    router.post(
        `/app/payroll/structures/${props.structure.id}/activate`,
        {},
        { onFinish: () => (activating.value = false) },
    );
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/payroll/structures">← Salary Structures</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ structure.name }}</h1>
                <p class="mt-1 text-sm text-slate-500">
                    <span class="font-mono">{{ structure.code }}</span> · v{{ structure.version }}
                </p>
            </div>
            <StatusBadge :status="structure.status" />
        </div>

        <section v-if="revisions.length > 1" class="mt-4 text-sm text-slate-600">
            <span class="text-slate-500">Other revisions: </span>
            <template v-for="(r, i) in revisions" :key="r.id">
                <a
                    v-if="r.id !== structure.id"
                    class="underline"
                    :href="`/app/payroll/structures/${r.id}`"
                    >v{{ r.version }} ({{ r.status }})</a
                >
                <span v-else class="font-medium">v{{ r.version }} (this)</span>
                <span v-if="i < revisions.length - 1">, </span>
            </template>
        </section>

        <table class="mt-6 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">#</th>
                    <th scope="col" class="py-2 font-medium">Component</th>
                    <th scope="col" class="py-2 font-medium">Calculation</th>
                    <th scope="col" class="py-2 font-medium">Base / Rate</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="c in structure.components" :key="c.id">
                    <td class="py-3">{{ c.displayOrder }}</td>
                    <td class="py-3">
                        <span class="font-mono text-xs text-slate-500">{{
                            c.salaryComponentCode
                        }}</span>
                        {{ c.salaryComponentName }}
                    </td>
                    <td class="py-3">
                        {{
                            c.calculationType === 'fixed_amount'
                                ? 'Fixed amount'
                                : 'Percentage of base'
                        }}
                    </td>
                    <td class="py-3">
                        <span v-if="c.calculationType === 'percentage_of_base'"
                            >{{ (Number(c.rate) * 100).toFixed(2) }}%</span
                        >
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-if="structure.components.length === 0" class="mt-4 text-sm text-slate-500">
            No components on this revision yet.
        </p>

        <section v-if="canManage" class="mt-8 border-t border-slate-200 pt-6">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-900">Draft -- editable</h2>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded border border-slate-300 px-3 py-1.5 text-sm"
                        @click="showForm = !showForm"
                    >
                        {{ showForm ? 'Cancel' : 'Add component' }}
                    </button>
                    <button
                        type="button"
                        :disabled="activating || structure.components.length === 0"
                        class="rounded bg-emerald-700 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                        @click="activate"
                    >
                        Activate
                    </button>
                </div>
            </div>

            <form
                v-if="showForm"
                class="mt-4 space-y-3 rounded border border-slate-200 p-4"
                @submit.prevent="submit"
            >
                <div>
                    <label class="block text-sm text-slate-600" for="salary_component_id"
                        >Component</label
                    >
                    <select
                        id="salary_component_id"
                        v-model="form.salary_component_id"
                        class="mt-1 w-full max-w-md rounded border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="" disabled>Select…</option>
                        <option v-for="c in availableComponents" :key="c.id" :value="c.id">
                            {{ c.code }} — {{ c.name }}
                        </option>
                    </select>
                    <p v-if="form.errors.salary_component_id" class="mt-1 text-sm text-red-600">
                        {{ form.errors.salary_component_id }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-600" for="calculation_type"
                        >Calculation</label
                    >
                    <select
                        id="calculation_type"
                        v-model="form.calculation_type"
                        class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="fixed_amount">Fixed amount</option>
                        <option value="percentage_of_base">Percentage of base</option>
                    </select>
                </div>
                <div
                    v-if="form.calculation_type === 'percentage_of_base'"
                    class="grid grid-cols-2 gap-3"
                >
                    <div>
                        <label class="block text-sm text-slate-600" for="base_component_id"
                            >Base component</label
                        >
                        <select
                            id="base_component_id"
                            v-model="form.base_component_id"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="" disabled>Select…</option>
                            <option
                                v-for="c in structure.components"
                                :key="c.id"
                                :value="c.salaryComponentId"
                            >
                                {{ c.salaryComponentCode }} — {{ c.salaryComponentName }}
                            </option>
                        </select>
                        <p v-if="form.errors.base_component_id" class="mt-1 text-sm text-red-600">
                            {{ form.errors.base_component_id }}
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600" for="rate"
                            >Rate (0-1, e.g. 0.10 for 10%)</label
                        >
                        <input
                            id="rate"
                            v-model="form.rate"
                            type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                        <p v-if="form.errors.rate" class="mt-1 text-sm text-red-600">
                            {{ form.errors.rate }}
                        </p>
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-slate-600" for="display_order">Order</label>
                    <input
                        id="display_order"
                        v-model.number="form.display_order"
                        type="number"
                        min="1"
                        class="mt-1 w-24 rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <p v-if="form.errors.display_order" class="mt-1 text-sm text-red-600">
                        {{ form.errors.display_order }}
                    </p>
                </div>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                >
                    {{ form.processing ? 'Adding…' : 'Add component' }}
                </button>
            </form>
        </section>
    </main>
</template>
