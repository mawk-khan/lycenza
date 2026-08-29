<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface SalaryComponent {
    id: string;
    code: string;
    name: string;
    type: 'earning' | 'deduction';
    liabilityLedgerAccountId: string | null;
    status: 'active' | 'inactive';
}

interface Props {
    components: SalaryComponent[];
    canManage: boolean;
}

defineProps<Props>();

const showForm = ref(false);
const form = useForm({
    code: '',
    name: '',
    type: 'earning' as 'earning' | 'deduction',
    liability_ledger_account_id: '',
});

function submit(): void {
    form.post('/app/payroll/components', {
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    });
}

const deactivating = ref<string | null>(null);

function deactivate(id: string): void {
    if (
        !window.confirm(
            'Deactivate this Salary Component? It stays visible on any existing structure/run history.',
        )
    )
        return;

    deactivating.value = id;
    router.post(
        `/app/payroll/components/${id}/deactivate`,
        {},
        { onFinish: () => (deactivating.value = null) },
    );
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/payroll">← Payroll</a>

        <div class="mt-2 flex items-center justify-between">
            <h1 class="text-xl font-semibold">Salary Components</h1>
            <button
                v-if="canManage"
                type="button"
                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white"
                @click="showForm = !showForm"
            >
                {{ showForm ? 'Cancel' : 'Add component' }}
            </button>
        </div>

        <form
            v-if="showForm"
            class="mt-4 space-y-3 rounded border border-slate-200 p-4"
            @submit.prevent="submit"
        >
            <div>
                <label class="block text-sm text-slate-600" for="code">Code</label>
                <input
                    id="code"
                    v-model="form.code"
                    type="text"
                    maxlength="64"
                    class="mt-1 w-full max-w-xs rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">
                    {{ form.errors.code }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="name">Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    maxlength="255"
                    class="mt-1 w-full max-w-md rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                    {{ form.errors.name }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="type">Type</label>
                <select
                    id="type"
                    v-model="form.type"
                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="earning">Earning</option>
                    <option value="deduction">Deduction</option>
                </select>
                <p v-if="form.errors.type" class="mt-1 text-sm text-red-600">
                    {{ form.errors.type }}
                </p>
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? 'Creating…' : 'Create component' }}
            </button>
        </form>

        <table class="mt-6 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">Code</th>
                    <th scope="col" class="py-2 font-medium">Name</th>
                    <th scope="col" class="py-2 font-medium">Type</th>
                    <th scope="col" class="py-2 font-medium">Status</th>
                    <th v-if="canManage" scope="col" class="py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="c in components" :key="c.id">
                    <td class="py-3 font-mono text-xs">{{ c.code }}</td>
                    <td class="py-3">{{ c.name }}</td>
                    <td class="py-3 capitalize">{{ c.type }}</td>
                    <td class="py-3"><StatusBadge :status="c.status" /></td>
                    <td v-if="canManage" class="py-3 text-right">
                        <button
                            v-if="c.status === 'active'"
                            type="button"
                            :disabled="deactivating === c.id"
                            class="text-xs text-red-600 underline disabled:opacity-50"
                            @click="deactivate(c.id)"
                        >
                            Deactivate
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <p v-if="components.length === 0" class="mt-4 text-sm text-slate-500">
            No Salary Components yet.
        </p>
    </main>
</template>
