<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import { formatMoney } from '../../../../money';

interface Assignment {
    id: string;
    salaryStructureId: string;
    effectiveFrom: string;
    effectiveTo: string | null;
}

interface FixedComponent {
    salaryStructureComponentId: string;
    salaryComponentId: string;
}

interface StructureOption {
    id: string;
    code: string;
    version: number;
    name: string;
    fixedComponents: FixedComponent[];
}

interface Props {
    employmentRecord: {
        id: string;
        employeeId: string;
        employeeFullName: string | null;
        employeeNumber: string | null;
    };
    assignments: Assignment[];
    structureOptions: StructureOption[];
    canViewSensitive: boolean;
    canManageSensitive: boolean;
}

const props = defineProps<Props>();

// Revealed values are fetched ONLY on explicit user action, per
// assignment, and never preloaded into this page's props -- an actor
// lacking payroll.compensation.sensitive.view can never obtain a value
// through devtools because the server never transmits it to them at all.
const revealed = reactive<
    Record<string, Array<{ salaryStructureComponentId: string; amount: string }>>
>({});
const revealing = ref<string | null>(null);

async function reveal(assignmentId: string): Promise<void> {
    revealing.value = assignmentId;
    try {
        const response = await fetch(
            `/app/payroll/compensation-assignments/${assignmentId}/values`,
            {
                headers: { Accept: 'application/json' },
            },
        );
        if (!response.ok) return;
        const body = await response.json();
        revealed[assignmentId] = body.data ?? [];
    } finally {
        revealing.value = null;
    }
}

function structureFor(id: string): StructureOption | undefined {
    return props.structureOptions.find((s) => s.id === id);
}

const showForm = ref(false);
const form = useForm({
    salary_structure_id: '',
    effective_from: '',
    fixed_values: [] as Array<{ salary_structure_component_id: string; amount: string }>,
});

function selectStructure(id: string): void {
    form.salary_structure_id = id;
    const structure = structureFor(id);
    form.fixed_values = (structure?.fixedComponents ?? []).map((c) => ({
        salary_structure_component_id: c.salaryStructureComponentId,
        amount: '',
    }));
}

function submit(): void {
    form.post(`/app/payroll/compensation/${props.employmentRecord.id}`, {
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/payroll/compensation">← Employee Compensation</a>

        <h1 class="mt-2 text-xl font-semibold">
            {{ employmentRecord.employeeFullName ?? employmentRecord.id }}
        </h1>
        <p v-if="employmentRecord.employeeNumber" class="mt-1 text-sm text-slate-500">
            {{ employmentRecord.employeeNumber }}
        </p>

        <div class="mt-6 flex items-center justify-between">
            <h2 class="text-sm font-medium text-slate-900">Compensation assignments</h2>
            <button
                v-if="canManageSensitive"
                type="button"
                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white"
                @click="showForm = !showForm"
            >
                {{ showForm ? 'Cancel' : 'Assign new compensation' }}
            </button>
        </div>

        <form
            v-if="showForm"
            class="mt-4 space-y-3 rounded border border-slate-200 p-4"
            @submit.prevent="submit"
        >
            <div>
                <label class="block text-sm text-slate-600" for="salary_structure_id"
                    >Salary structure</label
                >
                <select
                    id="salary_structure_id"
                    :value="form.salary_structure_id"
                    class="mt-1 w-full max-w-md rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="selectStructure(($event.target as HTMLSelectElement).value)"
                >
                    <option value="" disabled>Select…</option>
                    <option v-for="s in structureOptions" :key="s.id" :value="s.id">
                        {{ s.code }} v{{ s.version }} — {{ s.name }}
                    </option>
                </select>
                <p v-if="form.errors.salary_structure_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.salary_structure_id }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="effective_from"
                    >Effective from</label
                >
                <input
                    id="effective_from"
                    v-model="form.effective_from"
                    type="date"
                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.effective_from" class="mt-1 text-sm text-red-600">
                    {{ form.errors.effective_from }}
                </p>
            </div>

            <div v-if="form.fixed_values.length > 0" class="space-y-2">
                <p class="text-sm text-slate-600">Fixed component amounts</p>
                <div
                    v-for="(v, i) in form.fixed_values"
                    :key="v.salary_structure_component_id"
                    class="flex items-center gap-2"
                >
                    <span class="w-48 text-xs text-slate-500">Component {{ i + 1 }}</span>
                    <input
                        v-model="v.amount"
                        type="text"
                        placeholder="0.00"
                        class="w-32 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    />
                </div>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? 'Assigning…' : 'Assign compensation' }}
            </button>
        </form>

        <table class="mt-4 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">Effective from</th>
                    <th scope="col" class="py-2 font-medium">Effective to</th>
                    <th v-if="canViewSensitive" scope="col" class="py-2 font-medium">Amounts</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="a in assignments" :key="a.id">
                    <td class="py-3">{{ a.effectiveFrom }}</td>
                    <td class="py-3">{{ a.effectiveTo ?? 'Current' }}</td>
                    <td v-if="canViewSensitive" class="py-3">
                        <button
                            v-if="!revealed[a.id]"
                            type="button"
                            :disabled="revealing === a.id"
                            class="text-xs underline disabled:opacity-50"
                            @click="reveal(a.id)"
                        >
                            {{ revealing === a.id ? 'Loading…' : 'Reveal amounts' }}
                        </button>
                        <ul v-else class="space-y-0.5 font-mono text-xs">
                            <li v-for="v in revealed[a.id]" :key="v.salaryStructureComponentId">
                                {{ formatMoney(v.amount, '') }}
                            </li>
                        </ul>
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-if="assignments.length === 0" class="mt-4 text-sm text-slate-500">
            No compensation assigned yet.
        </p>
    </main>
</template>
