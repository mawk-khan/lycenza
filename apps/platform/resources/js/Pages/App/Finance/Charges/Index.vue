<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import Pagination from '../../../../Components/Pagination.vue';
import { formatMoney } from '../../../../money';

interface ChargeRow {
    id: string;
    studentId: string;
    studentName: string | null;
    academicYearId: string;
    description: string;
    amount: string;
    currency: string;
    dueDate: string | null;
    cancelledAt: string | null;
    createdAt: string;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface StudentCandidate {
    id: string;
    studentNumber: string;
    firstName: string;
    middleName: string | null;
    lastName: string | null;
}

interface Props {
    charges: {
        data: ChargeRow[];
        links: PageLink[];
        total: number;
    };
    filters: {
        student_id: string;
        academic_year_id: string;
        include_cancelled: boolean;
    };
    selectedStudentName: string | null;
    academicYears: Array<{ id: string; name: string; code: string }>;
    canManage: boolean;
}

const props = defineProps<Props>();

const studentId = ref(props.filters.student_id);
const studentLabel = ref(props.filters.student_id ? (props.selectedStudentName ?? '') : '');
const academicYearId = ref(props.filters.academic_year_id);
const includeCancelled = ref(props.filters.include_cancelled);

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
    studentId.value = candidate.id;
    studentLabel.value = candidateName(candidate);
    studentQuery.value = '';
    studentResults.value = [];
    applyFilters();
}

function clearStudent(): void {
    studentId.value = '';
    studentLabel.value = '';
    applyFilters();
}

function applyFilters(): void {
    router.get(
        '/app/finance/charges',
        {
            student_id: studentId.value || undefined,
            academic_year_id: academicYearId.value || undefined,
            include_cancelled: includeCancelled.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const hasFilters = studentId.value || academicYearId.value || includeCancelled.value;
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance">← Finance</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Charges</h1>
                <p class="mt-1 text-sm text-slate-500">Student fee charges for this School.</p>
            </div>
            <a
                v-if="canManage"
                href="/app/finance/charges/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Assess charge
            </a>
        </div>

        <div class="mt-6 flex flex-wrap items-end gap-3">
            <div class="relative">
                <label class="block text-sm text-slate-600" for="filter-student">Student</label>
                <div
                    v-if="studentLabel"
                    class="mt-1 flex items-center gap-2 rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <span>{{ studentLabel }}</span>
                    <button
                        type="button"
                        class="text-slate-400 hover:text-slate-700"
                        @click="clearStudent"
                    >
                        ×
                    </button>
                </div>
                <input
                    v-else
                    id="filter-student"
                    v-model="studentQuery"
                    type="text"
                    placeholder="Search by name…"
                    class="mt-1 w-56 rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="studentResults.length > 0"
                    class="absolute z-10 mt-1 w-56 rounded border border-slate-200 bg-white shadow-sm"
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
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-year">Academic Year</label>
                <select
                    id="filter-year"
                    v-model="academicYearId"
                    class="mt-1 w-48 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All years</option>
                    <option v-for="year in academicYears" :key="year.id" :value="year.id">
                        {{ year.name }}
                    </option>
                </select>
            </div>
            <label class="mb-2 flex items-center gap-2 text-sm text-slate-600">
                <input v-model="includeCancelled" type="checkbox" @change="applyFilters" />
                Include cancelled
            </label>
        </div>

        <EmptyState
            v-if="charges.data.length === 0"
            class="mt-6"
            :title="hasFilters ? 'No charges match your filters' : 'No charges yet'"
            :description="
                hasFilters
                    ? 'Try a different Student, Academic Year, or include cancelled charges.'
                    : canManage
                      ? 'Assess the first charge to begin building this School\'s Fees history.'
                      : 'No Fee charges have been assessed for this School yet.'
            "
        >
            <template v-if="canManage && !hasFilters" #action>
                <a
                    href="/app/finance/charges/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Assess charge
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 hidden w-full text-left text-sm md:table">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Student</th>
                        <th scope="col" class="py-2 font-medium">Description</th>
                        <th scope="col" class="py-2 text-right font-medium">Amount</th>
                        <th scope="col" class="py-2 font-medium">Due</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="charge in charges.data" :key="charge.id">
                        <td class="py-3">
                            <a
                                class="font-medium underline"
                                :href="`/app/finance/charges/${charge.id}`"
                                >{{ charge.studentName ?? charge.studentId }}</a
                            >
                        </td>
                        <td class="py-3 text-slate-600">{{ charge.description }}</td>
                        <td class="py-3 text-right font-mono">
                            {{ formatMoney(charge.amount, charge.currency) }}
                        </td>
                        <td class="py-3 text-slate-600">{{ charge.dueDate ?? '—' }}</td>
                        <td class="py-3">
                            <span
                                v-if="charge.cancelledAt"
                                class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700"
                                >Cancelled</span
                            >
                            <span
                                v-else
                                class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"
                                >Assessed</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul class="mt-6 space-y-3 md:hidden">
                <li
                    v-for="charge in charges.data"
                    :key="charge.id"
                    class="rounded border border-slate-200 p-4"
                >
                    <a class="font-medium underline" :href="`/app/finance/charges/${charge.id}`">{{
                        charge.studentName ?? charge.studentId
                    }}</a>
                    <p class="mt-1 text-sm text-slate-500">{{ charge.description }}</p>
                    <p class="mt-1 font-mono text-sm">
                        {{ formatMoney(charge.amount, charge.currency) }}
                    </p>
                </li>
            </ul>

            <Pagination :links="charges.links" />
        </template>
    </main>
</template>
