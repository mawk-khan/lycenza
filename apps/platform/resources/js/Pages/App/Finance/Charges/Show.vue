<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { formatMoney } from '../../../../money';

interface ChargeDetail {
    id: string;
    studentId: string;
    studentName: string | null;
    studentNumber: string | null;
    academicYearId: string;
    academicYearName: string | null;
    description: string;
    amount: string;
    currency: string;
    dueDate: string | null;
    receivableLedgerAccountId: string;
    revenueLedgerAccountId: string;
    journalEntryId: string;
    cancelledAt: string | null;
    cancellationJournalEntryId: string | null;
    createdAt: string;
}

interface Adjustment {
    id: string;
    feeConcessionId: string;
    category: string;
    amount: string;
    currency: string;
    postedAt: string;
    cancelledAt: string | null;
}

interface Props {
    charge: ChargeDetail;
    canManage: boolean;
    canRecordPayment: boolean;
    adjustments: Adjustment[] | null;
    canRequestConcession: boolean;
}

const props = defineProps<Props>();

const cancelling = ref(false);
const reason = ref('');
const cancelError = ref('');

function cancel(): void {
    const confirmed = window.confirm(
        'Cancel this charge? This creates a reversing journal entry and preserves the original charge -- it is not deleted.',
    );
    if (!confirmed) return;

    cancelling.value = true;
    cancelError.value = '';
    router.post(
        `/app/finance/charges/${props.charge.id}/cancel`,
        { reason: reason.value || undefined },
        {
            onError: (errors) => {
                cancelError.value = errors.cancellation ?? 'That charge could not be cancelled.';
            },
            onFinish: () => (cancelling.value = false),
        },
    );
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance/charges">← Charges</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ charge.description }}</h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ charge.studentName ?? charge.studentId }}
                    <span v-if="charge.studentNumber">({{ charge.studentNumber }})</span>
                    · {{ charge.academicYearName ?? charge.academicYearId }}
                </p>
            </div>
            <span
                v-if="charge.cancelledAt"
                class="shrink-0 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700"
                >Cancelled</span
            >
            <span
                v-else
                class="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"
                >Assessed</span
            >
        </div>

        <p
            v-if="cancelError"
            class="mt-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            {{ cancelError }}
        </p>

        <dl class="mt-6 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <div>
                <dt class="text-slate-500">Amount</dt>
                <dd class="font-mono">{{ formatMoney(charge.amount, charge.currency) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Due date</dt>
                <dd>{{ charge.dueDate ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Assessed</dt>
                <dd>{{ charge.createdAt }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Recognition journal entry</dt>
                <dd>
                    <a
                        class="underline"
                        :href="`/app/finance/journal-entries/${charge.journalEntryId}`"
                        >View journal entry</a
                    >
                </dd>
            </div>
            <div v-if="charge.cancelledAt">
                <dt class="text-slate-500">Cancelled</dt>
                <dd>{{ charge.cancelledAt }}</dd>
            </div>
            <div v-if="charge.cancellationJournalEntryId">
                <dt class="text-slate-500">Cancellation journal entry</dt>
                <dd>
                    <a
                        class="underline"
                        :href="`/app/finance/journal-entries/${charge.cancellationJournalEntryId}`"
                        >View journal entry</a
                    >
                </dd>
            </div>
        </dl>

        <section v-if="adjustments !== null" class="mt-8 border-t border-slate-200 pt-6">
            <h2 class="text-sm font-medium text-slate-900">Concession adjustments</h2>
            <p v-if="adjustments.length === 0" class="mt-1 text-sm text-slate-500">
                No concession has been posted against this charge.
            </p>
            <ul v-else class="mt-2 divide-y divide-slate-100 text-sm">
                <li
                    v-for="a in adjustments"
                    :key="a.id"
                    class="flex items-center justify-between gap-4 py-2"
                >
                    <a class="underline" :href="`/app/finance/concessions/${a.feeConcessionId}`">{{
                        a.category
                    }}</a>
                    <span
                        class="font-mono"
                        :class="{ 'text-slate-400 line-through': a.cancelledAt }"
                    >
                        {{ formatMoney(a.amount, a.currency) }}
                    </span>
                </li>
            </ul>
        </section>

        <section
            v-if="canRequestConcession && !charge.cancelledAt"
            class="mt-8 border-t border-slate-200 pt-6"
        >
            <h2 class="text-sm font-medium text-slate-900">Concession, scholarship or waiver</h2>
            <p class="mt-1 text-sm text-slate-500">
                Request a fixed reduction of this charge. Another person must approve it; the charge
                amount itself never changes.
            </p>
            <a
                class="mt-3 inline-block rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
                :href="`/app/finance/concessions/create?charge_id=${charge.id}`"
                >Request concession</a
            >
        </section>

        <section
            v-if="canRecordPayment && !charge.cancelledAt"
            class="mt-8 border-t border-slate-200 pt-6"
        >
            <h2 class="text-sm font-medium text-slate-900">Payment received?</h2>
            <p class="mt-1 text-sm text-slate-500">
                Record cash, bank transfer or cheque money already received against this charge.
            </p>
            <a
                class="mt-3 inline-block rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
                :href="`/app/finance/payments/record?student_id=${charge.studentId}&charge_id=${charge.id}`"
                >Record offline payment</a
            >
        </section>

        <section
            v-if="canManage && !charge.cancelledAt"
            class="mt-8 border-t border-slate-200 pt-6"
        >
            <h2 class="text-sm font-medium text-slate-900">Cancel this charge</h2>
            <p class="mt-1 text-sm text-slate-500">
                Cancelling records a reversing journal entry against the original recognition entry.
                The charge itself is kept, marked cancelled -- never deleted or edited. A charge
                with Payment allocations already applied, or with a live concession adjustment,
                cannot be cancelled.
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
                :disabled="cancelling"
                class="mt-3 rounded border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
                @click="cancel"
            >
                Cancel charge
            </button>
        </section>
    </main>
</template>
