<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { formatMoney } from '../../../../../money';

interface JournalLine {
    id: string;
    ledgerAccountId: string;
    accountCode: string;
    accountName: string;
    side: 'debit' | 'credit';
    amount: string;
    currency: string;
}

interface JournalEntryDetail {
    id: string;
    currency: string;
    description: string;
    postedAt: string;
    reversalOfJournalEntryId: string | null;
    reversedByJournalEntryId: string | null;
    lines: JournalLine[];
}

interface Props {
    journalEntry: JournalEntryDetail;
    canReverse: boolean;
}

const props = defineProps<Props>();

const reversing = ref(false);
const reason = ref('');
const reversalError = ref('');

function reverse(): void {
    const confirmed = window.confirm(
        'Reverse this journal entry? This creates a NEW journal entry with opposite Debit/Credit sides on each line -- the original entry is preserved unchanged, never deleted or edited.',
    );
    if (!confirmed) return;

    reversing.value = true;
    reversalError.value = '';
    router.post(
        `/app/finance/journal-entries/${props.journalEntry.id}/reverse`,
        { reason: reason.value || undefined },
        {
            onError: (errors) => {
                reversalError.value =
                    errors.reversal ?? 'That journal entry could not be reversed.';
            },
            onFinish: () => (reversing.value = false),
        },
    );
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance/journal-entries">← Journal entries</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ journalEntry.description }}</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Posted {{ journalEntry.postedAt }} · {{ journalEntry.currency }}
                </p>
            </div>
            <span
                v-if="journalEntry.reversedByJournalEntryId"
                class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700"
            >
                Reversed
            </span>
        </div>

        <p
            v-if="reversalError"
            class="mt-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            {{ reversalError }}
        </p>

        <p v-if="journalEntry.reversalOfJournalEntryId" class="mt-4 text-sm text-slate-600">
            This is a reversal of
            <a
                class="underline"
                :href="`/app/finance/journal-entries/${journalEntry.reversalOfJournalEntryId}`"
                >another journal entry</a
            >.
        </p>
        <p v-if="journalEntry.reversedByJournalEntryId" class="mt-2 text-sm text-slate-600">
            Reversed by
            <a
                class="underline"
                :href="`/app/finance/journal-entries/${journalEntry.reversedByJournalEntryId}`"
                >this reversing entry</a
            >.
        </p>

        <table class="mt-6 w-full text-left text-sm">
            <caption class="sr-only">
                Journal lines for
                {{
                    journalEntry.description
                }}
            </caption>
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">Account</th>
                    <th scope="col" class="py-2 text-right font-medium">Debit</th>
                    <th scope="col" class="py-2 text-right font-medium">Credit</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="line in journalEntry.lines" :key="line.id">
                    <td class="py-3">
                        <span class="font-mono text-xs text-slate-500">{{ line.accountCode }}</span>
                        {{ line.accountName }}
                    </td>
                    <td class="py-3 text-right font-mono">
                        {{ line.side === 'debit' ? formatMoney(line.amount, line.currency) : '' }}
                    </td>
                    <td class="py-3 text-right font-mono">
                        {{ line.side === 'credit' ? formatMoney(line.amount, line.currency) : '' }}
                    </td>
                </tr>
            </tbody>
        </table>

        <section
            v-if="canReverse && !journalEntry.reversedByJournalEntryId"
            class="mt-8 border-t border-slate-200 pt-6"
        >
            <h2 class="text-sm font-medium text-slate-900">Reverse this journal entry</h2>
            <p class="mt-1 text-sm text-slate-500">
                Reversal creates a new journal entry with the opposite Debit/Credit sides. The
                original entry is preserved -- this is not an edit or a delete.
            </p>
            <div class="mt-3">
                <label class="block text-sm text-slate-600" for="reason">Reason (optional)</label>
                <input
                    id="reason"
                    v-model="reason"
                    type="text"
                    maxlength="255"
                    class="mt-1 w-full max-w-md rounded border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <button
                type="button"
                :disabled="reversing"
                class="mt-3 rounded border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
                @click="reverse"
            >
                Reverse journal entry
            </button>
        </section>
    </main>
</template>
