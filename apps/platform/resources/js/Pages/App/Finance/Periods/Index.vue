<script setup lang="ts">
import { formatDateTime } from '../../../../datetime';
import { router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

interface Period {
    id: string;
    key: string;
    startsOn: string;
    endsOn: string;
    status: 'open' | 'closed';
    closedAt: string | null;
    closedBy: string | null;
    entryCount: number | null;
    blockers: string[];
    notices: string[];
}

interface Props {
    localToday: string;
    periods: Period[];
    unmappedEntries: number;
    canClose: boolean;
    hasMfaFactor: boolean;
}

defineProps<Props>();

const REASONS: Record<string, string> = {
    period_already_closed: 'This period is already closed.',
    period_not_ended: 'This financial year has not ended yet.',
    earlier_period_open: 'An earlier financial year is still open. Close years in order.',
    unmapped_journal_entries:
        'Some older journal entries are not yet assigned to a financial year. Run the period backfill first.',
    'charges.receipt_series_differs_from_period':
        'Some payments posted in this year carry a receipt number from another year’s series (receipts follow the payment date). Receipts are never renumbered.',
    no_postings: 'Nothing was posted in this year.',
};

function describe(code: string): string {
    return REASONS[code] ?? code;
}

const closingId = ref<string | null>(null);
const form = reactive({ confirmation: '', mfa_code: '' });
const errors = ref<Record<string, string>>({});
const submitting = ref(false);

function startClose(period: Period): void {
    closingId.value = period.id;
    form.confirmation = '';
    form.mfa_code = '';
    errors.value = {};
}

function submitClose(period: Period): void {
    submitting.value = true;
    errors.value = {};
    router.post(
        `/app/finance/periods/${period.id}/close`,
        { confirmation: form.confirmation, mfa_code: form.mfa_code },
        {
            preserveScroll: true,
            onSuccess: () => (closingId.value = null),
            onError: (e) => (errors.value = e),
            onFinish: () => (submitting.value = false),
        },
    );
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/finance">← Finance</a>
        <h1 class="mt-2 text-xl font-semibold">Financial periods</h1>
        <p class="mt-1 text-sm text-ink-muted">
            One financial year per row (today: {{ localToday }}). Closing a year is permanent: it
            records carried-forward balances computed from the ledger, and nothing can be posted
            into it afterwards. Corrections are posted in the current year, linked to the original.
        </p>

        <p
            v-if="unmappedEntries > 0"
            class="mt-4 rounded-xs border border-accent-300 bg-accent-100 p-3 text-sm text-ink"
        >
            {{ unmappedEntries }} older journal
            {{ unmappedEntries === 1 ? 'entry is' : 'entries are' }} not yet assigned to a financial
            year.
        </p>

        <p v-if="periods.length === 0" class="mt-6 text-sm text-ink-muted">
            No financial year exists yet. The first posting creates one.
        </p>

        <ul class="mt-6 divide-y divide-line rounded-xs border border-line">
            <li v-for="period in periods" :key="period.id" class="px-4 py-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-medium">{{ period.key }}</h2>
                        <p class="text-sm text-ink-muted">
                            {{ period.startsOn }} to {{ period.endsOn }}
                            <template v-if="period.entryCount !== null">
                                · {{ period.entryCount }}
                                {{ period.entryCount === 1 ? 'journal entry' : 'journal entries' }}
                            </template>
                        </p>
                        <p v-if="period.status === 'closed'" class="text-sm text-ink-muted">
                            Closed {{ formatDateTime(period.closedAt)
                            }}{{ period.closedBy ? ` by ${period.closedBy}` : '' }}
                        </p>
                    </div>
                    <span
                        class="shrink-0 rounded-xs px-2 py-0.5 text-xs font-medium"
                        :class="
                            period.status === 'closed'
                                ? 'bg-graphite-200 text-ink'
                                : 'bg-graphite-100 text-ink'
                        "
                    >
                        {{ period.status === 'closed' ? 'Closed' : 'Open' }}
                    </span>
                </div>

                <template v-if="period.status === 'open'">
                    <ul v-if="period.blockers.length" class="mt-2 space-y-1 text-sm text-ink-muted">
                        <li v-for="code in period.blockers" :key="code">• {{ describe(code) }}</li>
                    </ul>
                    <ul v-if="period.notices.length" class="mt-2 space-y-1 text-sm text-ink">
                        <li v-for="code in period.notices" :key="code">• {{ describe(code) }}</li>
                    </ul>

                    <div v-if="canClose && period.blockers.length === 0" class="mt-3">
                        <button
                            v-if="closingId !== period.id"
                            type="button"
                            class="btn btn-secondary"
                            @click="startClose(period)"
                        >
                            Close {{ period.key }}…
                        </button>
                        <form
                            v-else
                            class="mt-2 space-y-3 rounded-xs border border-danger/40 bg-danger-tint p-4"
                            @submit.prevent="submitClose(period)"
                        >
                            <p class="text-sm text-danger">
                                Closing {{ period.key }} cannot be undone. Type
                                <span class="font-mono font-semibold">{{ period.key }}</span> to
                                confirm.
                            </p>
                            <div>
                                <label class="block text-sm text-ink" :for="`confirm-${period.id}`"
                                    >Period</label
                                >
                                <input
                                    :id="`confirm-${period.id}`"
                                    v-model="form.confirmation"
                                    type="text"
                                    maxlength="16"
                                    autocomplete="off"
                                    class="input mt-1 w-40 font-mono"
                                />
                            </div>
                            <div v-if="hasMfaFactor">
                                <label class="block text-sm text-ink" :for="`mfa-${period.id}`"
                                    >Authentication code</label
                                >
                                <input
                                    :id="`mfa-${period.id}`"
                                    v-model="form.mfa_code"
                                    type="text"
                                    inputmode="numeric"
                                    autocomplete="one-time-code"
                                    maxlength="32"
                                    class="input mt-1 w-40 font-mono"
                                />
                            </div>
                            <p v-else class="text-sm text-danger">
                                Closing a period needs multi-factor authentication. Enroll a factor
                                under Account security first.
                            </p>
                            <p
                                v-for="(message, field) in errors"
                                :key="field"
                                class="text-sm text-danger"
                            >
                                {{ message }}
                            </p>
                            <div class="flex gap-2">
                                <button
                                    type="submit"
                                    :disabled="
                                        submitting ||
                                        form.confirmation !== period.key ||
                                        !hasMfaFactor
                                    "
                                    class="btn btn-danger"
                                >
                                    Close permanently
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    @click="closingId = null"
                                >
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </template>
            </li>
        </ul>
    </main>
</template>
