<script setup lang="ts">
import EmptyState from '../../../../Components/EmptyState.vue';

interface LedgerAccountRow {
    id: string;
    code: string;
    name: string;
    type: string;
    currency: string;
    isSystem: boolean;
    status: 'active' | 'inactive';
}

interface Props {
    accounts: LedgerAccountRow[];
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance">← Finance</a>
        <h1 class="mt-2 text-xl font-semibold">Ledger accounts</h1>
        <p class="mt-1 text-sm text-slate-500">
            The Chart of Accounts directory. Read-only in this checkpoint -- no account
            create/edit/deactivate.
        </p>

        <EmptyState
            v-if="accounts.length === 0"
            class="mt-6"
            title="No ledger accounts yet"
            description="Ledger accounts are provisioned by School setup, not created here."
        />

        <template v-else>
            <table class="mt-6 hidden w-full text-left text-sm md:table">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Code</th>
                        <th scope="col" class="py-2 font-medium">Name</th>
                        <th scope="col" class="py-2 font-medium">Type</th>
                        <th scope="col" class="py-2 font-medium">Currency</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="account in accounts" :key="account.id">
                        <td class="py-3 font-mono text-xs">{{ account.code }}</td>
                        <td class="py-3">
                            {{ account.name }}
                            <span
                                v-if="account.isSystem"
                                class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500"
                                >system</span
                            >
                        </td>
                        <td class="py-3 text-slate-600">{{ account.type }}</td>
                        <td class="py-3 text-slate-600">{{ account.currency }}</td>
                        <td class="py-3">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="
                                    account.status === 'active'
                                        ? 'bg-emerald-50 text-emerald-700'
                                        : 'bg-slate-100 text-slate-600'
                                "
                            >
                                {{ account.status === 'active' ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul class="mt-6 space-y-3 md:hidden">
                <li
                    v-for="account in accounts"
                    :key="account.id"
                    class="rounded border border-slate-200 p-4"
                >
                    <p class="font-mono text-xs text-slate-500">{{ account.code }}</p>
                    <p class="font-medium">{{ account.name }}</p>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ account.type }} · {{ account.currency }}
                    </p>
                </li>
            </ul>
        </template>
    </main>
</template>
