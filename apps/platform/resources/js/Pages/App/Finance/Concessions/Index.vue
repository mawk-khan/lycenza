<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import { formatMoney } from '../../../../money';

interface ConcessionRow {
    id: string;
    studentName: string | null;
    category: string;
    scope: string;
    kind: string;
    fixedAmount: string | null;
    percentage: string | null;
    currency: string;
    status: string;
    createdAt: string;
}

interface Props {
    concessions: {
        data: ConcessionRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: { status: string; scope: string };
    canRequest: boolean;
    settings: {
        concessionLedgerAccountId: string | null;
        options: Array<{ id: string; label: string }>;
        canManage: boolean;
    } | null;
}

const props = defineProps<Props>();
const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);

const statuses = ['pending', 'approved', 'rejected', 'withdrawn', 'revoked', 'all'];
const settingsForm = useForm({
    concession_ledger_account_id: props.settings?.concessionLedgerAccountId ?? '',
});
const currentAccount = computed(
    () =>
        props.settings?.options.find((o) => o.id === props.settings?.concessionLedgerAccountId)
            ?.label ?? null,
);

function filter(status: string, scope: string): void {
    router.get(
        '/app/finance/concessions',
        { status, scope: scope || undefined },
        { preserveState: true },
    );
}

function saveAccount(): void {
    settingsForm.post('/app/finance/concessions/settings', { preserveScroll: true });
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance">← Finance</a>
        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Concessions</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Concessions, scholarships and waivers. Every request needs a second person's
                    approval before it reduces what a Student owes; the charge itself never changes.
                </p>
            </div>
            <a
                v-if="canRequest"
                class="shrink-0 rounded bg-slate-900 px-3 py-1.5 text-sm text-white"
                href="/app/finance/concessions/create"
                >Request concession</a
            >
        </div>

        <section v-if="settings" class="mt-6 rounded border border-slate-200 p-4">
            <h2 class="text-sm font-medium">Concession account</h2>
            <p class="mt-1 text-sm text-slate-500">
                Approved concessions post to this expense account. Changing it never moves
                concessions already posted.
            </p>
            <p v-if="!settings.canManage" class="mt-2 text-sm">
                {{ currentAccount ?? 'Not configured -- concessions cannot be posted yet.' }}
            </p>
            <form v-else class="mt-2 flex flex-wrap items-end gap-2" @submit.prevent="saveAccount">
                <label class="text-sm">
                    <span class="sr-only">Concession expense account</span>
                    <select
                        v-model="settingsForm.concession_ledger_account_id"
                        class="rounded border border-slate-300 px-2 py-1"
                        required
                    >
                        <option value="" disabled>Choose an active expense account…</option>
                        <option v-for="o in settings.options" :key="o.id" :value="o.id">
                            {{ o.label }}
                        </option>
                    </select>
                </label>
                <button
                    type="submit"
                    class="rounded border border-slate-300 px-3 py-1 text-sm disabled:opacity-50"
                    :disabled="settingsForm.processing"
                >
                    Save
                </button>
                <span
                    v-if="settingsForm.errors.concession_ledger_account_id"
                    class="text-xs text-red-600"
                    >{{ settingsForm.errors.concession_ledger_account_id }}</span
                >
            </form>
        </section>

        <p
            v-if="errors.action"
            role="alert"
            class="mt-4 rounded bg-red-50 p-3 text-sm text-red-700"
        >
            {{ errors.action }}
        </p>

        <div class="mt-6 flex flex-wrap items-center gap-2 text-sm">
            <button
                v-for="s in statuses"
                :key="s"
                type="button"
                class="rounded-full border px-3 py-1 capitalize"
                :class="
                    filters.status === s
                        ? 'border-slate-900 bg-slate-900 text-white'
                        : 'border-slate-300'
                "
                @click="filter(s, filters.scope)"
            >
                {{ s === 'pending' ? 'Awaiting approval' : s }}
            </button>
            <select
                class="ml-auto rounded border border-slate-300 px-2 py-1"
                :value="filters.scope"
                aria-label="Scope"
                @change="filter(filters.status, ($event.target as HTMLSelectElement).value)"
            >
                <option value="">Charge and standing</option>
                <option value="targeted">One charge</option>
                <option value="standing">Standing</option>
            </select>
        </div>

        <EmptyState
            v-if="concessions.data.length === 0"
            class="mt-6"
            title="No concessions here"
            description="Requests appear here; pending ones wait for a second person to approve."
        />

        <table v-else class="mt-4 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">Student</th>
                    <th scope="col" class="py-2 font-medium">Category</th>
                    <th scope="col" class="py-2 font-medium">Applies to</th>
                    <th scope="col" class="py-2 text-right font-medium">Value</th>
                    <th scope="col" class="py-2 pl-4 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="c in concessions.data" :key="c.id">
                    <td class="py-3">
                        <a class="underline" :href="`/app/finance/concessions/${c.id}`">{{
                            c.studentName ?? 'Student'
                        }}</a>
                    </td>
                    <td class="py-3 capitalize">{{ c.category }}</td>
                    <td class="py-3">{{ c.scope === 'targeted' ? 'One charge' : 'Standing' }}</td>
                    <td class="py-3 text-right font-mono">
                        {{
                            c.kind === 'fixed'
                                ? formatMoney(c.fixedAmount ?? '0.00', c.currency)
                                : `${c.percentage}%`
                        }}
                    </td>
                    <td class="py-3 pl-4 capitalize">{{ c.status }}</td>
                </tr>
            </tbody>
        </table>
        <Pagination class="mt-4" :links="concessions.links" />
    </main>
</template>
