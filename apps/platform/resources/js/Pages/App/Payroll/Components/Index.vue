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
    <main class="mx-auto max-w-3xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/payroll">← Payroll</a>

        <div class="mt-2 flex items-center justify-between">
            <h1 class="text-xl font-semibold">Salary Components</h1>
            <button
                v-if="canManage"
                type="button"
                class="btn btn-primary"
                @click="showForm = !showForm"
            >
                {{ showForm ? 'Cancel' : 'Add component' }}
            </button>
        </div>

        <form
            v-if="showForm"
            class="mt-4 space-y-3 rounded-xs border border-line p-4"
            @submit.prevent="submit"
        >
            <div>
                <label class="block text-sm text-ink-muted" for="code">Code</label>
                <input
                    id="code"
                    v-model="form.code"
                    type="text"
                    maxlength="64"
                    class="input mt-1 max-w-xs"
                />
                <p v-if="form.errors.code" class="mt-1 text-sm text-danger">
                    {{ form.errors.code }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="name">Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    maxlength="255"
                    class="input mt-1 max-w-md"
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-danger">
                    {{ form.errors.name }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="type">Type</label>
                <select id="type" v-model="form.type" class="input mt-1 w-auto">
                    <option value="earning">Earning</option>
                    <option value="deduction">Deduction</option>
                </select>
                <p v-if="form.errors.type" class="mt-1 text-sm text-danger">
                    {{ form.errors.type }}
                </p>
            </div>
            <button type="submit" :disabled="form.processing" class="btn btn-primary">
                {{ form.processing ? 'Creating…' : 'Create component' }}
            </button>
        </form>

        <table class="mt-6 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-line text-xs text-ink-muted">
                    <th scope="col" class="py-2 font-medium">Code</th>
                    <th scope="col" class="py-2 font-medium">Name</th>
                    <th scope="col" class="py-2 font-medium">Type</th>
                    <th scope="col" class="py-2 font-medium">Status</th>
                    <th v-if="canManage" scope="col" class="py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
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
                            class="text-xs text-danger underline disabled:opacity-50"
                            @click="deactivate(c.id)"
                        >
                            Deactivate
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <p v-if="components.length === 0" class="mt-4 text-sm text-ink-muted">
            No Salary Components yet.
        </p>
    </main>
</template>
