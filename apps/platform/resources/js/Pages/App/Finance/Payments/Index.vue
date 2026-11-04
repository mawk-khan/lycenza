<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import { formatCalendarDate, formatDateTime } from '../../../../datetime';
import { formatMoney } from '../../../../money';

/**
 * LC-4 (M4): the collections list -- receipts recorded on a School day
 * (default: today), who they were for, how they were paid and where each
 * stands now. Totals cover every matching receipt, not just this page.
 * Corrections happen on a payment's own page.
 */
type Status = 'received' | 'partly_refunded' | 'refunded' | 'reversed';

interface PaymentRow {
    id: string;
    receiptNumber: string | null;
    students: string[];
    recordedAt: string;
    receivedOn: string;
    methodLabel: string;
    amount: string;
    currency: string;
    status: Status;
    refunded: string;
    reallocated: boolean;
    recordedBy: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Total {
    currency: string;
    receipts: number;
    gross: string;
    retained: string;
}

interface Props {
    payments: { data: PaymentRow[]; links: PageLink[]; total: number };
    totals: Total[];
    filters: { from: string; to: string; receipt: string; student: string; method: string };
    today: string;
    methods: Array<{ value: string; label: string }>;
    canRecord: boolean;
}

const props = defineProps<Props>();

const from = ref(props.filters.from);
const to = ref(props.filters.to);
const receipt = ref(props.filters.receipt);
const student = ref(props.filters.student);
const method = ref(props.filters.method);

function apply(): void {
    router.get(
        '/app/finance/payments',
        {
            from: from.value || undefined,
            to: to.value && to.value !== from.value ? to.value : undefined,
            receipt: receipt.value || undefined,
            student: student.value || undefined,
            method: method.value || undefined,
        },
        { preserveScroll: true, replace: true },
    );
}

function showToday(): void {
    from.value = props.today;
    to.value = props.today;
    receipt.value = '';
    student.value = '';
    method.value = '';
    apply();
}

const isSingleDay = computed(() => props.filters.from === props.filters.to);
const isToday = computed(() => isSingleDay.value && props.filters.from === props.today);
const period = computed(() =>
    isSingleDay.value
        ? formatCalendarDate(props.filters.from)
        : `${formatCalendarDate(props.filters.from)} – ${formatCalendarDate(props.filters.to)}`,
);
const narrowed = computed(
    () => !!(props.filters.receipt || props.filters.student || props.filters.method),
);

const STATUS: Record<Status, { label: string; className: string }> = {
    received: { label: 'Received', className: 'tag-positive' },
    partly_refunded: { label: 'Partly refunded', className: 'tag-info' },
    refunded: { label: 'Refunded', className: 'tag-info' },
    reversed: { label: 'Reversed', className: 'tag-attention' },
};
</script>

<template>
    <main class="mx-auto max-w-5xl p-4 font-sans text-ink sm:p-8">
        <a class="text-sm underline" href="/app/finance">← Finance</a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Payments received</h1>
                <p class="mt-1 text-sm text-ink-muted">
                    Receipts recorded {{ isToday ? 'today' : 'on' }} {{ period }} (School time).
                    Recorded payments are never edited; a correction shows as the status.
                </p>
            </div>
            <a v-if="canRecord" href="/app/finance/payments/record" class="btn btn-primary shrink-0"
                >Record offline payment</a
            >
        </div>

        <form
            class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6 lg:items-end"
            role="search"
            aria-label="Filter payments"
            @submit.prevent="apply"
        >
            <div>
                <label class="block text-sm text-ink-muted" for="filter-from">Recorded on</label>
                <input
                    id="filter-from"
                    v-model="from"
                    type="date"
                    :max="today"
                    class="input mt-1"
                />
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="filter-to">To (optional)</label>
                <input
                    id="filter-to"
                    v-model="to"
                    type="date"
                    :min="from"
                    :max="today"
                    class="input mt-1"
                />
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="filter-receipt"
                    >Receipt number</label
                >
                <input
                    id="filter-receipt"
                    v-model="receipt"
                    type="search"
                    placeholder="e.g. 000123"
                    class="input mt-1"
                />
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="filter-student">Student</label>
                <input
                    id="filter-student"
                    v-model="student"
                    type="search"
                    placeholder="Name or number"
                    class="input mt-1"
                />
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="filter-method">Method</label>
                <select id="filter-method" v-model="method" class="input mt-1">
                    <option value="">All methods</option>
                    <option v-for="m in methods" :key="m.value" :value="m.value">
                        {{ m.label }}
                    </option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary">Show</button>
                <button
                    v-if="!isToday || narrowed"
                    type="button"
                    class="btn btn-secondary"
                    @click="showToday"
                >
                    Today
                </button>
            </div>
        </form>

        <section class="mt-6" aria-labelledby="totals-heading">
            <h2 id="totals-heading" class="sr-only">Totals</h2>
            <dl
                v-for="t in totals"
                :key="t.currency"
                class="grid grid-cols-1 gap-3 rounded-xs border border-line bg-graphite-100 p-4 text-sm sm:grid-cols-3"
            >
                <div>
                    <dt class="text-ink-muted">Receipts</dt>
                    <dd class="text-lg font-semibold">{{ t.receipts }}</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Gross received</dt>
                    <dd class="font-mono text-lg font-semibold">
                        {{ formatMoney(t.gross, t.currency) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Still retained</dt>
                    <dd class="font-mono text-lg font-semibold">
                        {{ formatMoney(t.retained, t.currency) }}
                    </dd>
                    <dd class="text-xs text-ink-muted">
                        Gross less any of these receipts reversed or refunded since.
                    </dd>
                </div>
            </dl>
            <p class="mt-2 text-xs text-ink-muted">
                Totals cover every matching receipt{{ narrowed ? ' for these filters' : '' }}, not
                only this page.
            </p>
        </section>

        <EmptyState
            v-if="payments.data.length === 0"
            class="mt-6"
            :title="narrowed ? 'No payments match these filters' : 'No payments recorded'"
            :description="
                narrowed
                    ? 'Try another receipt number, Student or method.'
                    : `No payments were recorded ${isToday ? 'today' : 'on ' + period}.`
            "
        />

        <template v-else>
            <table class="mt-6 hidden w-full text-left text-sm md:table">
                <thead>
                    <tr class="border-b border-line text-xs text-ink-muted">
                        <th scope="col" class="py-2 pr-3 font-medium">Receipt</th>
                        <th scope="col" class="py-2 pr-3 font-medium">Student</th>
                        <th scope="col" class="py-2 pr-3 font-medium">Recorded</th>
                        <th scope="col" class="py-2 pr-3 font-medium">Method</th>
                        <th scope="col" class="py-2 pr-3 text-right font-medium">Amount</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="payment in payments.data" :key="payment.id">
                        <td class="py-3 pr-3">
                            <a
                                class="font-mono underline"
                                :href="`/app/finance/payments/${payment.id}`"
                                >{{ payment.receiptNumber ?? 'View payment' }}</a
                            >
                        </td>
                        <td class="py-3 pr-3">
                            <span v-for="name in payment.students" :key="name" class="block">{{
                                name
                            }}</span>
                        </td>
                        <td class="py-3 pr-3 text-ink-muted">
                            {{ formatDateTime(payment.recordedAt) }}
                            <span
                                v-if="payment.receivedOn !== payment.recordedAt.slice(0, 10)"
                                class="block text-xs"
                                >Received {{ formatCalendarDate(payment.receivedOn) }}</span
                            >
                            <span v-if="payment.recordedBy" class="block text-xs"
                                >by {{ payment.recordedBy }}</span
                            >
                        </td>
                        <td class="py-3 pr-3">{{ payment.methodLabel }}</td>
                        <td class="py-3 pr-3 text-right font-mono">
                            {{ formatMoney(payment.amount, payment.currency) }}
                        </td>
                        <td class="py-3">
                            <span class="tag" :class="STATUS[payment.status].className">{{
                                STATUS[payment.status].label
                            }}</span>
                            <span
                                v-if="payment.status === 'partly_refunded'"
                                class="block text-xs text-ink-muted"
                                >{{
                                    formatMoney(payment.refunded, payment.currency)
                                }}
                                refunded</span
                            >
                            <span v-if="payment.reallocated" class="block text-xs text-ink-muted"
                                >Reallocated</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul class="mt-6 space-y-3 md:hidden">
                <li
                    v-for="payment in payments.data"
                    :key="payment.id"
                    class="rounded-xs border border-line p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <a
                            class="font-mono font-medium underline"
                            :href="`/app/finance/payments/${payment.id}`"
                            >{{ payment.receiptNumber ?? 'View payment' }}</a
                        >
                        <span class="tag shrink-0" :class="STATUS[payment.status].className">{{
                            STATUS[payment.status].label
                        }}</span>
                    </div>
                    <p v-for="name in payment.students" :key="name" class="mt-1 text-sm">
                        {{ name }}
                    </p>
                    <p class="mt-1 text-sm text-ink-muted">
                        {{ formatDateTime(payment.recordedAt) }} · {{ payment.methodLabel }}
                    </p>
                    <p class="mt-1 font-mono text-sm">
                        {{ formatMoney(payment.amount, payment.currency) }}
                    </p>
                </li>
            </ul>

            <Pagination :links="payments.links" />
        </template>
    </main>
</template>
