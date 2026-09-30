<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatMoney } from '../../../../money';

interface StudentOption {
    id: string;
    name: string;
    studentNumber: string | null;
}

interface Props {
    idempotencyKey: string;
    charge: { id: string; description: string; amount: string; currency: string } | null;
    student: StudentOption | null;
    academicYears: Array<{ id: string; name: string; startsOn: string; endsOn: string }>;
    feeHeads: Array<{ id: string; label: string }>;
    categories: string[];
}

const props = defineProps<Props>();
const page = usePage();
const actionError = computed(() => (page.props.errors as Record<string, string>).action);
const targeted = computed(() => props.charge !== null);

// The server-issued key makes a double submit replay the same request.
const form = useForm({
    idempotency_key: props.idempotencyKey,
    scope: targeted.value ? 'targeted' : 'standing',
    category: 'concession',
    kind: 'fixed',
    fixed_amount: '',
    percentage: '',
    charge_id: props.charge?.id ?? '',
    student_id: props.student?.id ?? '',
    academic_year_id: props.academicYears[0]?.id ?? '',
    fee_head_id: '',
    valid_from: props.academicYears[0]?.startsOn ?? '',
    valid_to: props.academicYears[0]?.endsOn ?? '',
});

const selectedStudent = ref<StudentOption | null>(props.student);
const query = ref('');
const results = ref<StudentOption[]>([]);

async function search(): Promise<void> {
    if (query.value.trim().length < 2) return;
    const response = await fetch(
        `/app/finance/concessions/students/search?q=${encodeURIComponent(query.value.trim())}`,
        { headers: { Accept: 'application/json' } },
    );
    results.value = response.ok ? ((await response.json()).data as StudentOption[]) : [];
}

function choose(student: StudentOption): void {
    selectedStudent.value = student;
    form.student_id = student.id;
    results.value = [];
}

function yearChanged(): void {
    const year = props.academicYears.find((y) => y.id === form.academic_year_id);
    if (year) {
        form.valid_from = year.startsOn;
        form.valid_to = year.endsOn;
    }
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        fixed_amount: data.kind === 'fixed' ? data.fixed_amount : null,
        percentage: data.kind === 'percentage' ? data.percentage : null,
        charge_id: targeted.value ? data.charge_id : null,
        student_id: targeted.value ? null : data.student_id,
        academic_year_id: targeted.value ? null : data.academic_year_id,
        fee_head_id: targeted.value || data.fee_head_id === '' ? null : data.fee_head_id,
        valid_from: targeted.value ? null : data.valid_from,
        valid_to: targeted.value ? null : data.valid_to,
    })).post('/app/finance/concessions');
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance/concessions">← Concessions</a>
        <h1 class="mt-2 text-xl font-semibold">Request a concession</h1>
        <p class="mt-1 text-sm text-slate-500">
            A request has no effect until another person approves it. There is no notes field: do
            not record a family's circumstances here.
        </p>

        <p v-if="actionError" role="alert" class="mt-4 rounded bg-red-50 p-3 text-sm text-red-700">
            {{ actionError }}
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div v-if="charge" class="rounded border border-slate-200 p-3 text-sm">
                <span class="block text-xs text-slate-500">Charge</span>
                {{ charge.description }} ·
                <span class="font-mono">{{ formatMoney(charge.amount, charge.currency) }}</span>
                <span v-if="student" class="block text-slate-500">{{ student.name }}</span>
            </div>

            <template v-else>
                <p class="text-sm text-slate-500">
                    A standing concession applies automatically when assessment bills this Student
                    in the window. To reduce one existing charge, use "Request concession" on that
                    charge's page.
                </p>
                <div class="text-sm">
                    <span class="block text-xs text-slate-500">Student</span>
                    <p v-if="selectedStudent" class="mt-1">
                        {{ selectedStudent.name }}
                        <span v-if="selectedStudent.studentNumber" class="text-slate-500"
                            >({{ selectedStudent.studentNumber }})</span
                        >
                    </p>
                    <div class="mt-1 flex gap-2">
                        <input
                            v-model="query"
                            type="search"
                            aria-label="Search Students by name or number"
                            placeholder="Search by name or number"
                            class="w-full rounded border border-slate-300 px-2 py-1"
                            @keydown.enter.prevent="search"
                        />
                        <button
                            type="button"
                            class="rounded border border-slate-300 px-3"
                            @click="search"
                        >
                            Search
                        </button>
                    </div>
                    <ul
                        v-if="results.length"
                        class="mt-1 divide-y divide-slate-100 rounded border border-slate-200"
                    >
                        <li v-for="r in results" :key="r.id">
                            <button
                                type="button"
                                class="w-full px-2 py-1 text-left hover:bg-slate-50"
                                @click="choose(r)"
                            >
                                {{ r.name }}
                                <span class="text-slate-500">{{ r.studentNumber }}</span>
                            </button>
                        </li>
                    </ul>
                    <span v-if="form.errors.student_id" class="text-xs text-red-600">{{
                        form.errors.student_id
                    }}</span>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="text-sm">
                        <span class="block text-xs text-slate-500">Academic year</span>
                        <select
                            v-model="form.academic_year_id"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                            @change="yearChanged"
                        >
                            <option v-for="y in academicYears" :key="y.id" :value="y.id">
                                {{ y.name }}
                            </option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="block text-xs text-slate-500">Fee head</span>
                        <select
                            v-model="form.fee_head_id"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        >
                            <option value="">Every fee head</option>
                            <option v-for="h in feeHeads" :key="h.id" :value="h.id">
                                {{ h.label }}
                            </option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="block text-xs text-slate-500">Valid from</span>
                        <input
                            v-model="form.valid_from"
                            type="date"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        />
                    </label>
                    <label class="text-sm">
                        <span class="block text-xs text-slate-500">Valid to</span>
                        <input
                            v-model="form.valid_to"
                            type="date"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                        />
                        <span v-if="form.errors.valid_to" class="text-xs text-red-600">{{
                            form.errors.valid_to
                        }}</span>
                    </label>
                </div>
            </template>

            <div class="grid gap-3 md:grid-cols-3">
                <label class="text-sm">
                    <span class="block text-xs text-slate-500">Category</span>
                    <select
                        v-model="form.category"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1 capitalize"
                    >
                        <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                    </select>
                </label>
                <label v-if="!targeted" class="text-sm">
                    <span class="block text-xs text-slate-500">Value</span>
                    <select
                        v-model="form.kind"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                    >
                        <option value="fixed">Fixed amount</option>
                        <option value="percentage">Percentage of each charge</option>
                    </select>
                </label>
                <label v-if="form.kind === 'fixed'" class="text-sm">
                    <span class="block text-xs text-slate-500">Amount (INR)</span>
                    <input
                        v-model="form.fixed_amount"
                        inputmode="decimal"
                        placeholder="0.00"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1 font-mono"
                        required
                    />
                    <span v-if="form.errors.fixed_amount" class="text-xs text-red-600">{{
                        form.errors.fixed_amount
                    }}</span>
                </label>
                <label v-else class="text-sm">
                    <span class="block text-xs text-slate-500">Percentage</span>
                    <input
                        v-model="form.percentage"
                        inputmode="decimal"
                        placeholder="10"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1 font-mono"
                        required
                    />
                    <span v-if="form.errors.percentage" class="text-xs text-red-600">{{
                        form.errors.percentage
                    }}</span>
                </label>
            </div>

            <button
                type="submit"
                class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                :disabled="form.processing"
            >
                Submit for approval
            </button>
        </form>
    </main>
</template>
