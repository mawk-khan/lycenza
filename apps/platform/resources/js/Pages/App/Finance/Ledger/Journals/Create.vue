<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatMoney, sumAmounts } from '../../../../../money';

interface Props {
    accounts: Array<{ id: string; code: string; name: string; currency: string }>;
}

defineProps<Props>();

interface LineInput {
    ledger_account_id: string;
    side: 'debit' | 'credit';
    amount: string;
}

function emptyLine(): LineInput {
    return { ledger_account_id: '', side: 'debit', amount: '' };
}

const form = useForm<{
    currency: string;
    description: string;
    lines: LineInput[];
}>({
    currency: 'INR',
    description: '',
    lines: [emptyLine(), emptyLine()],
});

function addLine(): void {
    form.lines.push(emptyLine());
}

function removeLine(index: number): void {
    form.lines.splice(index, 1);
}

// Preview only -- exact BigInt-cents arithmetic (money.ts), never a
// float. The server remains the sole authority on whether the entry
// actually balances (FINANCE.md 0G.7 rule 17).
const totalDebits = computed(() =>
    sumAmounts(form.lines.filter((l) => l.side === 'debit').map((l) => l.amount)),
);
const totalCredits = computed(() =>
    sumAmounts(form.lines.filter((l) => l.side === 'credit').map((l) => l.amount)),
);
const balancePreview = computed(() => totalDebits.value === totalCredits.value);

function submit(): void {
    form.post('/app/finance/journal-entries');
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance/journal-entries">← Journal entries</a>
        <h1 class="mt-2 text-xl font-semibold">Post journal entry</h1>
        <p class="mt-1 text-sm text-slate-500">
            Every entry needs at least 2 lines whose Debits equal its Credits. The server is the
            final authority on whether the entry balances.
        </p>

        <form class="mt-6 space-y-6" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="description">Description</label>
                <input
                    id="description"
                    v-model="form.description"
                    required
                    maxlength="255"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    :aria-invalid="!!form.errors.description"
                />
                <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">
                    {{ form.errors.description }}
                </p>
            </div>

            <div>
                <h2 class="text-sm font-medium text-slate-900">Lines</h2>
                <p v-if="form.errors.lines" class="mt-1 text-sm text-red-600">
                    {{ form.errors.lines }}
                </p>

                <div
                    v-for="(line, index) in form.lines"
                    :key="index"
                    class="mt-3 grid grid-cols-12 items-end gap-2 rounded border border-slate-200 p-3"
                >
                    <div class="col-span-5">
                        <label class="block text-xs text-slate-600" :for="`account-${index}`"
                            >Account</label
                        >
                        <select
                            :id="`account-${index}`"
                            v-model="line.ledger_account_id"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-2 text-sm"
                        >
                            <option value="" disabled>Select an account</option>
                            <option
                                v-for="account in accounts"
                                :key="account.id"
                                :value="account.id"
                            >
                                {{ account.code }} — {{ account.name }}
                            </option>
                        </select>
                    </div>
                    <div class="col-span-3">
                        <label class="block text-xs text-slate-600" :for="`side-${index}`"
                            >Side</label
                        >
                        <select
                            :id="`side-${index}`"
                            v-model="line.side"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-2 text-sm"
                        >
                            <option value="debit">Debit</option>
                            <option value="credit">Credit</option>
                        </select>
                    </div>
                    <div class="col-span-3">
                        <label class="block text-xs text-slate-600" :for="`amount-${index}`"
                            >Amount</label
                        >
                        <input
                            :id="`amount-${index}`"
                            v-model="line.amount"
                            required
                            inputmode="decimal"
                            placeholder="0.00"
                            pattern="\d{1,12}(\.\d{1,2})?"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-2 text-sm"
                        />
                    </div>
                    <div class="col-span-1 text-right">
                        <button
                            type="button"
                            :disabled="form.lines.length <= 2"
                            class="text-xs text-red-600 underline disabled:cursor-not-allowed disabled:text-slate-300 disabled:no-underline"
                            @click="removeLine(index)"
                        >
                            Remove
                        </button>
                    </div>
                </div>

                <button type="button" class="mt-3 text-sm underline" @click="addLine">
                    + Add line
                </button>
            </div>

            <div class="rounded border border-slate-200 bg-slate-50 p-3 text-sm">
                <p>
                    Total debits:
                    <span class="font-mono">{{ formatMoney(totalDebits, form.currency) }}</span>
                </p>
                <p>
                    Total credits:
                    <span class="font-mono">{{ formatMoney(totalCredits, form.currency) }}</span>
                </p>
                <p class="mt-1" :class="balancePreview ? 'text-emerald-700' : 'text-amber-700'">
                    {{
                        balancePreview
                            ? 'Debits and credits balance (preview only -- the server confirms this).'
                            : 'Debits and credits do not balance yet.'
                    }}
                </p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
                Post journal entry
            </button>
        </form>
    </main>
</template>
