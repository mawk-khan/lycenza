<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { formatMoney } from '../../../../money';

interface StudentCandidate {
    id: string;
    studentNumber: string;
    firstName: string;
    middleName: string | null;
    lastName: string | null;
}

interface Account {
    id: string;
    code: string;
    name: string;
}

interface SimilarCharge {
    chargeId: string;
    description: string;
    amount: string;
    currency: string;
    dueDate: string | null;
    assessedOn: string;
}

interface Props {
    receivableAccounts: Account[];
    revenueAccounts: Account[];
    defaultReceivableAccountId: string | null;
    defaultRevenueAccountId: string | null;
    academicYears: Array<{ id: string; name: string; code: string; status: string; label: string }>;
    defaultAcademicYearId: string | null;
    similarCharges: SimilarCharge[];
}

const props = defineProps<Props>();

// UAT-C4 (UAT-FIN-11): the current year and any single valid account are
// preselected; everything stays editable.
const form = useForm({
    student_id: '',
    academic_year_id: props.defaultAcademicYearId ?? '',
    description: '',
    amount: '',
    currency: 'INR',
    receivable_ledger_account_id: props.defaultReceivableAccountId ?? '',
    revenue_ledger_account_id: props.defaultRevenueAccountId ?? '',
    due_date: '',
    confirm_similar: false,
});

const selectedStudentLabel = ref('');
const studentQuery = ref('');
const studentResults = ref<StudentCandidate[]>([]);
let debounceTimer: ReturnType<typeof setTimeout> | undefined;

function candidateName(s: StudentCandidate): string {
    return [s.firstName, s.middleName, s.lastName].filter(Boolean).join(' ');
}

watch(studentQuery, (value) => {
    clearTimeout(debounceTimer);
    if (value.trim().length < 2) {
        studentResults.value = [];
        return;
    }
    debounceTimer = setTimeout(async () => {
        const response = await fetch(
            `/app/finance/charges/students/search?q=${encodeURIComponent(value)}`,
            { headers: { Accept: 'application/json' } },
        );
        const body = await response.json();
        studentResults.value = body.data as StudentCandidate[];
    }, 300);
});

function selectStudent(candidate: StudentCandidate): void {
    form.student_id = candidate.id;
    selectedStudentLabel.value = `${candidateName(candidate)} (${candidate.studentNumber})`;
    studentQuery.value = '';
    studentResults.value = [];
}

function clearStudent(): void {
    form.student_id = '';
    selectedStudentLabel.value = '';
}

function submit(): void {
    form.post('/app/finance/charges', {
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance/charges">← Charges</a>
        <h1 class="mt-2 text-xl font-semibold">Assess charge</h1>
        <p class="mt-1 text-sm text-slate-500">
            Records a Student fee charge and posts its recognition entry to the Ledger in one step.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div class="relative">
                <label class="block text-sm text-slate-600" for="student">Student</label>
                <div
                    v-if="selectedStudentLabel"
                    class="mt-1 flex items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <span>{{ selectedStudentLabel }}</span>
                    <button
                        type="button"
                        class="text-slate-400 hover:text-slate-700"
                        @click="clearStudent"
                    >
                        Change
                    </button>
                </div>
                <input
                    v-else
                    id="student"
                    v-model="studentQuery"
                    type="text"
                    placeholder="Search by name or student number…"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    :aria-invalid="!!form.errors.student_id"
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
                            {{ candidateName(candidate) }}
                            <span class="text-xs text-slate-400"
                                >({{ candidate.studentNumber }})</span
                            >
                        </button>
                    </li>
                </ul>
                <p v-if="form.errors.student_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.student_id }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="academic_year_id"
                    >Academic Year</label
                >
                <select
                    id="academic_year_id"
                    v-model="form.academic_year_id"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    :aria-invalid="!!form.errors.academic_year_id"
                >
                    <option value="" disabled>Select an Academic Year</option>
                    <option v-for="year in academicYears" :key="year.id" :value="year.id">
                        {{ year.label }}
                    </option>
                </select>
                <p v-if="form.errors.academic_year_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.academic_year_id }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="description">Description</label>
                <input
                    id="description"
                    v-model="form.description"
                    required
                    maxlength="255"
                    placeholder="e.g. Term 1 Tuition Fee"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    :aria-invalid="!!form.errors.description"
                />
                <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">
                    {{ form.errors.description }}
                </p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-slate-600" for="amount">Amount (INR)</label>
                    <input
                        id="amount"
                        v-model="form.amount"
                        required
                        inputmode="decimal"
                        placeholder="0.00"
                        pattern="\d{1,12}(\.\d{1,2})?"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        :aria-invalid="!!form.errors.amount"
                    />
                    <p v-if="form.errors.amount" class="mt-1 text-sm text-red-600">
                        {{ form.errors.amount }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-600" for="due_date"
                        >Due date (optional)</label
                    >
                    <input
                        id="due_date"
                        v-model="form.due_date"
                        type="date"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                </div>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="receivable_ledger_account_id"
                    >Receivable account</label
                >
                <select
                    id="receivable_ledger_account_id"
                    v-model="form.receivable_ledger_account_id"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    :aria-invalid="!!form.errors.receivable_ledger_account_id"
                >
                    <option value="" disabled>Select an account</option>
                    <option
                        v-for="account in receivableAccounts"
                        :key="account.id"
                        :value="account.id"
                    >
                        {{ account.code }} — {{ account.name }}
                    </option>
                </select>
                <p
                    v-if="form.errors.receivable_ledger_account_id"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.receivable_ledger_account_id }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="revenue_ledger_account_id"
                    >Revenue account</label
                >
                <select
                    id="revenue_ledger_account_id"
                    v-model="form.revenue_ledger_account_id"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="" disabled>Select an account</option>
                    <option
                        v-for="account in revenueAccounts"
                        :key="account.id"
                        :value="account.id"
                    >
                        {{ account.code }} — {{ account.name }}
                    </option>
                </select>
            </div>

            <div
                v-if="form.errors.confirm_similar && similarCharges.length > 0"
                role="alert"
                class="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
            >
                <p class="font-medium">{{ form.errors.confirm_similar }}</p>
                <ul class="mt-2 space-y-1">
                    <li
                        v-for="c in similarCharges"
                        :key="c.chargeId"
                        class="flex flex-wrap justify-between gap-2"
                    >
                        <a class="underline" :href="`/app/finance/charges/${c.chargeId}`">{{
                            c.description
                        }}</a>
                        <span
                            >{{ formatMoney(c.amount, c.currency)
                            }}<template v-if="c.dueDate"> · due {{ c.dueDate }}</template> ·
                            assessed {{ c.assessedOn }}</span
                        >
                    </li>
                </ul>
                <label class="mt-3 flex items-start gap-2">
                    <input v-model="form.confirm_similar" type="checkbox" class="mt-1" />
                    <span>This is a separate charge — assess it anyway.</span>
                </label>
            </div>

            <button
                type="submit"
                :disabled="form.processing || !form.student_id"
                class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
                Assess charge
            </button>
        </form>
    </main>
</template>
