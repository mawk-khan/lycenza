<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';

/**
 * Phase 0H.3B — the administrative Curriculum Delivery surface.
 *
 * A CurriculumDelivery records that one Section has COVERED one
 * SyllabusUnit — when it began, and when, if yet, it finished. It says
 * nothing about an individual lesson, nothing about who taught it, and
 * nothing about any Student: there is no roster, no attendance, no
 * mark, no grade, no lesson plan and no attachment anywhere on this
 * page by design.
 *
 * "Not started" is a PROJECTION, never a stored row: the table
 * iterates the Offering's syllabus units and shows whatever delivery
 * exists for the chosen Section.
 *
 * Only REQUIRED Subject Offerings appear — an elective has no
 * Section-wide cohort, so per-Section delivery has no meaning for one.
 *
 * `expected_status` is always taken from the row as currently loaded,
 * so a stale page loses the compare-and-swap rather than silently
 * overwriting a colleague's change.
 */
interface AcademicYearRow {
    id: string;
    name: string;
    code: string;
}

interface OfferingRow {
    id: string;
    subjectCode: string | null;
    subjectName: string | null;
    gradeLevelName: string | null;
    campusName: string | null;
}

interface SectionRow {
    id: string;
    name: string;
    code: string;
}

interface UnitDeliveryRow {
    syllabusUnitId: string;
    code: string;
    title: string;
    sequence: number;
    state: string;
    deliveryId: string | null;
    startedOn: string | null;
    completedOn: string | null;
}

interface Props {
    academicYears: AcademicYearRow[];
    offerings: OfferingRow[];
    sections: SectionRow[];
    filters: { academicYearId: string; subjectOfferingId: string; sectionId: string };
    rows: UnitDeliveryRow[];
    canManage: boolean;
}

const props = defineProps<Props>();

const academicYearId = ref(props.filters.academicYearId);
const subjectOfferingId = ref(props.filters.subjectOfferingId);
const sectionId = ref(props.filters.sectionId);

function reload(): void {
    router.get(
        '/app/syllabus-delivery',
        {
            academic_year_id: academicYearId.value || undefined,
            subject_offering_id: subjectOfferingId.value || undefined,
            section_id: sectionId.value || undefined,
        },
        { preserveState: false, replace: true },
    );
}

watch(academicYearId, () => {
    // Changing the year invalidates both narrower choices.
    subjectOfferingId.value = '';
    sectionId.value = '';
    reload();
});

watch(subjectOfferingId, () => {
    // A Section is only valid within one Offering's context.
    sectionId.value = '';
    reload();
});

watch(sectionId, () => reload());

function stateLabel(state: string): string {
    if (state === 'not_started') {
        return 'Not started';
    }
    return state === 'in_progress' ? 'In progress' : 'Completed';
}

const startForm = useForm({
    subject_offering_id: '',
    section_id: '',
    syllabus_unit_id: '',
    started_on: '',
});

const startingUnitId = ref<string | null>(null);

function beginStart(row: UnitDeliveryRow): void {
    startingUnitId.value = row.syllabusUnitId;
    startForm.clearErrors();
    startForm.started_on = '';
}

function submitStart(): void {
    if (startingUnitId.value === null) {
        return;
    }
    startForm.subject_offering_id = subjectOfferingId.value;
    startForm.section_id = sectionId.value;
    startForm.syllabus_unit_id = startingUnitId.value;
    startForm.post('/app/syllabus-delivery', {
        onSuccess: () => {
            startingUnitId.value = null;
            startForm.reset('started_on');
        },
    });
}

const transitionForm = useForm({
    expected_status: '',
    new_status: '',
    completed_on: '',
});

const completingRow = ref<UnitDeliveryRow | null>(null);

function beginComplete(row: UnitDeliveryRow): void {
    completingRow.value = row;
    transitionForm.clearErrors();
    transitionForm.completed_on = '';
}

function submitComplete(): void {
    const row = completingRow.value;
    if (row === null || row.deliveryId === null) {
        return;
    }
    // expected_status comes from the row as loaded — this is the
    // compare-and-swap guard, not a convenience default.
    transitionForm.expected_status = row.state;
    transitionForm.new_status = 'completed';
    transitionForm.post(`/app/syllabus-delivery/${row.deliveryId}/transition`, {
        onSuccess: () => {
            completingRow.value = null;
            transitionForm.reset('completed_on');
        },
    });
}

function reopen(row: UnitDeliveryRow): void {
    if (row.deliveryId === null) {
        return;
    }
    router.post(`/app/syllabus-delivery/${row.deliveryId}/transition`, {
        expected_status: row.state,
        new_status: 'in_progress',
    });
}

const correctForm = useForm({ started_on: '', completed_on: '' });
const correctingRow = ref<UnitDeliveryRow | null>(null);

function beginCorrect(row: UnitDeliveryRow): void {
    correctingRow.value = row;
    correctForm.clearErrors();
    correctForm.started_on = row.startedOn ?? '';
    correctForm.completed_on = row.completedOn ?? '';
}

function submitCorrect(): void {
    const row = correctingRow.value;
    if (row === null || row.deliveryId === null) {
        return;
    }
    const payload: Record<string, string> = { started_on: correctForm.started_on };
    if (row.state === 'completed' && correctForm.completed_on !== '') {
        payload.completed_on = correctForm.completed_on;
    }
    router.patch(`/app/syllabus-delivery/${row.deliveryId}`, payload, {
        onSuccess: () => {
            correctingRow.value = null;
        },
    });
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <h1 class="mt-2 text-xl font-semibold">Curriculum Delivery</h1>
        <p class="mt-1 text-sm text-slate-500">
            Which syllabus units each Section has actually covered, and when.
        </p>

        <div class="mt-6 flex flex-wrap gap-4">
            <div>
                <label class="block text-sm text-slate-600" for="filter-year">Academic Year</label>
                <select
                    id="filter-year"
                    v-model="academicYearId"
                    class="mt-1 w-64 rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="">Select a year</option>
                    <option v-for="year in academicYears" :key="year.id" :value="year.id">
                        {{ year.name }} ({{ year.code }})
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-offering">
                    Subject Offering
                </label>
                <select
                    id="filter-offering"
                    v-model="subjectOfferingId"
                    class="mt-1 w-96 rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="">Select a Subject Offering</option>
                    <option v-for="offering in offerings" :key="offering.id" :value="offering.id">
                        {{ offering.subjectCode }} · {{ offering.subjectName }} —
                        {{ offering.gradeLevelName }}, {{ offering.campusName }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-section">Section</label>
                <select
                    id="filter-section"
                    v-model="sectionId"
                    class="mt-1 w-64 rounded border border-slate-300 px-3 py-2 text-sm"
                    :disabled="!subjectOfferingId"
                >
                    <option value="">Select a Section</option>
                    <option v-for="section in sections" :key="section.id" :value="section.id">
                        {{ section.name }} ({{ section.code }})
                    </option>
                </select>
            </div>
        </div>

        <EmptyState
            v-if="!subjectOfferingId || !sectionId"
            class="mt-8"
            title="Choose a Subject Offering and a Section"
            description="Curriculum delivery is recorded per Section. Pick a required Subject Offering, then the Section whose coverage you want to review."
        />

        <template v-else>
            <h2 class="mt-8 text-sm font-semibold text-slate-700">Coverage</h2>

            <EmptyState
                v-if="rows.length === 0"
                class="mt-2"
                title="No syllabus units yet"
                description="This Subject Offering has no active syllabus units, so there is nothing to deliver yet."
            />

            <table v-else class="mt-2 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2">#</th>
                        <th class="py-2">Code</th>
                        <th class="py-2">Title</th>
                        <th class="py-2">State</th>
                        <th class="py-2">Started</th>
                        <th class="py-2">Completed</th>
                        <th v-if="canManage" class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.syllabusUnitId"
                        class="border-b border-slate-100"
                    >
                        <td class="py-2">{{ row.sequence }}</td>
                        <td class="py-2 font-medium">{{ row.code }}</td>
                        <td class="py-2">{{ row.title }}</td>
                        <td class="py-2">{{ stateLabel(row.state) }}</td>
                        <td class="py-2">{{ row.startedOn ?? '—' }}</td>
                        <td class="py-2">{{ row.completedOn ?? '—' }}</td>
                        <td v-if="canManage" class="space-x-3 py-2 text-right">
                            <button
                                v-if="row.state === 'not_started'"
                                type="button"
                                class="underline"
                                @click="beginStart(row)"
                            >
                                Start
                            </button>
                            <button
                                v-if="row.state === 'in_progress'"
                                type="button"
                                class="underline"
                                @click="beginComplete(row)"
                            >
                                Mark completed
                            </button>
                            <button
                                v-if="row.state === 'completed'"
                                type="button"
                                class="underline"
                                @click="reopen(row)"
                            >
                                Reopen
                            </button>
                            <button
                                v-if="row.deliveryId !== null"
                                type="button"
                                class="underline"
                                @click="beginCorrect(row)"
                            >
                                Correct dates
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <form
                v-if="canManage && startingUnitId !== null"
                class="mt-6 rounded border border-slate-300 p-4"
                @submit.prevent="submitStart"
            >
                <h3 class="text-sm font-semibold">Start delivery</h3>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <label class="text-sm text-slate-600" for="start-date">Started on</label>
                    <input
                        id="start-date"
                        v-model="startForm.started_on"
                        type="date"
                        class="rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                        :disabled="startForm.processing"
                    >
                        Start
                    </button>
                    <button type="button" class="text-sm underline" @click="startingUnitId = null">
                        Cancel
                    </button>
                </div>
                <p v-if="startForm.errors.started_on" class="mt-2 text-sm text-red-700">
                    {{ startForm.errors.started_on }}
                </p>
            </form>

            <form
                v-if="canManage && completingRow !== null"
                class="mt-6 rounded border border-slate-300 p-4"
                @submit.prevent="submitComplete"
            >
                <h3 class="text-sm font-semibold">Mark completed</h3>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <label class="text-sm text-slate-600" for="complete-date">Completed on</label>
                    <input
                        id="complete-date"
                        v-model="transitionForm.completed_on"
                        type="date"
                        class="rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                        :disabled="transitionForm.processing"
                    >
                        Complete
                    </button>
                    <button type="button" class="text-sm underline" @click="completingRow = null">
                        Cancel
                    </button>
                </div>
                <p v-if="transitionForm.errors.completed_on" class="mt-2 text-sm text-red-700">
                    {{ transitionForm.errors.completed_on }}
                </p>
            </form>

            <form
                v-if="canManage && correctingRow !== null"
                class="mt-6 rounded border border-slate-300 p-4"
                @submit.prevent="submitCorrect"
            >
                <h3 class="text-sm font-semibold">Correct dates</h3>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <label class="text-sm text-slate-600" for="correct-started">Started on</label>
                    <input
                        id="correct-started"
                        v-model="correctForm.started_on"
                        type="date"
                        class="rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <template v-if="correctingRow.state === 'completed'">
                        <label class="text-sm text-slate-600" for="correct-completed">
                            Completed on
                        </label>
                        <input
                            id="correct-completed"
                            v-model="correctForm.completed_on"
                            type="date"
                            class="rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </template>
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                    >
                        Save
                    </button>
                    <button type="button" class="text-sm underline" @click="correctingRow = null">
                        Cancel
                    </button>
                </div>
            </form>
        </template>
    </main>
</template>
