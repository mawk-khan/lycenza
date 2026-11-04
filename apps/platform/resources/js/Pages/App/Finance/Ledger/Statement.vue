<script setup lang="ts">
// ORG.9B (ADR 0076 §68), 9B-3: one ledger account's statement for a range of
// School-local posting dates. Opening balance, every posted line in posting
// order with the running balance after it, closing balance. Read-only; the
// running balance is computed on the server over the whole range, so every
// page carries the correct balance brought forward.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import { formatMoney } from '../../../../money';

interface Line {
    journalEntryId: string;
    postedOn: string;
    postedAt: string;
    description: string;
    isReversal: boolean;
    debit: string;
    credit: string;
    balance: string;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    account: {
        id: string;
        code: string;
        name: string;
        type: string;
        status: string;
        currency: string;
        normalSide: 'debit' | 'credit';
    };
    filters: { from: string; to: string };
    today: string;
    opening: string;
    totalDebit: string;
    totalCredit: string;
    closing: string;
    lines: {
        data: Line[];
        links: PageLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
}

const props = defineProps<Props>();
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const pageErrors = computed(() => usePage().props.errors as Record<string, string | undefined>);

/** A net-debit amount shown on the side it falls (Dr / Cr), never a bare minus sign. */
function side(netDebit: string): string {
    const negative = netDebit.startsWith('-');
    const amount = negative ? netDebit.slice(1) : netDebit;
    if (amount === '0.00') {
        return formatMoney('0.00', props.account.currency);
    }
    return `${formatMoney(amount, props.account.currency)} ${negative ? 'Cr' : 'Dr'}`;
}

function apply(): void {
    router.get(
        `/app/finance/ledger-accounts/${props.account.id}/ledger`,
        { from: from.value || undefined, to: to.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <Head :title="`Ledger · ${account.code}`" />
    <main class="mx-auto max-w-5xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/finance/ledger-accounts">← Ledger accounts</a>
        <h1 class="mt-2 text-xl font-semibold">
            <span class="font-mono text-base text-ink-muted">{{ account.code }}</span>
            {{ account.name }}
        </h1>
        <p class="mt-1 text-sm text-ink-muted">
            {{ account.type }} account · normally carries a {{ account.normalSide }} balance. Lines
            are in posting order, dated by the school's own calendar. Every entry here is posted;
            corrections appear as their own reversing entries.
        </p>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <div>
                <label class="block text-xs text-ink-muted" for="ledger-from">From</label>
                <input
                    id="ledger-from"
                    v-model="from"
                    type="date"
                    :max="today"
                    class="input mt-1 w-auto"
                />
            </div>
            <div>
                <label class="block text-xs text-ink-muted" for="ledger-to">To</label>
                <input
                    id="ledger-to"
                    v-model="to"
                    type="date"
                    :max="today"
                    class="input mt-1 w-auto"
                />
            </div>
            <button type="submit" class="btn btn-secondary">Show</button>
        </form>
        <p v-if="pageErrors.from || pageErrors.to" class="mt-3 text-sm text-danger" role="alert">
            {{ pageErrors.from ?? pageErrors.to }}
        </p>

        <dl class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="rounded-xs border border-line p-3">
                <dt class="text-xs text-ink-muted">Opening balance ({{ filters.from }})</dt>
                <dd class="text-lg font-semibold">{{ side(opening) }}</dd>
            </div>
            <div class="rounded-xs border border-line p-3">
                <dt class="text-xs text-ink-muted">Debits in range</dt>
                <dd class="text-lg font-semibold">
                    {{ formatMoney(totalDebit, account.currency) }}
                </dd>
            </div>
            <div class="rounded-xs border border-line p-3">
                <dt class="text-xs text-ink-muted">Credits in range</dt>
                <dd class="text-lg font-semibold">
                    {{ formatMoney(totalCredit, account.currency) }}
                </dd>
            </div>
            <div class="rounded-xs border border-line p-3">
                <dt class="text-xs text-ink-muted">Closing balance ({{ filters.to }})</dt>
                <dd class="text-lg font-semibold">{{ side(closing) }}</dd>
            </div>
        </dl>

        <EmptyState
            v-if="lines.data.length === 0"
            class="mt-6"
            title="No postings to this account in this range"
            description="The opening and closing balances above still show where the account stands."
        />

        <template v-else>
            <div class="relative mt-6 overflow-x-auto">
                <table class="w-full min-w-[44rem] text-left text-sm">
                    <caption class="sr-only">
                        Posted lines with the running balance after each
                    </caption>
                    <thead>
                        <tr class="border-b border-line text-xs text-ink-muted">
                            <th scope="col" class="py-2 font-medium">Posted</th>
                            <th scope="col" class="py-2 font-medium">Entry</th>
                            <th scope="col" class="py-2 text-right font-medium">Debit</th>
                            <th scope="col" class="py-2 text-right font-medium">Credit</th>
                            <th scope="col" class="py-2 text-right font-medium">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="(line, index) in lines.data" :key="index">
                            <td class="py-2 text-ink-muted">{{ line.postedAt }}</td>
                            <td class="py-2">
                                <a
                                    class="underline"
                                    :href="`/app/finance/journal-entries/${line.journalEntryId}`"
                                    >{{ line.description }}</a
                                >
                                <span
                                    v-if="line.isReversal"
                                    class="ml-2 rounded-xs bg-graphite-200 px-1.5 py-0.5 text-xs text-ink"
                                    >reversal</span
                                >
                            </td>
                            <td class="py-2 text-right font-mono">
                                {{
                                    line.debit === '0.00'
                                        ? ''
                                        : formatMoney(line.debit, account.currency)
                                }}
                            </td>
                            <td class="py-2 text-right font-mono">
                                {{
                                    line.credit === '0.00'
                                        ? ''
                                        : formatMoney(line.credit, account.currency)
                                }}
                            </td>
                            <td class="py-2 text-right font-mono">{{ side(line.balance) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination
                :links="lines.links"
                :from="lines.from"
                :to="lines.to"
                :total="lines.total"
            />
        </template>
    </main>
</template>
