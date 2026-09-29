<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';

interface AccountOption {
    id: string;
    code: string;
    name: string;
}

interface FeeHeadRow {
    id: string;
    code: string;
    name: string;
    description: string | null;
    status: 'active' | 'inactive';
    receivableLedgerAccountId: string;
    revenueLedgerAccountId: string;
    currency: string;
}

interface StructureRow {
    id: string;
    code: string;
    name: string;
    status: 'draft' | 'active' | 'retired';
    academicYearName: string | null;
    gradeLevelName: string | null;
    campusName: string | null;
    supersedesFeeStructureId: string | null;
}

interface Props {
    feeHeads: FeeHeadRow[];
    structures: StructureRow[];
    receivableAccounts: AccountOption[];
    revenueAccounts: AccountOption[];
    academicYears: Array<{ id: string; name: string; status: string }>;
    gradeLevels: Array<{ id: string; name: string }>;
    campuses: Array<{ id: string; name: string }>;
    canManage: boolean;
}

const props = defineProps<Props>();
const page = usePage();
const actionError = computed(() => (page.props.errors as Record<string, string>).action);

const headForm = useForm({
    code: '',
    name: '',
    description: '',
    receivable_ledger_account_id: '',
    revenue_ledger_account_id: '',
});

function createHead(): void {
    headForm.post('/app/finance/fee-setup/fee-heads', {
        preserveScroll: true,
        onSuccess: () => headForm.reset(),
    });
}

const editingHeadId = ref<string | null>(null);
const editForm = useForm({
    name: '',
    receivable_ledger_account_id: '',
    revenue_ledger_account_id: '',
});

function startEdit(head: FeeHeadRow): void {
    editingHeadId.value = head.id;
    editForm.name = head.name;
    editForm.receivable_ledger_account_id = head.receivableLedgerAccountId;
    editForm.revenue_ledger_account_id = head.revenueLedgerAccountId;
    editForm.clearErrors();
}

function saveEdit(head: FeeHeadRow): void {
    editForm.post(`/app/finance/fee-setup/fee-heads/${head.id}`, {
        preserveScroll: true,
        onSuccess: () => (editingHeadId.value = null),
    });
}

function setHeadStatus(head: FeeHeadRow, status: 'active' | 'inactive'): void {
    if (
        status === 'inactive' &&
        !window.confirm(`Deactivate ${head.code}? It stays on existing structures and charges.`)
    ) {
        return;
    }
    router.post(
        `/app/finance/fee-setup/fee-heads/${head.id}`,
        { status },
        { preserveScroll: true },
    );
}

const structureForm = useForm({
    academic_year_id: '',
    grade_level_id: '',
    campus_id: '',
    code: '',
    name: '',
});

function createStructure(): void {
    structureForm
        .transform((data) => ({ ...data, campus_id: data.campus_id || null }))
        .post('/app/finance/fee-setup/structures');
}

function accountLabel(options: AccountOption[], id: string): string {
    const account = options.find((a) => a.id === id);
    return account ? `${account.code} · ${account.name}` : 'inactive or other account';
}

const statusClass: Record<StructureRow['status'], string> = {
    draft: 'bg-amber-50 text-amber-700',
    active: 'bg-emerald-50 text-emerald-700',
    retired: 'bg-slate-100 text-slate-600',
};

const noAccounts = computed(
    () => props.receivableAccounts.length === 0 || props.revenueAccounts.length === 0,
);
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance">← Finance</a>
        <h1 class="mt-2 text-xl font-semibold">Fee setup</h1>
        <p class="mt-1 text-sm text-slate-500">
            Fee heads map fees to ledger accounts. A fee structure sets each fee head's yearly
            amount and instalment schedule for one academic year and grade (optionally one campus).
            Nothing here bills a Student yet.
        </p>

        <p v-if="actionError" role="alert" class="mt-4 rounded bg-red-50 p-3 text-sm text-red-700">
            {{ actionError }}
        </p>

        <!-- Fee heads -->
        <section class="mt-8">
            <h2 class="text-lg font-semibold">Fee heads</h2>

            <p v-if="canManage && noAccounts" class="mt-2 text-sm text-amber-700">
                You need an active asset account and an active income account first --
                <a class="underline" href="/app/finance/ledger-accounts">create ledger accounts</a>.
            </p>

            <form
                v-if="canManage"
                class="mt-3 grid gap-3 rounded border border-slate-200 p-4 md:grid-cols-2"
                @submit.prevent="createHead"
            >
                <label class="text-sm">
                    <span class="block text-xs text-slate-500">Code</span>
                    <input
                        v-model="headForm.code"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1 font-mono"
                        maxlength="32"
                        required
                    />
                    <span v-if="headForm.errors.code" class="text-xs text-red-600">{{
                        headForm.errors.code
                    }}</span>
                </label>
                <label class="text-sm">
                    <span class="block text-xs text-slate-500">Name</span>
                    <input
                        v-model="headForm.name"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        maxlength="120"
                        required
                    />
                    <span v-if="headForm.errors.name" class="text-xs text-red-600">{{
                        headForm.errors.name
                    }}</span>
                </label>
                <label class="text-sm">
                    <span class="block text-xs text-slate-500">Receivable account (asset)</span>
                    <select
                        v-model="headForm.receivable_ledger_account_id"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        required
                    >
                        <option value="" disabled>Choose…</option>
                        <option v-for="a in receivableAccounts" :key="a.id" :value="a.id">
                            {{ a.code }} · {{ a.name }}
                        </option>
                    </select>
                    <span
                        v-if="headForm.errors.receivable_ledger_account_id"
                        class="text-xs text-red-600"
                        >{{ headForm.errors.receivable_ledger_account_id }}</span
                    >
                </label>
                <label class="text-sm">
                    <span class="block text-xs text-slate-500">Revenue account (income)</span>
                    <select
                        v-model="headForm.revenue_ledger_account_id"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        required
                    >
                        <option value="" disabled>Choose…</option>
                        <option v-for="a in revenueAccounts" :key="a.id" :value="a.id">
                            {{ a.code }} · {{ a.name }}
                        </option>
                    </select>
                    <span
                        v-if="headForm.errors.revenue_ledger_account_id"
                        class="text-xs text-red-600"
                        >{{ headForm.errors.revenue_ledger_account_id }}</span
                    >
                </label>
                <label class="text-sm md:col-span-2">
                    <span class="block text-xs text-slate-500">Description (optional)</span>
                    <input
                        v-model="headForm.description"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        maxlength="500"
                    />
                </label>
                <div class="md:col-span-2">
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                        :disabled="headForm.processing || noAccounts"
                    >
                        Create fee head
                    </button>
                </div>
            </form>

            <EmptyState
                v-if="feeHeads.length === 0"
                class="mt-4"
                title="No fee heads yet"
                description="A fee head is one kind of fee, such as Tuition or Transport."
            />

            <table v-else class="mt-4 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Code</th>
                        <th scope="col" class="py-2 font-medium">Name</th>
                        <th scope="col" class="hidden py-2 font-medium md:table-cell">Accounts</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                        <th v-if="canManage" scope="col" class="py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="head in feeHeads" :key="head.id" class="align-top">
                        <td class="py-3 font-mono text-xs">{{ head.code }}</td>
                        <td class="py-3">
                            <template v-if="editingHeadId === head.id">
                                <input
                                    v-model="editForm.name"
                                    class="w-full rounded border border-slate-300 px-2 py-1"
                                    maxlength="120"
                                />
                                <select
                                    v-model="editForm.receivable_ledger_account_id"
                                    class="mt-2 w-full rounded border border-slate-300 px-2 py-1"
                                >
                                    <option
                                        v-for="a in receivableAccounts"
                                        :key="a.id"
                                        :value="a.id"
                                    >
                                        {{ a.code }} · {{ a.name }}
                                    </option>
                                </select>
                                <select
                                    v-model="editForm.revenue_ledger_account_id"
                                    class="mt-2 w-full rounded border border-slate-300 px-2 py-1"
                                >
                                    <option v-for="a in revenueAccounts" :key="a.id" :value="a.id">
                                        {{ a.code }} · {{ a.name }}
                                    </option>
                                </select>
                                <p
                                    v-for="(message, field) in editForm.errors"
                                    :key="field"
                                    class="text-xs text-red-600"
                                >
                                    {{ message }}
                                </p>
                            </template>
                            <template v-else>{{ head.name }}</template>
                        </td>
                        <td class="hidden py-3 text-xs text-slate-600 md:table-cell">
                            <template v-if="canManage">
                                {{
                                    accountLabel(receivableAccounts, head.receivableLedgerAccountId)
                                }}
                                <br />
                                {{ accountLabel(revenueAccounts, head.revenueLedgerAccountId) }}
                            </template>
                            <template v-else>Mapped</template>
                        </td>
                        <td class="py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="
                                    head.status === 'active'
                                        ? 'bg-emerald-50 text-emerald-700'
                                        : 'bg-slate-100 text-slate-600'
                                "
                                >{{ head.status === 'active' ? 'Active' : 'Inactive' }}</span
                            >
                        </td>
                        <td v-if="canManage" class="space-x-2 py-3 text-right text-xs">
                            <template v-if="editingHeadId === head.id">
                                <button type="button" class="underline" @click="saveEdit(head)">
                                    Save
                                </button>
                                <button
                                    type="button"
                                    class="underline"
                                    @click="editingHeadId = null"
                                >
                                    Cancel
                                </button>
                            </template>
                            <template v-else>
                                <button type="button" class="underline" @click="startEdit(head)">
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    class="underline"
                                    @click="
                                        setHeadStatus(
                                            head,
                                            head.status === 'active' ? 'inactive' : 'active',
                                        )
                                    "
                                >
                                    {{ head.status === 'active' ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Fee structures -->
        <section class="mt-10">
            <h2 class="text-lg font-semibold">Fee structures</h2>

            <form
                v-if="canManage"
                class="mt-3 grid gap-3 rounded border border-slate-200 p-4 md:grid-cols-3"
                @submit.prevent="createStructure"
            >
                <label class="text-sm">
                    <span class="block text-xs text-slate-500">Academic year</span>
                    <select
                        v-model="structureForm.academic_year_id"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        required
                    >
                        <option value="" disabled>Choose…</option>
                        <option v-for="y in academicYears" :key="y.id" :value="y.id">
                            {{ y.name }} ({{ y.status }})
                        </option>
                    </select>
                    <span
                        v-if="structureForm.errors.academic_year_id"
                        class="text-xs text-red-600"
                        >{{ structureForm.errors.academic_year_id }}</span
                    >
                </label>
                <label class="text-sm">
                    <span class="block text-xs text-slate-500">Grade</span>
                    <select
                        v-model="structureForm.grade_level_id"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        required
                    >
                        <option value="" disabled>Choose…</option>
                        <option v-for="g in gradeLevels" :key="g.id" :value="g.id">
                            {{ g.name }}
                        </option>
                    </select>
                    <span v-if="structureForm.errors.grade_level_id" class="text-xs text-red-600">{{
                        structureForm.errors.grade_level_id
                    }}</span>
                </label>
                <label class="text-sm">
                    <span class="block text-xs text-slate-500">Campus</span>
                    <select
                        v-model="structureForm.campus_id"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                    >
                        <option value="">All campuses (School default)</option>
                        <option v-for="c in campuses" :key="c.id" :value="c.id">
                            {{ c.name }}
                        </option>
                    </select>
                    <span v-if="structureForm.errors.campus_id" class="text-xs text-red-600">{{
                        structureForm.errors.campus_id
                    }}</span>
                </label>
                <label class="text-sm">
                    <span class="block text-xs text-slate-500">Code</span>
                    <input
                        v-model="structureForm.code"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1 font-mono"
                        maxlength="32"
                        required
                    />
                    <span v-if="structureForm.errors.code" class="text-xs text-red-600">{{
                        structureForm.errors.code
                    }}</span>
                </label>
                <label class="text-sm md:col-span-2">
                    <span class="block text-xs text-slate-500">Name</span>
                    <input
                        v-model="structureForm.name"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        maxlength="120"
                        required
                    />
                    <span v-if="structureForm.errors.name" class="text-xs text-red-600">{{
                        structureForm.errors.name
                    }}</span>
                </label>
                <div class="md:col-span-3">
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                        :disabled="structureForm.processing"
                    >
                        Create draft structure
                    </button>
                </div>
            </form>

            <EmptyState
                v-if="structures.length === 0"
                class="mt-4"
                title="No fee structures yet"
                description="Create a draft for an academic year and grade, add fee lines and schedules, then activate it."
            />

            <table v-else class="mt-4 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Structure</th>
                        <th scope="col" class="py-2 font-medium">Year</th>
                        <th scope="col" class="py-2 font-medium">Grade</th>
                        <th scope="col" class="py-2 font-medium">Campus</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="s in structures" :key="s.id">
                        <td class="py-3">
                            <a
                                class="underline"
                                :href="`/app/finance/fee-setup/structures/${s.id}`"
                                >{{ s.name }}</a
                            >
                            <span class="ml-1 font-mono text-xs text-slate-500">{{ s.code }}</span>
                            <span
                                v-if="s.supersedesFeeStructureId"
                                class="ml-1 text-xs text-slate-500"
                                >(amendment)</span
                            >
                        </td>
                        <td class="py-3 text-slate-600">{{ s.academicYearName }}</td>
                        <td class="py-3 text-slate-600">{{ s.gradeLevelName }}</td>
                        <td class="py-3 text-slate-600">{{ s.campusName ?? 'All campuses' }}</td>
                        <td class="py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                :class="statusClass[s.status]"
                                >{{ s.status }}</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </main>
</template>
