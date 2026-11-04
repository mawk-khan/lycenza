<script setup lang="ts">
import { formatDateTime } from '../../../../../datetime';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import MfaCodeField from '../../../../../Components/MfaCodeField.vue';
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
    hasMfaFactor: boolean;
}

const props = defineProps<Props>();

const reversing = ref(false);
const reason = ref('');
const reversalError = ref('');
// SR.4 (ADR 0071 §26.7): reversal needs a fresh code.
const mfaCode = ref('');
const mfaError = ref<string | null>(null);

function reverse(): void {
    const confirmed = window.confirm(
        'Reverse this journal entry? This creates a NEW journal entry with opposite Debit/Credit sides on each line -- the original entry is preserved unchanged, never deleted or edited.',
    );
    if (!confirmed) return;

    reversing.value = true;
    reversalError.value = '';
    router.post(
        `/app/finance/journal-entries/${props.journalEntry.id}/reverse`,
        { reason: reason.value || undefined, mfa_code: mfaCode.value },
        {
            onError: (errors) => {
                mfaError.value = errors.mfa_code ?? null;
                mfaCode.value = '';
                reversalError.value = errors.mfa_code
                    ? ''
                    : (errors.reversal ?? 'That journal entry could not be reversed.');
            },
            onFinish: () => (reversing.value = false),
        },
    );
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/finance/journal-entries">← Journal entries</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ journalEntry.description }}</h1>
                <p class="mt-1 text-sm text-ink-muted">
                    Posted {{ formatDateTime(journalEntry.postedAt) }} · {{ journalEntry.currency }}
                </p>
            </div>
            <span
                v-if="journalEntry.reversedByJournalEntryId"
                class="shrink-0 rounded-xs bg-accent-100 px-2 py-0.5 text-xs font-medium text-ink"
            >
                Reversed
            </span>
        </div>

        <p
            v-if="reversalError"
            class="mt-4 rounded-xs border border-danger/40 bg-danger-tint p-3 text-sm text-danger"
        >
            {{ reversalError }}
        </p>

        <p v-if="journalEntry.reversalOfJournalEntryId" class="mt-4 text-sm text-ink-muted">
            This is a reversal of
            <a
                class="underline"
                :href="`/app/finance/journal-entries/${journalEntry.reversalOfJournalEntryId}`"
                >another journal entry</a
            >.
        </p>
        <p v-if="journalEntry.reversedByJournalEntryId" class="mt-2 text-sm text-ink-muted">
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
                <tr class="border-b border-line text-xs text-ink-muted">
                    <th scope="col" class="py-2 font-medium">Account</th>
                    <th scope="col" class="py-2 text-right font-medium">Debit</th>
                    <th scope="col" class="py-2 text-right font-medium">Credit</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="line in journalEntry.lines" :key="line.id">
                    <td class="py-3">
                        <span class="font-mono text-xs text-ink-muted">{{ line.accountCode }}</span>
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
            class="mt-8 border-t border-line pt-6"
        >
            <h2 class="text-sm font-medium text-ink">Reverse this journal entry</h2>
            <p class="mt-1 text-sm text-ink-muted">
                Reversal creates a new journal entry with the opposite Debit/Credit sides. The
                original entry is preserved -- this is not an edit or a delete.
            </p>
            <div class="mt-3">
                <label class="block text-sm text-ink-muted" for="reason">Reason (optional)</label>
                <input
                    id="reason"
                    v-model="reason"
                    type="text"
                    maxlength="255"
                    class="input mt-1 max-w-md"
                />
            </div>
            <div class="mt-3">
                <MfaCodeField
                    id="journal-reverse-mfa"
                    v-model="mfaCode"
                    :has-mfa-factor="hasMfaFactor"
                    action="Reversing a journal entry"
                    :error="mfaError"
                />
            </div>
            <button
                type="button"
                :disabled="reversing || !hasMfaFactor"
                class="mt-3 rounded-xs border border-danger/40 px-3 py-2 text-sm font-medium text-danger hover:bg-danger-tint disabled:opacity-50"
                @click="reverse"
            >
                Reverse journal entry
            </button>
        </section>
    </main>
</template>
