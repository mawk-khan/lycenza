<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { formatMoney, sumAmounts } from '../../../../money';

interface Installment {
    id: string;
    sequence: number;
    label: string;
    billingPeriodKey: string;
    periodStartsOn: string;
    periodEndsOn: string;
    dueDate: string;
    academicTermId: string | null;
    amount: string;
    currency: string;
}

interface Line {
    id: string;
    feeHeadId: string;
    isOptional: boolean;
    frequency: string;
    amount: string;
    currency: string;
    installments: Installment[];
}

interface Structure {
    id: string;
    code: string;
    name: string;
    status: 'draft' | 'active' | 'retired';
    academicYearName: string | null;
    academicYearStartsOn: string | null;
    academicYearEndsOn: string | null;
    gradeLevelName: string | null;
    campusName: string | null;
    supersedesFeeStructureId: string | null;
    successorId: string | null;
    successorStatus: string | null;
    activatedAt: string | null;
    retiredAt: string | null;
    lines: Line[];
}

interface Selection {
    id: string;
    studentId: string;
    studentName: string | null;
    studentNumber: string | null;
    feeStructureLineId: string;
    selectedAt: string;
}

interface Props {
    structure: Structure;
    feeHeads: Array<{ id: string; code: string; name: string; status: string }>;
    academicTerms: Array<{ id: string; name: string }>;
    selections: Selection[];
    frequencies: string[];
    canManage: boolean;
}

const props = defineProps<Props>();
const page = usePage();
const actionError = computed(() => (page.props.errors as Record<string, string>).action);
const isDraft = computed(() => props.structure.status === 'draft');
const editable = computed(() => props.canManage && isDraft.value);

function headLabel(id: string): string {
    const head = props.feeHeads.find((h) => h.id === id);
    return head ? `${head.code} · ${head.name}` : id;
}

function scheduleTotal(line: Line): string {
    return sumAmounts(line.installments.map((i) => i.amount));
}

function scheduleMatches(line: Line): boolean {
    return line.installments.length > 0 && scheduleTotal(line) === sumAmounts([line.amount]);
}

function post(url: string, data: Record<string, unknown> = {}, confirmText?: string): void {
    if (confirmText && !window.confirm(confirmText)) return;
    router.post(url, data as never, { preserveScroll: true });
}

const base = computed(() => `/app/finance/fee-setup/structures/${props.structure.id}`);

// --- lines -------------------------------------------------------------------
const lineForm = useForm({ fee_head_id: '', amount: '', is_optional: false });
function addLine(): void {
    lineForm.post(`${base.value}/lines`, {
        preserveScroll: true,
        onSuccess: () => lineForm.reset(),
    });
}

const lineAmounts = ref<Record<string, string>>({});
watch(
    () => props.structure.lines,
    (lines) => {
        lineAmounts.value = Object.fromEntries(lines.map((l) => [l.id, l.amount]));
    },
    { immediate: true },
);

// --- schedule editor -------------------------------------------------------
interface ScheduleRow {
    label: string;
    billing_period_key: string;
    period_starts_on: string;
    period_ends_on: string;
    due_date: string;
    academic_term_id: string | null;
    amount: string;
}

const editingLineId = ref<string | null>(null);
const scheduleRows = ref<ScheduleRow[]>([]);
const scheduleErrors = ref<Record<string, string>>({});

function editSchedule(line: Line): void {
    editingLineId.value = line.id;
    scheduleErrors.value = {};
    scheduleRows.value = line.installments.map((i) => ({
        label: i.label,
        billing_period_key: i.billingPeriodKey,
        period_starts_on: i.periodStartsOn,
        period_ends_on: i.periodEndsOn,
        due_date: i.dueDate,
        academic_term_id: i.academicTermId,
        amount: i.amount,
    }));
    if (scheduleRows.value.length === 0) addRow();
}

function addRow(): void {
    scheduleRows.value.push({
        label: '',
        billing_period_key: '',
        period_starts_on: props.structure.academicYearStartsOn ?? '',
        period_ends_on: props.structure.academicYearEndsOn ?? '',
        due_date: props.structure.academicYearStartsOn ?? '',
        academic_term_id: null,
        amount: '',
    });
}

function saveSchedule(line: Line): void {
    router.post(
        `${base.value}/lines/${line.id}/installments`,
        { installments: scheduleRows.value } as never,
        {
            preserveScroll: true,
            onSuccess: () => (editingLineId.value = null),
            onError: (errors) => (scheduleErrors.value = errors),
        },
    );
}

const draftTotal = computed(() =>
    sumAmounts(
        scheduleRows.value.map((r) => (/^\d{1,12}(\.\d{1,2})?$/.test(r.amount) ? r.amount : '0')),
    ),
);

// --- selections ---------------------------------------------------------------
interface StudentCandidate {
    id: string;
    studentNumber: string;
    name: string;
}
const selectingLineId = ref<string | null>(null);
const studentQuery = ref('');
const studentResults = ref<StudentCandidate[]>([]);
let debounce: ReturnType<typeof setTimeout> | undefined;

watch(studentQuery, (value) => {
    clearTimeout(debounce);
    if (value.trim().length < 2) {
        studentResults.value = [];
        return;
    }
    debounce = setTimeout(async () => {
        const response = await fetch(
            `/app/finance/fee-setup/students/search?q=${encodeURIComponent(value)}`,
            { headers: { Accept: 'application/json' } },
        );
        studentResults.value = (await response.json()).data as StudentCandidate[];
    }, 300);
});

function selectStudent(lineId: string, student: StudentCandidate): void {
    router.post(
        '/app/finance/fee-setup/selections',
        { student_id: student.id, fee_structure_line_id: lineId },
        {
            preserveScroll: true,
            onSuccess: () => {
                studentQuery.value = '';
                studentResults.value = [];
            },
        },
    );
}

function selectionsFor(lineId: string): Selection[] {
    return props.selections.filter((s) => s.feeStructureLineId === lineId);
}

const successorForm = useForm({ code: '', name: '' });
function createSuccessor(): void {
    successorForm.post(`${base.value}/successor`);
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance/fee-setup">← Fee setup</a>
        <h1 class="mt-2 text-xl font-semibold">
            {{ structure.name }}
            <span class="ml-1 font-mono text-sm text-slate-500">{{ structure.code }}</span>
        </h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ structure.academicYearName }} · {{ structure.gradeLevelName }} ·
            {{ structure.campusName ?? 'All campuses (School default)' }} ·
            <span class="font-medium capitalize">{{ structure.status }}</span>
        </p>
        <p v-if="structure.supersedesFeeStructureId" class="mt-1 text-sm text-slate-500">
            Amends
            <a
                class="underline"
                :href="`/app/finance/fee-setup/structures/${structure.supersedesFeeStructureId}`"
                >the previous structure</a
            >; activating this one retires it.
        </p>
        <p v-if="structure.successorId" class="mt-1 text-sm text-slate-500">
            An amendment exists ({{ structure.successorStatus }}):
            <a
                class="underline"
                :href="`/app/finance/fee-setup/structures/${structure.successorId}`"
                >open it</a
            >.
        </p>

        <p v-if="actionError" role="alert" class="mt-4 rounded bg-red-50 p-3 text-sm text-red-700">
            {{ actionError }}
        </p>

        <!-- lifecycle -->
        <div v-if="canManage" class="mt-4 flex flex-wrap items-end gap-3">
            <button
                v-if="isDraft"
                type="button"
                class="rounded bg-emerald-700 px-3 py-1.5 text-sm text-white"
                @click="
                    post(
                        `${base}/activate`,
                        {},
                        'Activate this structure? Its lines and schedules can no longer be edited afterwards.',
                    )
                "
            >
                Activate
            </button>
            <button
                v-if="structure.status === 'active'"
                type="button"
                class="rounded border border-slate-300 px-3 py-1.5 text-sm"
                @click="
                    post(
                        `${base}/retire`,
                        {},
                        'Retire this structure? It stays readable but can no longer be used for new billing.',
                    )
                "
            >
                Retire
            </button>
            <form
                v-if="structure.status === 'active'"
                class="flex items-end gap-2"
                @submit.prevent="createSuccessor"
            >
                <label class="text-sm">
                    <span class="block text-xs text-slate-500">Amendment code</span>
                    <input
                        v-model="successorForm.code"
                        class="w-32 rounded border border-slate-300 px-2 py-1 font-mono"
                        maxlength="32"
                        required
                    />
                </label>
                <button type="submit" class="rounded border border-slate-300 px-3 py-1.5 text-sm">
                    Amend (copy to a new draft)
                </button>
                <span v-if="successorForm.errors.code" class="text-xs text-red-600">{{
                    successorForm.errors.code
                }}</span>
            </form>
        </div>

        <!-- lines -->
        <section class="mt-8">
            <h2 class="text-lg font-semibold">Fee lines</h2>

            <p v-if="structure.lines.length === 0" class="mt-2 text-sm text-slate-500">
                No fee lines yet.
            </p>

            <article
                v-for="line in structure.lines"
                :key="line.id"
                class="mt-4 rounded border border-slate-200 p-4"
            >
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="font-medium">
                        {{ headLabel(line.feeHeadId) }}
                        <span
                            v-if="line.isOptional"
                            class="ml-1 rounded bg-sky-50 px-1.5 py-0.5 text-xs text-sky-700"
                            >optional</span
                        >
                    </h3>
                    <p class="text-sm">
                        {{ formatMoney(line.amount, line.currency) }} a year ·
                        <span
                            :class="scheduleMatches(line) ? 'text-emerald-700' : 'text-amber-700'"
                        >
                            schedule {{ formatMoney(scheduleTotal(line), line.currency) }}
                        </span>
                    </p>
                </div>

                <div v-if="editable" class="mt-3 flex flex-wrap items-end gap-3 text-sm">
                    <label>
                        <span class="block text-xs text-slate-500">Yearly amount</span>
                        <input
                            v-model="lineAmounts[line.id]"
                            class="w-32 rounded border border-slate-300 px-2 py-1"
                            inputmode="decimal"
                        />
                    </label>
                    <button
                        type="button"
                        class="underline"
                        @click="post(`${base}/lines/${line.id}`, { amount: lineAmounts[line.id] })"
                    >
                        Save amount
                    </button>
                    <button
                        type="button"
                        class="underline"
                        @click="post(`${base}/lines/${line.id}`, { is_optional: !line.isOptional })"
                    >
                        {{ line.isOptional ? 'Make required' : 'Make optional' }}
                    </button>
                    <span class="text-xs text-slate-500">Generate schedule:</span>
                    <button
                        v-for="f in frequencies"
                        :key="f"
                        type="button"
                        class="underline"
                        @click="
                            post(
                                `${base}/lines/${line.id}/generate`,
                                { frequency: f },
                                'Replace this line\'s schedule with a generated one? You can still edit it afterwards.',
                            )
                        "
                    >
                        {{ f.replace('_', ' ') }}
                    </button>
                    <button type="button" class="underline" @click="editSchedule(line)">
                        Edit schedule
                    </button>
                    <button
                        type="button"
                        class="text-red-700 underline"
                        @click="
                            post(`${base}/lines/${line.id}/remove`, {}, 'Remove this draft line?')
                        "
                    >
                        Remove line
                    </button>
                </div>

                <!-- schedule editor -->
                <div v-if="editingLineId === line.id" class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="text-slate-500">
                                <th class="py-1">Label</th>
                                <th class="py-1">Period key</th>
                                <th class="py-1">Starts</th>
                                <th class="py-1">Ends</th>
                                <th class="py-1">Due</th>
                                <th class="py-1">Term</th>
                                <th class="py-1">Amount</th>
                                <th class="py-1"><span class="sr-only">Remove</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in scheduleRows" :key="i">
                                <td>
                                    <input
                                        v-model="row.label"
                                        class="w-28 rounded border px-1 py-0.5"
                                    />
                                </td>
                                <td>
                                    <input
                                        v-model="row.billing_period_key"
                                        class="w-24 rounded border px-1 py-0.5 font-mono"
                                    />
                                </td>
                                <td>
                                    <input
                                        v-model="row.period_starts_on"
                                        type="date"
                                        class="rounded border px-1 py-0.5"
                                    />
                                </td>
                                <td>
                                    <input
                                        v-model="row.period_ends_on"
                                        type="date"
                                        class="rounded border px-1 py-0.5"
                                    />
                                </td>
                                <td>
                                    <input
                                        v-model="row.due_date"
                                        type="date"
                                        class="rounded border px-1 py-0.5"
                                    />
                                </td>
                                <td>
                                    <select
                                        v-model="row.academic_term_id"
                                        class="rounded border px-1 py-0.5"
                                    >
                                        <option :value="null">—</option>
                                        <option
                                            v-for="t in academicTerms"
                                            :key="t.id"
                                            :value="t.id"
                                        >
                                            {{ t.name }}
                                        </option>
                                    </select>
                                </td>
                                <td>
                                    <input
                                        v-model="row.amount"
                                        class="w-24 rounded border px-1 py-0.5"
                                        inputmode="decimal"
                                    />
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="underline"
                                        @click="scheduleRows.splice(i, 1)"
                                    >
                                        ✕
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-2 text-xs">
                        Total {{ formatMoney(draftTotal, line.currency) }} of
                        {{ formatMoney(line.amount, line.currency) }}.
                    </p>
                    <p
                        v-for="(message, field) in scheduleErrors"
                        :key="field"
                        class="text-xs text-red-600"
                    >
                        {{ message }}
                    </p>
                    <div class="mt-2 space-x-3 text-sm">
                        <button type="button" class="underline" @click="addRow">Add row</button>
                        <button type="button" class="underline" @click="saveSchedule(line)">
                            Save schedule
                        </button>
                        <button type="button" class="underline" @click="editingLineId = null">
                            Cancel
                        </button>
                    </div>
                </div>

                <table v-else-if="line.installments.length" class="mt-3 w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-500">
                            <th class="py-1">#</th>
                            <th class="py-1">Label</th>
                            <th class="py-1">Period</th>
                            <th class="py-1">Due</th>
                            <th class="py-1 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="i in line.installments" :key="i.id">
                            <td class="py-1">{{ i.sequence }}</td>
                            <td class="py-1">
                                {{ i.label }}
                                <span class="font-mono text-slate-500">{{
                                    i.billingPeriodKey
                                }}</span>
                            </td>
                            <td class="py-1">{{ i.periodStartsOn }} – {{ i.periodEndsOn }}</td>
                            <td class="py-1">{{ i.dueDate }}</td>
                            <td class="py-1 text-right">{{ formatMoney(i.amount, i.currency) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="mt-3 text-xs text-amber-700">No schedule yet.</p>

                <!-- optional selections -->
                <div v-if="line.isOptional" class="mt-4 border-t border-slate-100 pt-3">
                    <h4 class="text-sm font-medium">Students who chose this optional fee</h4>
                    <ul class="mt-2 space-y-1 text-sm">
                        <li
                            v-for="s in selectionsFor(line.id)"
                            :key="s.id"
                            class="flex items-center justify-between"
                        >
                            <span>{{ s.studentName }} ({{ s.studentNumber }})</span>
                            <button
                                v-if="canManage"
                                type="button"
                                class="text-xs underline"
                                @click="
                                    post(
                                        `/app/finance/fee-setup/selections/${s.id}/withdraw`,
                                        {},
                                        'Withdraw this selection? Future billing stops; nothing already billed changes.',
                                    )
                                "
                            >
                                Withdraw
                            </button>
                        </li>
                        <li v-if="selectionsFor(line.id).length === 0" class="text-slate-500">
                            None yet.
                        </li>
                    </ul>
                    <div v-if="canManage" class="mt-2">
                        <button
                            v-if="selectingLineId !== line.id"
                            type="button"
                            class="text-xs underline"
                            @click="selectingLineId = line.id"
                        >
                            Add a Student
                        </button>
                        <div v-else>
                            <input
                                v-model="studentQuery"
                                class="w-64 rounded border border-slate-300 px-2 py-1 text-sm"
                                placeholder="Search by name or number"
                            />
                            <ul class="mt-1 text-sm">
                                <li v-for="c in studentResults" :key="c.id">
                                    <button
                                        type="button"
                                        class="underline"
                                        @click="selectStudent(line.id, c)"
                                    >
                                        {{ c.name }} ({{ c.studentNumber }})
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </article>

            <form
                v-if="editable"
                class="mt-6 flex flex-wrap items-end gap-3 rounded border border-slate-200 p-4 text-sm"
                @submit.prevent="addLine"
            >
                <label>
                    <span class="block text-xs text-slate-500">Fee head</span>
                    <select
                        v-model="lineForm.fee_head_id"
                        class="rounded border border-slate-300 px-2 py-1"
                        required
                    >
                        <option value="" disabled>Choose…</option>
                        <option
                            v-for="h in feeHeads.filter((h) => h.status === 'active')"
                            :key="h.id"
                            :value="h.id"
                        >
                            {{ h.code }} · {{ h.name }}
                        </option>
                    </select>
                </label>
                <label>
                    <span class="block text-xs text-slate-500">Yearly amount (INR)</span>
                    <input
                        v-model="lineForm.amount"
                        class="w-32 rounded border border-slate-300 px-2 py-1"
                        inputmode="decimal"
                        required
                    />
                </label>
                <label class="flex items-center gap-1">
                    <input v-model="lineForm.is_optional" type="checkbox" />
                    Optional
                </label>
                <button type="submit" class="rounded bg-slate-900 px-3 py-1.5 text-white">
                    Add line
                </button>
                <p
                    v-for="(message, field) in lineForm.errors"
                    :key="field"
                    class="w-full text-xs text-red-600"
                >
                    {{ message }}
                </p>
            </form>
        </section>
    </main>
</template>
