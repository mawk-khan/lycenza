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
    requests: CorrectionRequest[] | null;
}

interface CorrectionRequest {
    id: string;
    kind: CorrectionKind;
    status: 'pending' | 'approved' | 'rejected' | 'withdrawn';
    amount: string | null;
    reason: string;
    chargeDescription: string | null;
    toChargeDescription: string | null;
    refundMethodLabel: string | null;
    refundReference: string | null;
    refundedOn: string | null;
    requestedByName: string | null;
    requestedOn: string;
    decidedByName: string | null;
    decidedOn: string | null;
    isMine: boolean;
    canDecide: boolean;
    canWithdraw: boolean;
}

interface Props {
    payment: PaymentDetail;
    recordedOutcome: 'recorded' | 'duplicate_replay' | null;
    corrections: Corrections;
    correctedOutcome:
        'requested' | 'duplicate_replay' | 'approved' | 'rejected' | 'withdrawn' | null;
    correctionKey: string;
    hasMfaFactor: boolean;
}

const props = defineProps<Props>();

const kindLabel: Record<CorrectionKind, string> = {
    reallocation: 'Reallocated',
    reversal: 'Reversed',
    refund: 'Refunded',
};

const requestKindLabel: Record<CorrectionKind, string> = {
    reallocation: 'Move to another charge',
    reversal: 'Reverse this payment',
    refund: 'Refund money paid back',
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
    // The Payment as this page saw it: a correction recorded since makes the request stale.
    correction_count: props.corrections.history.length,
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

// A decision on this page reloads its props: request against what is shown now.
watch(
    () => props.corrections.history.length,
    (count) => {
        form.correction_count = count;
    },
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
    form.post(`/app/finance/payments/${props.payment.id}/correction-requests`, {
        preserveScroll: true,
        onSuccess: () => {
            // A submitted (or replayed) request is finished: the next one
            // needs the fresh key the reloaded page carries, never this one.
            form.reset();
            form.kind = '';
            form.idempotency_key = props.correctionKey;
            form.refunded_on = props.corrections.today;
            form.correction_count = props.corrections.history.length;
            opened.value = false;
        },
    });
}

// ORG.8C: someone other than the requester approves (which executes the
// correction, after a fresh code) or rejects; the requester may withdraw.
const decision = useForm({ mfa_code: '' });
const approvingId = ref<string | null>(null);
// A refused decision (self-decision, conflict, stale, decided) comes back on this key.
const decisionError = computed(
    () => (decision.errors as Record<string, string | undefined>).correction_request,
);

function decide(request: CorrectionRequest, action: 'approve' | 'reject' | 'withdraw'): void {
    decision.post(`/app/finance/payment-correction-requests/${request.id}/${action}`, {
        preserveScroll: true,
        onError: () => decision.reset('mfa_code'),
        onSuccess: () => {
            decision.reset();
            approvingId.value = null;
        },
    });
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-4 font-sans text-ink sm:p-8">
        <a class="text-sm underline" href="/app/finance/payments">← Payments</a>

        <p
            v-if="recordedOutcome === 'recorded'"
            role="status"
            class="mt-4 rounded-xs border border-graphite-300 bg-graphite-100 p-3 text-sm text-ink"
        >
            Payment recorded. It is now part of this School's permanent Finance record.
        </p>
        <p
            v-else-if="recordedOutcome === 'duplicate_replay'"
            role="status"
            class="mt-4 rounded-xs border border-line bg-graphite-100 p-3 text-sm text-ink"
        >
            This payment was already recorded -- nothing new was created.
        </p>
        <p
            v-if="correctedOutcome === 'requested'"
            role="status"
            class="mt-4 rounded-xs border border-graphite-300 bg-graphite-100 p-3 text-sm text-ink"
        >
            Correction requested. Nothing has changed yet: someone else must approve it, and it is
            recorded only then.
        </p>
        <p
            v-else-if="correctedOutcome === 'approved'"
            role="status"
            class="mt-4 rounded-xs border border-graphite-300 bg-graphite-100 p-3 text-sm text-ink"
        >
            Correction approved and recorded. The original payment is unchanged; the correction is
            listed below with its own journal entry.
        </p>
        <p
            v-else-if="correctedOutcome === 'rejected' || correctedOutcome === 'withdrawn'"
            role="status"
            class="mt-4 rounded-xs border border-line bg-graphite-100 p-3 text-sm text-ink"
        >
            Correction request {{ correctedOutcome }}. Nothing was changed.
        </p>
        <p
            v-else-if="correctedOutcome === 'duplicate_replay'"
            role="status"
            class="mt-4 rounded-xs border border-line bg-graphite-100 p-3 text-sm text-ink"
        >
            This correction was already requested -- nothing new was created.
        </p>

        <div
            v-if="corrections.reversed"
            role="note"
            class="mt-4 rounded-xs border border-accent-300 bg-accent-100 p-3 text-sm text-ink"
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
        <p class="mt-1 text-sm text-ink-muted">
            <template v-if="payment.source === 'manual'">
                {{ payment.methodLabel }} · received {{ payment.occurredOn }}.
            </template>
            <template v-else> {{ payment.provider }} · settled {{ payment.occurredOn }}. </template>
            Payments are never edited or deleted; any correction is recorded separately below.
        </p>

        <dl class="mt-6 grid grid-cols-1 gap-x-4 gap-y-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-ink-muted">Amount</dt>
                <dd class="font-mono">{{ formatMoney(payment.amount, payment.currency) }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Source</dt>
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
                    <dt class="text-ink-muted">Method</dt>
                    <dd>{{ payment.methodLabel }}</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Reference</dt>
                    <dd>{{ payment.manualReference ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Received on</dt>
                    <dd>{{ payment.occurredOn }}</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Recorded</dt>
                    <dd>
                        {{ formatDateTime(payment.recordedAt) }}
                        <span v-if="payment.recordedByName" class="block text-ink-muted"
                            >by {{ payment.recordedByName }}</span
                        >
                    </dd>
                </div>
            </template>
            <div v-else>
                <dt class="text-ink-muted">Provider reference</dt>
                <dd class="break-all">{{ payment.providerPaymentReference }}</dd>
            </div>
            <div v-if="payment.journalEntryId">
                <dt class="text-ink-muted">Settlement journal entry</dt>
                <dd>
                    <a
                        class="underline"
                        :href="`/app/finance/journal-entries/${payment.journalEntryId}`"
                        >View journal entry</a
                    >
                </dd>
            </div>
            <div>
                <dt class="text-ink-muted">Receipt</dt>
                <dd v-if="payment.receiptNumber">
                    <a
                        class="font-mono underline"
                        :href="`/app/finance/payments/${payment.id}/receipt`"
                        >{{ payment.receiptNumber }}</a
                    >
                    <span v-if="corrections.reversed" class="ml-1 text-ink">(reversed)</span>
                </dd>
                <dd v-else class="text-ink-muted">
                    Not issued yet (recorded before receipts existed)
                </dd>
            </div>
        </dl>

        <section v-if="corrections.history.length > 0" class="mt-8 border-t border-line pt-6">
            <h2 class="text-sm font-medium text-ink">Where this payment stands now</h2>
            <p class="mt-1 text-sm text-ink-muted">
                The original allocations below, after the corrections recorded on this payment.
            </p>
            <ul v-if="corrections.standing.length > 0" class="mt-3 divide-y divide-line text-sm">
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
            <p v-else class="mt-3 text-sm text-ink-muted">
                Nothing of this payment is on any charge.
            </p>
        </section>

        <section class="mt-8 border-t border-line pt-6">
            <h2 class="text-sm font-medium text-ink">
                {{ corrections.history.length > 0 ? 'Original allocations' : 'Allocations' }}
            </h2>
            <p class="mt-1 text-sm text-ink-muted">
                How this Payment's amount was applied across Charges when it was recorded.
                Allocations are permanent facts; corrections are recorded beside them.
            </p>

            <div v-if="payment.allocations.length > 0" class="overflow-x-auto">
                <table class="mt-4 w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-line text-xs text-ink-muted">
                            <th scope="col" class="py-2 font-medium">Charge</th>
                            <th scope="col" class="py-2 text-right font-medium">
                                Allocated amount
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
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
            <p v-else class="mt-4 text-sm text-ink-muted">No allocations recorded.</p>
        </section>

        <section v-if="corrections.history.length > 0" class="mt-8 border-t border-line pt-6">
            <h2 class="text-sm font-medium text-ink">Corrections</h2>
            <ol class="mt-3 space-y-4 text-sm">
                <li
                    v-for="entry in corrections.history"
                    :key="entry.id"
                    class="rounded-xs border border-line p-3"
                >
                    <p class="flex flex-wrap justify-between gap-2">
                        <span class="font-medium">{{ kindLabel[entry.kind] }}</span>
                        <span class="font-mono">{{
                            formatMoney(entry.amount, payment.currency)
                        }}</span>
                    </p>
                    <p class="mt-1 text-ink-muted">
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
                    <p v-if="entry.kind === 'refund'" class="mt-1 text-ink-muted">
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
                    <p class="mt-2 text-ink">Reason: {{ entry.reason }}</p>
                </li>
            </ol>
        </section>

        <section v-if="choices.length > 0" class="mt-8 border-t border-line pt-6">
            <h2 class="text-sm font-medium text-ink">Request a correction</h2>
            <p class="mt-1 text-sm text-ink-muted">
                A correction never changes or deletes this payment. You request it with your reason;
                someone else approves or rejects it, and only an approval records it as a new entry
                with its own journal entry. Fee concessions are not used for corrections.
            </p>
            <button
                v-if="!opened"
                type="button"
                class="btn btn-secondary mt-3"
                @click="opened = true"
            >
                Request correction…
            </button>

            <form v-else class="mt-4 space-y-4" @submit.prevent="submit">
                <fieldset>
                    <legend class="text-sm text-ink">What went wrong?</legend>
                    <div class="mt-2 space-y-2">
                        <label
                            v-for="c in choices"
                            :key="c.kind"
                            class="flex gap-2 rounded-xs border border-line p-3 text-sm"
                        >
                            <input v-model="form.kind" type="radio" name="kind" :value="c.kind" />
                            <span>
                                <span class="font-medium">{{ c.label }}</span>
                                <span class="block text-ink-muted">{{ c.help }}</span>
                            </span>
                        </label>
                    </div>
                    <p v-if="form.errors.kind" class="mt-1 text-sm text-danger">
                        {{ form.errors.kind }}
                    </p>
                </fieldset>

                <template v-if="form.kind === 'reallocation' || form.kind === 'refund'">
                    <div>
                        <label class="block text-sm text-ink" for="correction-charge">{{
                            form.kind === 'refund' ? 'Refund from charge' : 'Move from charge'
                        }}</label>
                        <select id="correction-charge" v-model="form.charge_id" class="input mt-1">
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
                        <p v-if="form.errors.charge_id" class="mt-1 text-sm text-danger">
                            {{ form.errors.charge_id }}
                        </p>
                    </div>
                </template>

                <div v-if="form.kind === 'reallocation'">
                    <label class="block text-sm text-ink" for="correction-to-charge"
                        >Move to charge (same Student)</label
                    >
                    <select
                        id="correction-to-charge"
                        v-model="form.to_charge_id"
                        :disabled="form.charge_id === ''"
                        class="input mt-1"
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
                        class="mt-1 text-sm text-ink-muted"
                    >
                        This Student has no other charge with an amount outstanding.
                    </p>
                    <p v-if="form.errors.to_charge_id" class="mt-1 text-sm text-danger">
                        {{ form.errors.to_charge_id }}
                    </p>
                </div>

                <div v-if="form.kind === 'reallocation' || form.kind === 'refund'">
                    <label class="block text-sm text-ink" for="correction-amount"
                        >Amount (INR)</label
                    >
                    <input
                        id="correction-amount"
                        v-model="form.amount"
                        type="text"
                        inputmode="decimal"
                        class="input mt-1 w-48 font-mono"
                    />
                    <p v-if="form.errors.amount" class="mt-1 text-sm text-danger">
                        {{ form.errors.amount }}
                    </p>
                </div>

                <template v-if="form.kind === 'refund'">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm text-ink" for="correction-account"
                                >Paid out of</label
                            >
                            <select
                                id="correction-account"
                                v-model="form.account_id"
                                class="input mt-1"
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
                            <p v-if="form.errors.account_id" class="mt-1 text-sm text-danger">
                                {{ form.errors.account_id }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm text-ink" for="correction-method"
                                >Paid back by</label
                            >
                            <select id="correction-method" v-model="form.method" class="input mt-1">
                                <option value="" disabled>Choose a method</option>
                                <option
                                    v-for="m in corrections.methods"
                                    :key="m.value"
                                    :value="m.value"
                                >
                                    {{ m.label }}
                                </option>
                            </select>
                            <p v-if="form.errors.method" class="mt-1 text-sm text-danger">
                                {{ form.errors.method }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm text-ink" for="correction-date"
                                >Paid back on</label
                            >
                            <input
                                id="correction-date"
                                v-model="form.refunded_on"
                                type="date"
                                :min="corrections.refundEarliest"
                                :max="corrections.today"
                                class="input mt-1"
                            />
                            <p v-if="form.errors.refunded_on" class="mt-1 text-sm text-danger">
                                {{ form.errors.refunded_on }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm text-ink" for="correction-reference"
                                >Reference (optional)</label
                            >
                            <input
                                id="correction-reference"
                                v-model="form.reference"
                                type="text"
                                maxlength="64"
                                class="input mt-1"
                            />
                            <p v-if="form.errors.reference" class="mt-1 text-sm text-danger">
                                {{ form.errors.reference }}
                            </p>
                        </div>
                    </div>
                    <p class="text-sm text-ink-muted">
                        After the refund the charge is owed again for that amount. If nothing should
                        be owed, cancel the charge or review a concession through the normal paths.
                    </p>
                </template>

                <div
                    v-if="form.kind === 'reversal'"
                    class="rounded-xs border border-accent-300 bg-accent-100 p-3 text-sm text-ink"
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
                    <p v-if="form.errors.confirm" class="mt-1 text-danger">
                        {{ form.errors.confirm }}
                    </p>
                </div>

                <div v-if="form.kind !== ''">
                    <label class="block text-sm text-ink" for="correction-reason">Reason</label>
                    <textarea
                        id="correction-reason"
                        v-model="form.reason"
                        rows="3"
                        maxlength="500"
                        class="input mt-1"
                    ></textarea>
                    <p class="mt-1 text-xs text-ink-muted">
                        Required. The approver sees it, and it is kept with the correction for
                        anyone reviewing it later.
                    </p>
                    <p v-if="form.errors.reason" class="mt-1 text-sm text-danger">
                        {{ form.errors.reason }}
                    </p>
                </div>

                <p v-if="form.errors.idempotency_key" class="text-sm text-danger">
                    {{ form.errors.idempotency_key }}
                </p>

                <div class="flex flex-wrap gap-3">
                    <button
                        type="submit"
                        :disabled="form.processing || form.kind === ''"
                        class="btn btn-primary"
                    >
                        Submit request
                    </button>
                    <button
                        type="button"
                        :disabled="form.processing"
                        class="btn btn-secondary"
                        @click="opened = false"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </section>

        <section
            v-if="corrections.requests && corrections.requests.length > 0"
            class="mt-8 border-t border-line pt-6"
        >
            <h2 class="text-sm font-medium text-ink">Correction requests</h2>
            <p class="mt-1 text-sm text-ink-muted">
                A request changes nothing until someone other than its requester approves it.
                Approving records the correction at once.
            </p>
            <p v-if="decisionError" class="mt-2 text-sm text-danger">
                {{ decisionError }}
            </p>
            <ol class="mt-3 space-y-3">
                <li
                    v-for="r in corrections.requests"
                    :key="r.id"
                    class="rounded-xs border border-line p-3 text-sm"
                >
                    <p class="font-medium">
                        {{ requestKindLabel[r.kind] }}
                        <template v-if="r.amount">
                            · {{ formatMoney(r.amount, payment.currency) }}</template
                        >
                        <span class="ml-1 text-ink-muted">({{ r.status }})</span>
                    </p>
                    <p v-if="r.chargeDescription" class="text-ink-muted">
                        From {{ r.chargeDescription
                        }}<template v-if="r.toChargeDescription">
                            to {{ r.toChargeDescription }}</template
                        >
                    </p>
                    <p v-if="r.refundMethodLabel" class="text-ink-muted">
                        Paid back by {{ r.refundMethodLabel }} on {{ r.refundedOn
                        }}<template v-if="r.refundReference"> · {{ r.refundReference }}</template>
                    </p>
                    <p class="mt-1 text-ink">{{ r.reason }}</p>
                    <p class="mt-1 text-xs text-ink-muted">
                        Requested by {{ r.requestedByName ?? 'unknown' }} on {{ r.requestedOn
                        }}<template v-if="r.decidedOn">
                            · {{ r.status }}
                            <template v-if="r.decidedByName">by {{ r.decidedByName }}</template>
                            on {{ r.decidedOn }}</template
                        >
                    </p>

                    <div v-if="r.canDecide" class="mt-3 space-y-3">
                        <template v-if="approvingId === r.id">
                            <MfaCodeField
                                :id="`payment-correction-approve-mfa-${r.id}`"
                                v-model="decision.mfa_code"
                                :has-mfa-factor="hasMfaFactor"
                                action="Approving a payment correction"
                                :error="decision.errors.mfa_code"
                            />
                            <div class="flex flex-wrap gap-3">
                                <button
                                    type="button"
                                    :disabled="decision.processing || !hasMfaFactor"
                                    class="btn btn-primary"
                                    @click="decide(r, 'approve')"
                                >
                                    Approve and record
                                </button>
                                <button
                                    type="button"
                                    :disabled="decision.processing"
                                    class="btn btn-secondary"
                                    @click="approvingId = null"
                                >
                                    Cancel
                                </button>
                            </div>
                        </template>
                        <div v-else class="flex flex-wrap gap-3">
                            <button
                                type="button"
                                class="btn btn-secondary"
                                @click="approvingId = r.id"
                            >
                                Approve…
                            </button>
                            <button
                                type="button"
                                :disabled="decision.processing"
                                class="btn btn-secondary"
                                @click="decide(r, 'reject')"
                            >
                                Reject
                            </button>
                        </div>
                    </div>
                    <button
                        v-if="r.canWithdraw"
                        type="button"
                        :disabled="decision.processing"
                        class="btn btn-secondary mt-3"
                        @click="decide(r, 'withdraw')"
                    >
                        Withdraw request
                    </button>
                </li>
            </ol>
        </section>
    </main>
</template>
