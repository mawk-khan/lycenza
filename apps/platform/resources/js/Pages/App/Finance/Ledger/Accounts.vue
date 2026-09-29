<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
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
    types: string[];
    canManage: boolean;
}

defineProps<Props>();

const form = useForm({ code: '', name: '', type: 'asset' });

function submit(): void {
    form.post('/app/finance/ledger-accounts', {
        preserveScroll: true,
        onSuccess: () => form.reset('code', 'name'),
    });
}

function setStatus(account: LedgerAccountRow, status: 'active' | 'inactive'): void {
    if (
        status === 'inactive' &&
        !window.confirm(
            `Deactivate ${account.code}? Existing postings stay; fee heads mapped to it will need an active account before new charges.`,
        )
    ) {
        return;
    }

    router.post(
        `/app/finance/ledger-accounts/${account.id}/status`,
        { status },
        { preserveScroll: true },
    );
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance">← Finance</a>
        <h1 class="mt-2 text-xl font-semibold">Ledger accounts</h1>
        <p class="mt-1 text-sm text-slate-500">
            The Chart of Accounts. Accounts are deactivated, never deleted, and an account's type
            cannot change once anything has been posted to it.
        </p>

        <form
            v-if="canManage"
            class="mt-6 grid gap-3 rounded border border-slate-200 p-4 md:grid-cols-4"
            @submit.prevent="submit"
        >
            <label class="text-sm">
                <span class="block text-xs text-slate-500">Code</span>
                <input
                    v-model="form.code"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1 font-mono"
                    maxlength="32"
                    required
                />
                <span v-if="form.errors.code" class="text-xs text-red-600">{{
                    form.errors.code
                }}</span>
            </label>
            <label class="text-sm md:col-span-2">
                <span class="block text-xs text-slate-500">Name</span>
                <input
                    v-model="form.name"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                    maxlength="120"
                    required
                />
                <span v-if="form.errors.name" class="text-xs text-red-600">{{
                    form.errors.name
                }}</span>
            </label>
            <label class="text-sm">
                <span class="block text-xs text-slate-500">Type</span>
                <select
                    v-model="form.type"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                >
                    <option v-for="type in types" :key="type" :value="type">{{ type }}</option>
                </select>
                <span v-if="form.errors.type" class="text-xs text-red-600">{{
                    form.errors.type
                }}</span>
            </label>
            <div class="md:col-span-4">
                <button
                    type="submit"
                    class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Create account
                </button>
                <span class="ml-2 text-xs text-slate-500">INR only.</span>
            </div>
        </form>

        <EmptyState
            v-if="accounts.length === 0"
            class="mt-6"
            title="No ledger accounts yet"
            :description="
                canManage
                    ? 'Create the accounts your School posts to, for example a Fees Receivable asset account and a Tuition Fee Income account.'
                    : 'Someone with ledger account administration can create them.'
            "
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
                        <th v-if="canManage" scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
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
                        <td v-if="canManage" class="py-3 text-right">
                            <button
                                v-if="account.status === 'active'"
                                type="button"
                                class="text-xs underline"
                                @click="setStatus(account, 'inactive')"
                            >
                                Deactivate
                            </button>
                            <button
                                v-else
                                type="button"
                                class="text-xs underline"
                                @click="setStatus(account, 'active')"
                            >
                                Activate
                            </button>
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
                        {{ account.type }} · {{ account.currency }} ·
                        {{ account.status === 'active' ? 'Active' : 'Inactive' }}
                    </p>
                    <button
                        v-if="canManage"
                        type="button"
                        class="mt-2 text-xs underline"
                        @click="
                            setStatus(account, account.status === 'active' ? 'inactive' : 'active')
                        "
                    >
                        {{ account.status === 'active' ? 'Deactivate' : 'Activate' }}
                    </button>
                </li>
            </ul>
        </template>
    </main>
</template>
