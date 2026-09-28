<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { formatMoney, subtractAmounts, sumAmounts } from '../../../../money';

interface StudentCandidate {
    id: string;
    studentNumber: string;
    name: string;
}

interface OpenCharge {
    id: string;
    description: string;
    amount: string;
    allocated: string;
    outstanding: string;
    currency: string;
    dueDate: string | null;
}

interface Props {
    idempotencyKey: string;
    today: string;
    methods: Array<{ value: string; label: string }>;
    settlementAccounts: Array<{ id: string; code: string; name: string }>;
    student: { id: string; name: string; studentNumber: string } | null;
    charges: OpenCharge[];
    preselectedChargeId: string | null;
}

const props = defineProps<Props>();

const form = useForm({
    idempotency_key: props.idempotencyKey,
    method: '',
    amount: '',
    occurred_on: props.today,
    reference: '',
    settlement_ledger_account_id:
        props.settlementAccounts.length === 1 ? props.settlementAccounts[0].id : '',
    allocations: [] as Array<{ charge_id: string; amount: string }>,
});

// Amount applied per charge, as typed. A preselected charge (arriving
// from a Charge page) starts at its full outstanding balance.
const applied = reactive<Record<string, string>>({});
for (const charge of props.charges) {
    applied[charge.id] =
        charge.id === props.preselectedChargeId && charge.outstanding !== '0.00'
            ? charge.outstanding
            : '';
}
if (props.preselectedChargeId && applied[props.preselectedChargeId]) {
    form.amount = applied[props.preselectedChargeId];
}

const reviewing = ref(false);

const allocations = computed(() =>
    props.charges
        .filter((charge) => (applied[charge.id] ?? '').trim() !== '')
        .map((charge) => ({ charge, amount: applied[charge.id].trim() })),
);
const appliedTotal = computed(() => sumAmounts(allocations.value.map((a) => a.amount)));
const leftToApply = computed(() => subtractAmounts(form.amount, appliedTotal.value));
const methodLabel = computed(() => props.methods.find((m) => m.value === form.method)?.label ?? '');
const account = computed(() =>
    props.settlementAccounts.find((a) => a.id === form.settlement_ledger_account_id),
);
const canReview = computed(
    () =>
        props.student !== null &&
        allocations.value.length > 0 &&
        form.method !== '' &&
        form.occurred_on !== '' &&
        form.settlement_ledger_account_id !== '' &&
        leftToApply.value === '0.00',
);

function applyFullOutstanding(charge: OpenCharge): void {
    applied[charge.id] = charge.outstanding;
}

// --- Student picker -------------------------------------------------------
const studentQuery = ref('');
const studentResults = ref<StudentCandidate[]>([]);
let debounceTimer: ReturnType<typeof setTimeout> | undefined;

watch(studentQuery, (value) => {
    clearTimeout(debounceTimer);
    if (value.trim().length < 2) {
        studentResults.value = [];
        return;
    }
    debounceTimer = setTimeout(async () => {
        const response = await fetch(
            `/app/finance/payments/record/students/search?q=${encodeURIComponent(value)}`,
            { headers: { Accept: 'application/json' } },
        );
        const body = await response.json();
        studentResults.value = body.data as StudentCandidate[];
    }, 300);
});

function selectStudent(candidate: StudentCandidate): void {
    router.get('/app/finance/payments/record', { student_id: candidate.id });
}

function changeStudent(): void {
    router.get('/app/finance/payments/record');
}

// --- Review and record ----------------------------------------------------
function review(): void {
    if (!canReview.value) return;
    form.clearErrors();
    reviewing.value = true;
}

function record(): void {
    form.allocations = allocations.value.map((a) => ({ charge_id: a.charge.id, amount: a.amount }));
    form.post('/app/finance/payments/record', {
        onError: () => {
            reviewing.value = false;
        },
    });
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance/payments">← Payments</a>
        <h1 class="mt-2 text-xl font-semibold">Record an offline payment</h1>
        <p class="mt-1 text-sm text-slate-500">
            Record money this School has already received in cash, by bank transfer or by cheque.
            Lycenza does not collect or move money -- this only records a payment that has already
            happened.
        </p>

        <p
            v-if="form.errors.idempotency_key"
            class="mt-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800"
        >
            {{ form.errors.idempotency_key }}
        </p>

        <p
            v-if="settlementAccounts.length === 0"
            class="mt-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800"
        >
            This School has no active asset ledger account (such as Cash or Bank) to record a
            payment into. Someone with ledger access needs to set one up first.
        </p>

        <!-- Step 1: Student -->
        <section class="mt-6">
            <h2 class="text-sm font-medium text-slate-900">Student</h2>
            <div
                v-if="student"
                class="mt-2 flex items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm"
            >
                <span
                    >{{ student.name }}
                    <span class="text-slate-500">({{ student.studentNumber }})</span></span
                >
                <button
                    v-if="!reviewing"
                    type="button"
                    class="text-slate-500 underline hover:text-slate-800"
                    @click="changeStudent"
                >
                    Change
                </button>
            </div>
            <div v-else class="relative mt-2">
                <label class="sr-only" for="student-search">Search students</label>
                <input
                    id="student-search"
                    v-model="studentQuery"
                    type="text"
                    placeholder="Search by name or student number…"
                    class="w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="studentResults.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white shadow-sm"
                >
                    <li v-for="candidate in studentResults" :key="candidate.id">
                        <button
                            type="button"
                            class="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50"
                            @click="selectStudent(candidate)"
                        >
                            {{ candidate.name }}
                            <span class="text-xs text-slate-400"
                                >({{ candidate.studentNumber }})</span
                            >
                        </button>
                    </li>
                </ul>
            </div>
        </section>

        <template v-if="student && !reviewing">
            <!-- Step 2: Charges -->
            <section class="mt-8">
                <h2 class="text-sm font-medium text-slate-900">Apply to charges</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Enter how much of this payment goes to each charge. One payment can cover
                    several charges.
                </p>
                <p v-if="charges.length === 0" class="mt-3 text-sm text-slate-500">
                    This Student has no open charges.
                </p>
                <table v-else class="mt-3 w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th scope="col" class="py-2 font-medium">Charge</th>
                            <th scope="col" class="py-2 text-right font-medium">Outstanding</th>
                            <th scope="col" class="py-2 pl-4 font-medium">Apply (INR)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="charge in charges" :key="charge.id">
                            <td class="py-3">
                                {{ charge.description }}
                                <span v-if="charge.dueDate" class="block text-xs text-slate-500"
                                    >Due {{ charge.dueDate }}</span
                                >
                            </td>
                            <td class="py-3 text-right font-mono">
                                {{ formatMoney(charge.outstanding, charge.currency) }}
                            </td>
                            <td class="py-3 pl-4">
                                <span
                                    v-if="charge.outstanding === '0.00'"
                                    class="text-xs text-slate-500"
                                    >Paid in full</span
                                >
                                <div v-else class="flex items-center gap-2">
                                    <input
                                        v-model="applied[charge.id]"
                                        :aria-label="`Amount to apply to ${charge.description}`"
                                        inputmode="decimal"
                                        placeholder="0.00"
                                        class="w-28 rounded border border-slate-300 px-2 py-1 text-right font-mono text-sm"
                                    />
                                    <button
                                        type="button"
                                        class="text-xs text-slate-500 underline hover:text-slate-800"
                                        @click="applyFullOutstanding(charge)"
                                    >
                                        Full
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="form.errors.allocations" class="mt-2 text-sm text-red-600">
                    {{ form.errors.allocations }}
                </p>
            </section>

            <!-- Step 3: Payment details -->
            <section class="mt-8 space-y-4">
                <h2 class="text-sm font-medium text-slate-900">Payment details</h2>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-slate-600" for="amount"
                            >Amount received (INR)</label
                        >
                        <input
                            id="amount"
                            v-model="form.amount"
                            required
                            inputmode="decimal"
                            placeholder="0.00"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-mono text-sm"
                            :aria-invalid="!!form.errors.amount"
                        />
                        <p class="mt-1 text-xs text-slate-500">
                            Applied to charges: {{ formatMoney(appliedTotal, 'INR') }}
                            <span v-if="leftToApply !== null && leftToApply !== '0.00'">
                                · left to apply: {{ formatMoney(leftToApply, 'INR') }}</span
                            >
                        </p>
                        <p v-if="form.errors.amount" class="mt-1 text-sm text-red-600">
                            {{ form.errors.amount }}
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600" for="occurred_on"
                            >Received on</label
                        >
                        <input
                            id="occurred_on"
                            v-model="form.occurred_on"
                            type="date"
                            required
                            :max="today"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                            :aria-invalid="!!form.errors.occurred_on"
                        />
                        <p v-if="form.errors.occurred_on" class="mt-1 text-sm text-red-600">
                            {{ form.errors.occurred_on }}
                        </p>
                    </div>
                </div>

                <fieldset>
                    <legend class="block text-sm text-slate-600">Method</legend>
                    <div class="mt-1 flex flex-wrap gap-4">
                        <label
                            v-for="method in methods"
                            :key="method.value"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input v-model="form.method" type="radio" :value="method.value" />
                            {{ method.label }}
                        </label>
                    </div>
                    <p v-if="form.errors.method" class="mt-1 text-sm text-red-600">
                        {{ form.errors.method }}
                    </p>
                </fieldset>

                <div>
                    <label class="block text-sm text-slate-600" for="reference"
                        >Reference (optional)</label
                    >
                    <input
                        id="reference"
                        v-model="form.reference"
                        maxlength="64"
                        placeholder="Cheque number or bank transfer reference (UTR)"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        :aria-invalid="!!form.errors.reference"
                    />
                    <p class="mt-1 text-xs text-slate-500">
                        Never enter card numbers, bank account numbers or passwords.
                    </p>
                    <p v-if="form.errors.reference" class="mt-1 text-sm text-red-600">
                        {{ form.errors.reference }}
                    </p>
                </div>

                <div>
                    <label class="block text-sm text-slate-600" for="settlement_ledger_account_id"
                        >Received into</label
                    >
                    <select
                        id="settlement_ledger_account_id"
                        v-model="form.settlement_ledger_account_id"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        :aria-invalid="!!form.errors.settlement_ledger_account_id"
                    >
                        <option value="" disabled>Select the account the money went into</option>
                        <option v-for="a in settlementAccounts" :key="a.id" :value="a.id">
                            {{ a.code }} — {{ a.name }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.settlement_ledger_account_id"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ form.errors.settlement_ledger_account_id }}
                    </p>
                </div>
            </section>

            <button
                type="button"
                :disabled="!canReview"
                class="mt-8 rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                @click="review"
            >
                Review payment
            </button>
        </template>

        <!-- Step 4: Confirm -->
        <section
            v-if="student && reviewing"
            class="mt-8 rounded border border-slate-300 p-5"
            aria-labelledby="review-heading"
        >
            <h2 id="review-heading" class="text-sm font-medium text-slate-900">
                Check the payment before recording it
            </h2>
            <p class="mt-1 text-sm text-slate-500">
                Once recorded, this payment cannot be edited or deleted.
            </p>

            <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                <div>
                    <dt class="text-slate-500">Amount received</dt>
                    <dd class="font-mono">{{ formatMoney(form.amount, 'INR') }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Method</dt>
                    <dd>{{ methodLabel }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Received on</dt>
                    <dd>{{ form.occurred_on }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Reference</dt>
                    <dd>{{ form.reference || '—' }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-slate-500">Received into</dt>
                    <dd>{{ account ? `${account.code} — ${account.name}` : '' }}</dd>
                </div>
            </dl>

            <h3 class="mt-5 text-xs font-medium text-slate-500">Applied to</h3>
            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                <li
                    v-for="allocation in allocations"
                    :key="allocation.charge.id"
                    class="flex justify-between py-2"
                >
                    <span>{{ allocation.charge.description }}</span>
                    <span class="font-mono">{{ formatMoney(allocation.amount, 'INR') }}</span>
                </li>
            </ul>

            <div class="mt-6 flex gap-3">
                <button
                    type="button"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                    @click="record"
                >
                    Record payment
                </button>
                <button
                    type="button"
                    :disabled="form.processing"
                    class="rounded border border-slate-300 px-4 py-2 text-sm disabled:opacity-50"
                    @click="reviewing = false"
                >
                    Back to edit
                </button>
            </div>
        </section>
    </main>
</template>
