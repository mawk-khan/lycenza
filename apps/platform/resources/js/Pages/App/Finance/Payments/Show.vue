<script setup lang="ts">
import { formatDateTime } from '../../../../datetime';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import MfaCodeField from '../../../../Components/MfaCodeField.vue';
import { formatMoney } from '../../../../money';

interface PaymentAllocation {
    chargeId: string;
    amount: string;
}

interface PaymentDetail {
    id: string;
    source: 'provider' | 'manual';
    provider: string | null;
    providerPaymentReference: string | null;
    method: string | null;
    methodLabel: string | null;
    manualReference: string | null;
    recordedByName: string | null;
    occurredOn: string;
    recordedAt: string;
    amount: string;
    currency: string;
    settlementLedgerAccountId: string | null;
    journalEntryId: string | null;
    settledAt: string;
    allocations: PaymentAllocation[];
    receiptNumber: string | null;
}

type CorrectionKind = 'reallocation' | 'reversal' | 'refund';

interface Standing {
    chargeId: string;
    studentId: string | null;
    description: string | null;
    amount: string;
}

interface HistoryLine {
    chargeId: string;
    description: string | null;
    direction: 'release' | 'apply';
    amount: string;
}

interface HistoryEntry {
    id: string;
    kind: CorrectionKind;
    amount: string;
    reason: string;
    recordedAt: string;
    recordedOn: string;
    byName: string | null;
    journalEntryId: string | null;
    refundMethodLabel: string | null;
    refundReference: string | null;
    refundedOn: string | null;
    lines: HistoryLine[];
}

interface Destination {
    chargeId: string;
    studentId: string;
    description: string;
    outstanding: string;
    dueDate: string | null;
}

interface Corrections {
    reversed: { on: string; reason: string } | null;
    standing: Standing[];
    history: HistoryEntry[];
    canReallocate: boolean;
    canReverseOrRefund: boolean;
    canRecordReplacement: boolean;
    studentIds: string[];
    destinations: Destination[];
    refundAccounts: { id: string; code: string; name: string }[];
    methods: { value: string; label: string }[];
    refundEarliest: string;
    today: string;
}

interface Props {
    payment: PaymentDetail;
    recordedOutcome: 'recorded' | 'duplicate_replay' | null;
    corrections: Corrections;
    correctedOutcome: 'recorded' | 'duplicate_replay' | null;
    correctionKey: string;
    hasMfaFactor: boolean;
}

const props = defineProps<Props>();

const kindLabel: Record<CorrectionKind, string> = {
    reallocation: 'Reallocated',
    reversal: 'Reversed',
    refund: 'Refunded',
};

const choices = computed(() => {
    const list: { kind: CorrectionKind; label: string; help: string }[] = [];
    if (props.corrections.canReallocate && props.corrections.standing.length > 0) {
        list.push({
            kind: 'reallocation',
            label: 'Move to another charge',
            help: 'The money is right but sits on the wrong charge of the same Student.',
        });
    }
    if (props.corrections.canReverseOrRefund && props.corrections.standing.length > 0) {
        list.push({
            kind: 'reversal',
            label: 'Reverse this payment',
            help: 'The payment should not stand as recorded (wrong amount, entered twice, wrong Student). Record the right payment afterwards.',
        });
        list.push({
            kind: 'refund',
            label: 'Refund money paid back',
            help: 'Money actually left the School and was paid back to the payer.',
        });
    }
    return list;
});

const form = useForm({
    kind: '' as CorrectionKind | '',
    idempotency_key: props.correctionKey,
    reason: '',
    charge_id: '',
    to_charge_id: '',
    amount: '',
    account_id: '',
    method: '',
    refunded_on: props.corrections.today,
    reference: '',
    confirm: false,
    mfa_code: '',
});

const opened = ref(false);

const source = computed(() =>
    props.corrections.standing.find((s) => s.chargeId === form.charge_id),
);
const destinations = computed(() =>
    props.corrections.destinations.filter(
        (d) =>
            source.value !== undefined &&
            d.studentId === source.value.studentId &&
            d.chargeId !== form.charge_id,
    ),
);

watch(
    () => form.kind,
    () => {
        form.clearErrors();
        form.charge_id =
            props.corrections.standing.length === 1 ? props.corrections.standing[0].chargeId : '';
        form.to_charge_id = '';
        form.amount = '';
        form.confirm = false;
    },
);
watch(
    () => form.charge_id,
    () => {
        form.to_charge_id = '';
        form.amount = source.value?.amount ?? '';
    },
);

function submit(): void {
    form.post(`/app/finance/payments/${props.payment.id}/corrections`, {
        preserveScroll: true,
        onError: () => form.reset('mfa_code'),
        onSuccess: () => {
            // A recorded (or replayed) correction is finished: the next one
            // needs the fresh key the reloaded page carries, never this one.
            form.reset();
            form.kind = '';
            form.idempotency_key = props.correctionKey;
            form.refunded_on = props.corrections.today;
            opened.value = false;
        },
    });
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-4 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/finance/payments">← Payments</a>

        <p
            v-if="recordedOutcome === 'recorded'"
            role="status"
            class="mt-4 rounded border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800"
        >
            Payment recorded. It is now part of this School's permanent Finance record.
        </p>
        <p
            v-else-if="recordedOutcome === 'duplicate_replay'"
            role="status"
            class="mt-4 rounded border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700"
        >
            This payment was already recorded -- nothing new was created.
        </p>
        <p
            v-if="correctedOutcome === 'recorded'"
            role="status"
            class="mt-4 rounded border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800"
        >
            Correction recorded. The original payment is unchanged; the correction is listed below
            with its own journal entry.
        </p>
        <p
            v-else-if="correctedOutcome === 'duplicate_replay'"
            role="status"
            class="mt-4 rounded border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700"
        >
            This correction was already recorded -- nothing new was created.
        </p>

        <div
            v-if="corrections.reversed"
            role="note"
            class="mt-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
        >
            <p class="font-medium">Reversed on {{ corrections.reversed.on }}.</p>
            <p class="mt-1">
                This payment no longer counts against any charge. Its receipt number is kept and the
                receipt is marked reversed.
            </p>
            <p v-if="corrections.canRecordReplacement" class="mt-2">
                <a
                    class="underline"
                    :href="
                        corrections.studentIds.length === 1
                            ? `/app/finance/payments/record?student_id=${corrections.studentIds[0]}`
                            : '/app/finance/payments/record'
                    "
                    >Record the correct payment</a
                >
            </p>
        </div>

        <h1 class="mt-2 text-xl font-semibold">
            {{ payment.source === 'manual' ? 'Offline payment' : 'Payment' }}
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            <template v-if="payment.source === 'manual'">
                {{ payment.methodLabel }} · received {{ payment.occurredOn }}.
            </template>
            <template v-else> {{ payment.provider }} · settled {{ payment.occurredOn }}. </template>
            Payments are never edited or deleted; any correction is recorded separately below.
        </p>

        <dl class="mt-6 grid grid-cols-1 gap-x-4 gap-y-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-slate-500">Amount</dt>
                <dd class="font-mono">{{ formatMoney(payment.amount, payment.currency) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Source</dt>
                <dd>
                    {{
                        payment.source === 'manual'
                            ? 'Recorded offline in Lycenza'
                            : 'Payment provider'
                    }}
                </dd>
            </div>
            <template v-if="payment.source === 'manual'">
                <div>
                    <dt class="text-slate-500">Method</dt>
                    <dd>{{ payment.methodLabel }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Reference</dt>
                    <dd>{{ payment.manualReference ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Received on</dt>
                    <dd>{{ payment.occurredOn }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Recorded</dt>
                    <dd>
                        {{ formatDateTime(payment.recordedAt) }}
                        <span v-if="payment.recordedByName" class="block text-slate-500"
                            >by {{ payment.recordedByName }}</span
                        >
                    </dd>
                </div>
            </template>
            <div v-else>
                <dt class="text-slate-500">Provider reference</dt>
                <dd class="break-all">{{ payment.providerPaymentReference }}</dd>
            </div>
            <div v-if="payment.journalEntryId">
                <dt class="text-slate-500">Settlement journal entry</dt>
                <dd>
                    <a
                        class="underline"
                        :href="`/app/finance/journal-entries/${payment.journalEntryId}`"
                        >View journal entry</a
                    >
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">Receipt</dt>
                <dd v-if="payment.receiptNumber">
                    <a
                        class="font-mono underline"
                        :href="`/app/finance/payments/${payment.id}/receipt`"
                        >{{ payment.receiptNumber }}</a
                    >
                    <span v-if="corrections.reversed" class="ml-1 text-amber-800">(reversed)</span>
                </dd>
                <dd v-else class="text-slate-500">
                    Not issued yet (recorded before receipts existed)
                </dd>
            </div>
        </dl>

        <section v-if="corrections.history.length > 0" class="mt-8 border-t border-slate-200 pt-6">
            <h2 class="text-sm font-medium text-slate-900">Where this payment stands now</h2>
            <p class="mt-1 text-sm text-slate-500">
                The original allocations below, after the corrections recorded on this payment.
            </p>
            <ul
                v-if="corrections.standing.length > 0"
                class="mt-3 divide-y divide-slate-100 text-sm"
            >
                <li
                    v-for="s in corrections.standing"
                    :key="s.chargeId"
                    class="flex flex-wrap justify-between gap-2 py-2"
                >
                    <a class="underline" :href="`/app/finance/charges/${s.chargeId}`">{{
                        s.description ?? 'View charge'
                    }}</a>
                    <span class="font-mono">{{ formatMoney(s.amount, payment.currency) }}</span>
                </li>
            </ul>
            <p v-else class="mt-3 text-sm text-slate-500">
                Nothing of this payment is on any charge.
            </p>
        </section>

        <section class="mt-8 border-t border-slate-200 pt-6">
            <h2 class="text-sm font-medium text-slate-900">
                {{ corrections.history.length > 0 ? 'Original allocations' : 'Allocations' }}
            </h2>
            <p class="mt-1 text-sm text-slate-500">
                How this Payment's amount was applied across Charges when it was recorded.
                Allocations are permanent facts; corrections are recorded beside them.
            </p>

            <div v-if="payment.allocations.length > 0" class="overflow-x-auto">
                <table class="mt-4 w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">Charge</th>
                            <th scope="col" class="py-2 text-right font-medium">
                                Allocated amount
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="allocation in payment.allocations" :key="allocation.chargeId">
                            <td class="py-3">
                                <a
                                    class="underline"
                                    :href="`/app/finance/charges/${allocation.chargeId}`"
                                    >View charge</a
                                >
                            </td>
                            <td class="py-3 text-right font-mono">
                                {{ formatMoney(allocation.amount, payment.currency) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="mt-4 text-sm text-slate-500">No allocations recorded.</p>
        </section>

        <section v-if="corrections.history.length > 0" class="mt-8 border-t border-slate-200 pt-6">
            <h2 class="text-sm font-medium text-slate-900">Corrections</h2>
            <ol class="mt-3 space-y-4 text-sm">
                <li
                    v-for="entry in corrections.history"
                    :key="entry.id"
                    class="rounded border border-slate-200 p-3"
                >
                    <p class="flex flex-wrap justify-between gap-2">
                        <span class="font-medium">{{ kindLabel[entry.kind] }}</span>
                        <span class="font-mono">{{
                            formatMoney(entry.amount, payment.currency)
                        }}</span>
                    </p>
                    <p class="mt-1 text-slate-500">
                        {{ entry.recordedOn
                        }}<template v-if="entry.byName"> · by {{ entry.byName }}</template>
                        <template v-if="entry.journalEntryId">
                            ·
                            <a
                                class="underline"
                                :href="`/app/finance/journal-entries/${entry.journalEntryId}`"
                                >journal entry</a
                            >
                        </template>
                    </p>
                    <p v-if="entry.kind === 'refund'" class="mt-1 text-slate-600">
                        Paid back by {{ entry.refundMethodLabel }} on {{ entry.refundedOn
                        }}<template v-if="entry.refundReference">
                            · ref {{ entry.refundReference }}</template
                        >
                    </p>
                    <ul class="mt-2 space-y-1">
                        <li
                            v-for="line in entry.lines"
                            :key="`${line.direction}-${line.chargeId}`"
                            class="flex flex-wrap justify-between gap-2"
                        >
                            <span
                                >{{ line.direction === 'release' ? 'Taken off' : 'Applied to' }}
                                {{ line.description ?? 'charge' }}</span
                            >
                            <span class="font-mono">{{
                                formatMoney(line.amount, payment.currency)
                            }}</span>
                        </li>
                    </ul>
                    <p class="mt-2 text-slate-700">Reason: {{ entry.reason }}</p>
                </li>
            </ol>
        </section>

        <section v-if="choices.length > 0" class="mt-8 border-t border-slate-200 pt-6">
            <h2 class="text-sm font-medium text-slate-900">Correct this payment</h2>
            <p class="mt-1 text-sm text-slate-500">
                A correction never changes or deletes this payment. It is recorded as a new entry
                with its own journal entry and your reason. Fee concessions are not used for
                corrections.
            </p>
            <button
                v-if="!opened"
                type="button"
                class="mt-3 rounded border border-slate-300 px-4 py-2 text-sm"
                @click="opened = true"
            >
                Correct this payment…
            </button>

            <form v-else class="mt-4 space-y-4" @submit.prevent="submit">
                <fieldset>
                    <legend class="text-sm text-slate-700">What went wrong?</legend>
                    <div class="mt-2 space-y-2">
                        <label
                            v-for="c in choices"
                            :key="c.kind"
                            class="flex gap-2 rounded border border-slate-200 p-3 text-sm"
                        >
                            <input v-model="form.kind" type="radio" name="kind" :value="c.kind" />
                            <span>
                                <span class="font-medium">{{ c.label }}</span>
                                <span class="block text-slate-500">{{ c.help }}</span>
                            </span>
                        </label>
                    </div>
                    <p v-if="form.errors.kind" class="mt-1 text-sm text-red-700">
                        {{ form.errors.kind }}
                    </p>
                </fieldset>

                <template v-if="form.kind === 'reallocation' || form.kind === 'refund'">
                    <div>
                        <label class="block text-sm text-slate-700" for="correction-charge">{{
                            form.kind === 'refund' ? 'Refund from charge' : 'Move from charge'
                        }}</label>
                        <select
                            id="correction-charge"
                            v-model="form.charge_id"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="" disabled>Choose a charge</option>
                            <option
                                v-for="s in corrections.standing"
                                :key="s.chargeId"
                                :value="s.chargeId"
                            >
                                {{ s.description }} —
                                {{ formatMoney(s.amount, payment.currency) }} of this payment
                            </option>
                        </select>
                        <p v-if="form.errors.charge_id" class="mt-1 text-sm text-red-700">
                            {{ form.errors.charge_id }}
                        </p>
                    </div>
                </template>

                <div v-if="form.kind === 'reallocation'">
                    <label class="block text-sm text-slate-700" for="correction-to-charge"
                        >Move to charge (same Student)</label
                    >
                    <select
                        id="correction-to-charge"
                        v-model="form.to_charge_id"
                        :disabled="form.charge_id === ''"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="" disabled>
                            Choose a charge with an amount outstanding
                        </option>
                        <option v-for="d in destinations" :key="d.chargeId" :value="d.chargeId">
                            {{ d.description }} —
                            {{ formatMoney(d.outstanding, payment.currency) }} outstanding<template
                                v-if="d.dueDate"
                                >, due {{ d.dueDate }}</template
                            >
                        </option>
                    </select>
                    <p
                        v-if="form.charge_id !== '' && destinations.length === 0"
                        class="mt-1 text-sm text-slate-500"
                    >
                        This Student has no other charge with an amount outstanding.
                    </p>
                    <p v-if="form.errors.to_charge_id" class="mt-1 text-sm text-red-700">
                        {{ form.errors.to_charge_id }}
                    </p>
                </div>

                <div v-if="form.kind === 'reallocation' || form.kind === 'refund'">
                    <label class="block text-sm text-slate-700" for="correction-amount"
                        >Amount (INR)</label
                    >
                    <input
                        id="correction-amount"
                        v-model="form.amount"
                        type="text"
                        inputmode="decimal"
                        class="mt-1 w-48 rounded border border-slate-300 px-3 py-2 font-mono text-sm"
                    />
                    <p v-if="form.errors.amount" class="mt-1 text-sm text-red-700">
                        {{ form.errors.amount }}
                    </p>
                </div>

                <template v-if="form.kind === 'refund'">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm text-slate-700" for="correction-account"
                                >Paid out of</label
                            >
                            <select
                                id="correction-account"
                                v-model="form.account_id"
                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                            >
                                <option value="" disabled>Choose an account</option>
                                <option
                                    v-for="a in corrections.refundAccounts"
                                    :key="a.id"
                                    :value="a.id"
                                >
                                    {{ a.code }} · {{ a.name }}
                                </option>
                            </select>
                            <p v-if="form.errors.account_id" class="mt-1 text-sm text-red-700">
                                {{ form.errors.account_id }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm text-slate-700" for="correction-method"
                                >Paid back by</label
                            >
                            <select
                                id="correction-method"
                                v-model="form.method"
                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                            >
                                <option value="" disabled>Choose a method</option>
                                <option
                                    v-for="m in corrections.methods"
                                    :key="m.value"
                                    :value="m.value"
                                >
                                    {{ m.label }}
                                </option>
                            </select>
                            <p v-if="form.errors.method" class="mt-1 text-sm text-red-700">
                                {{ form.errors.method }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm text-slate-700" for="correction-date"
                                >Paid back on</label
                            >
                            <input
                                id="correction-date"
                                v-model="form.refunded_on"
                                type="date"
                                :min="corrections.refundEarliest"
                                :max="corrections.today"
                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                            />
                            <p v-if="form.errors.refunded_on" class="mt-1 text-sm text-red-700">
                                {{ form.errors.refunded_on }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm text-slate-700" for="correction-reference"
                                >Reference (optional)</label
                            >
                            <input
                                id="correction-reference"
                                v-model="form.reference"
                                type="text"
                                maxlength="64"
                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                            />
                            <p v-if="form.errors.reference" class="mt-1 text-sm text-red-700">
                                {{ form.errors.reference }}
                            </p>
                        </div>
                    </div>
                    <p class="text-sm text-slate-500">
                        After the refund the charge is owed again for that amount. If nothing should
                        be owed, cancel the charge or review a concession through the normal paths.
                    </p>
                </template>

                <div
                    v-if="form.kind === 'reversal'"
                    class="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
                >
                    <p>
                        Everything this payment still has on its charges ({{
                            corrections.standing
                                .map((s) => formatMoney(s.amount, payment.currency))
                                .join(' + ')
                        }}) is taken back off. Those charges are owed again. A reversed payment
                        cannot be corrected further; record the right payment as a new one.
                    </p>
                    <label class="mt-2 flex gap-2">
                        <input v-model="form.confirm" type="checkbox" />
                        <span>I confirm this payment should be reversed.</span>
                    </label>
                    <p v-if="form.errors.confirm" class="mt-1 text-red-700">
                        {{ form.errors.confirm }}
                    </p>
                </div>

                <div v-if="form.kind !== ''">
                    <label class="block text-sm text-slate-700" for="correction-reason"
                        >Reason</label
                    >
                    <textarea
                        id="correction-reason"
                        v-model="form.reason"
                        rows="3"
                        maxlength="500"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    ></textarea>
                    <p class="mt-1 text-xs text-slate-500">
                        Required. Kept with the correction for anyone reviewing it later.
                    </p>
                    <p v-if="form.errors.reason" class="mt-1 text-sm text-red-700">
                        {{ form.errors.reason }}
                    </p>
                </div>

                <MfaCodeField
                    v-if="form.kind !== ''"
                    id="payment-correction-mfa"
                    v-model="form.mfa_code"
                    :has-mfa-factor="hasMfaFactor"
                    action="Correcting a payment"
                    :error="form.errors.mfa_code"
                />

                <p v-if="form.errors.idempotency_key" class="text-sm text-red-700">
                    {{ form.errors.idempotency_key }}
                </p>

                <div class="flex flex-wrap gap-3">
                    <button
                        type="submit"
                        :disabled="form.processing || form.kind === '' || !hasMfaFactor"
                        class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                    >
                        Record correction
                    </button>
                    <button
                        type="button"
                        :disabled="form.processing"
                        class="rounded border border-slate-300 px-4 py-2 text-sm disabled:opacity-50"
                        @click="opened = false"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    </main>
</template>
