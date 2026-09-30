<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';

/**
 * TCH.2 — the administrative Teaching Assignment page.
 *
 * A Teaching Assignment records that an Employee owns one Section + required
 * Subject Offering teaching context for a date range (inclusive; no end date
 * means open-ended). It is the ownership fact later checkpoints will build
 * teacher access on — on its own it gives nobody access to anything.
 *
 * An assignment is never edited or deleted: a mistake is ended and a new
 * assignment created. The pickers are a convenience; the server validates
 * every choice (same School, same class context, required offering, an
 * employed Employee, no overlapping period for the same Employee).
 */
interface AcademicYearRow {
    id: string;
    name: string;
    code: string;
    status: string;
}

interface AssignmentRow {
    id: string;
    employee: { id: string; employeeNumber: string | null; fullName: string | null };
    section: { id: string; name: string | null; code: string | null };
    subjectOffering: { id: string; subjectName: string | null; subjectCode: string | null };
    startsOn: string;
    endsOn: string | null;
    state: 'upcoming' | 'current' | 'past';
    endedAt: string | null;
    endReason: string | null;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface SectionOption {
    id: string;
    name: string;
    code: string;
    campusId: string;
    gradeLevelId: string;
    gradeLevelName: string | null;
}

interface OfferingOption {
    id: string;
    subjectCode: string | null;
    subjectName: string | null;
    campusId: string;
    gradeLevelId: string;
}

interface EmployeeOption {
    id: string;
    employeeNumber: string;
    fullName: string;
}

interface Props {
    academicYears: AcademicYearRow[];
    academicYearId: string;
    assignments: Paginated<AssignmentRow> | null;
    endReasons: string[];
    canManage: boolean;
    options: {
        sections: SectionOption[];
        offerings: OfferingOption[];
        employees: EmployeeOption[];
    } | null;
}

const props = defineProps<Props>();

const academicYearId = ref(props.academicYearId);

watch(academicYearId, () => {
    router.get(
        '/app/teaching-assignments',
        { academic_year_id: academicYearId.value || undefined },
        { preserveState: false, replace: true },
    );
});

const stateLabels: Record<AssignmentRow['state'], string> = {
    upcoming: 'Upcoming',
    current: 'Current',
    past: 'Past',
};

const reasonLabels: Record<string, string> = {
    completed: 'Completed',
    reassigned: 'Reassigned',
    employment_ended: 'Employment ended',
};

const createForm = useForm({
    employee_id: '',
    section_id: '',
    subject_offering_id: '',
    starts_on: '',
    ends_on: '',
});

// Only Subject Offerings of the chosen Section's Campus and Grade can be
// paired with it (the server enforces the same rule).
const compatibleOfferings = computed<OfferingOption[]>(() => {
    const section = props.options?.sections.find((s) => s.id === createForm.section_id);
    if (!section) {
        return [];
    }
    return (props.options?.offerings ?? []).filter(
        (o) => o.campusId === section.campusId && o.gradeLevelId === section.gradeLevelId,
    );
});

watch(
    () => createForm.section_id,
    () => {
        createForm.subject_offering_id = '';
    },
);

function submitCreate(): void {
    createForm
        .transform((data) => ({ ...data, ends_on: data.ends_on || null }))
        .post('/app/teaching-assignments', {
            preserveScroll: true,
            onSuccess: () => createForm.reset(),
        });
}

const endingId = ref<string | null>(null);
const endForm = useForm({ ends_on: '', reason: '' });

function beginEnd(row: AssignmentRow): void {
    endingId.value = row.id;
    endForm.clearErrors();
    endForm.ends_on = row.endsOn ?? '';
    endForm.reason = '';
}

function submitEnd(): void {
    if (endingId.value === null) {
        return;
    }
    endForm.post(`/app/teaching-assignments/${endingId.value}/end`, {
        preserveScroll: true,
        onSuccess: () => {
            endingId.value = null;
            endForm.reset();
        },
    });
}
</script>

<template>
    <main class="mx-auto max-w-6xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <h1 class="mt-2 text-xl font-semibold">Teaching Assignments</h1>
        <p class="mt-1 text-sm text-slate-500">
            Who teaches which Section and Subject Offering, and for which dates. This record grants
            no access by itself.
        </p>

        <div class="mt-6">
            <label class="block text-sm text-slate-600" for="filter-year">Academic Year</label>
            <select
                id="filter-year"
                v-model="academicYearId"
                class="mt-1 w-72 rounded border border-slate-300 px-3 py-2 text-sm"
            >
                <option value="">Select a year</option>
                <option v-for="year in academicYears" :key="year.id" :value="year.id">
                    {{ year.name }} ({{ year.code }})
                </option>
            </select>
        </div>

        <EmptyState
            v-if="assignments === null"
            class="mt-8"
            title="Choose an academic year"
            description="Teaching Assignments are listed per academic year."
        />

        <template v-else>
            <EmptyState
                v-if="assignments.data.length === 0"
                class="mt-8"
                title="No Teaching Assignments yet"
                description="Nobody has been assigned to teach a Section in this academic year."
            />

            <table v-else class="mt-6 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2">Employee</th>
                        <th class="py-2">Section</th>
                        <th class="py-2">Subject</th>
                        <th class="py-2">From</th>
                        <th class="py-2">To</th>
                        <th class="py-2">State</th>
                        <th class="py-2">Ended</th>
                        <th v-if="canManage" class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in assignments.data"
                        :key="row.id"
                        class="border-b border-slate-100"
                    >
                        <td class="py-2">
                            {{ row.employee.fullName }}
                            <span class="text-slate-500">({{ row.employee.employeeNumber }})</span>
                        </td>
                        <td class="py-2">{{ row.section.name }} ({{ row.section.code }})</td>
                        <td class="py-2">
                            {{ row.subjectOffering.subjectCode }} ·
                            {{ row.subjectOffering.subjectName }}
                        </td>
                        <td class="py-2">{{ row.startsOn }}</td>
                        <td class="py-2">{{ row.endsOn ?? 'Open-ended' }}</td>
                        <td class="py-2">{{ stateLabels[row.state] }}</td>
                        <td class="py-2">
                            {{
                                row.endReason ? (reasonLabels[row.endReason] ?? row.endReason) : '—'
                            }}
                        </td>
                        <td v-if="canManage" class="py-2 text-right">
                            <button
                                v-if="row.endedAt === null"
                                type="button"
                                class="underline"
                                @click="beginEnd(row)"
                            >
                                End
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <nav
                v-if="assignments.last_page > 1"
                class="mt-4 flex items-center gap-4 text-sm"
                aria-label="Pagination"
            >
                <a
                    v-if="assignments.prev_page_url"
                    class="underline"
                    :href="assignments.prev_page_url"
                    >Previous</a
                >
                <span>Page {{ assignments.current_page }} of {{ assignments.last_page }}</span>
                <a
                    v-if="assignments.next_page_url"
                    class="underline"
                    :href="assignments.next_page_url"
                    >Next</a
                >
            </nav>

            <form
                v-if="canManage && endingId !== null"
                class="mt-6 rounded border border-slate-300 p-4"
                @submit.prevent="submitEnd"
            >
                <h2 class="text-sm font-semibold">End assignment</h2>
                <p class="mt-1 text-sm text-slate-500">
                    The last day this Employee owns the class. It cannot be before the start date or
                    after an existing end date, and an ended assignment cannot be changed.
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <label class="text-sm text-slate-600" for="end-date">Last day</label>
                    <input
                        id="end-date"
                        v-model="endForm.ends_on"
                        type="date"
                        class="rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <label class="text-sm text-slate-600" for="end-reason">Reason</label>
                    <select
                        id="end-reason"
                        v-model="endForm.reason"
                        class="rounded border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="">Select a reason</option>
                        <option v-for="reason in endReasons" :key="reason" :value="reason">
                            {{ reasonLabels[reason] ?? reason }}
                        </option>
                    </select>
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                        :disabled="endForm.processing"
                    >
                        End assignment
                    </button>
                    <button type="button" class="text-sm underline" @click="endingId = null">
                        Cancel
                    </button>
                </div>
                <p v-if="endForm.errors.ends_on" class="mt-2 text-sm text-red-700">
                    {{ endForm.errors.ends_on }}
                </p>
                <p v-if="endForm.errors.reason" class="mt-2 text-sm text-red-700">
                    {{ endForm.errors.reason }}
                </p>
            </form>

            <form
                v-if="canManage && options !== null"
                class="mt-8 rounded border border-slate-300 p-4"
                @submit.prevent="submitCreate"
            >
                <h2 class="text-sm font-semibold">New assignment</h2>
                <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div>
                        <label class="block text-sm text-slate-600" for="new-employee"
                            >Employee</label
                        >
                        <select
                            id="new-employee"
                            v-model="createForm.employee_id"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="">Select an Employee</option>
                            <option
                                v-for="employee in options.employees"
                                :key="employee.id"
                                :value="employee.id"
                            >
                                {{ employee.fullName }} ({{ employee.employeeNumber }})
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600" for="new-section"
                            >Section</label
                        >
                        <select
                            id="new-section"
                            v-model="createForm.section_id"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="">Select a Section</option>
                            <option
                                v-for="section in options.sections"
                                :key="section.id"
                                :value="section.id"
                            >
                                {{ section.name }} ({{ section.code }}) —
                                {{ section.gradeLevelName }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600" for="new-offering">
                            Subject Offering (required subjects only)
                        </label>
                        <select
                            id="new-offering"
                            v-model="createForm.subject_offering_id"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                            :disabled="!createForm.section_id"
                        >
                            <option value="">Select a Subject Offering</option>
                            <option
                                v-for="offering in compatibleOfferings"
                                :key="offering.id"
                                :value="offering.id"
                            >
                                {{ offering.subjectCode }} · {{ offering.subjectName }}
                            </option>
                        </select>
                    </div>
                    <div class="flex gap-3">
                        <div>
                            <label class="block text-sm text-slate-600" for="new-starts"
                                >From</label
                            >
                            <input
                                id="new-starts"
                                v-model="createForm.starts_on"
                                type="date"
                                class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label class="block text-sm text-slate-600" for="new-ends">
                                To (optional)
                            </label>
                            <input
                                id="new-ends"
                                v-model="createForm.ends_on"
                                type="date"
                                class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                        :disabled="createForm.processing"
                    >
                        Create assignment
                    </button>
                </div>
                <p
                    v-for="(message, field) in createForm.errors"
                    :key="field"
                    class="mt-2 text-sm text-red-700"
                >
                    {{ message }}
                </p>
            </form>
        </template>
    </main>
</template>
