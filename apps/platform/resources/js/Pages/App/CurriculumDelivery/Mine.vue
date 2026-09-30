<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';

/**
 * TCH.3 — "My Curriculum Delivery": record syllabus coverage for the
 * classes you teach.
 *
 * Only your own classes appear — the Section and Subject Offering pairs
 * your Teaching Assignments cover — and only the coverage recorded within
 * your assignment dates. Everything here is decided by the server; this
 * page never receives another teacher's records.
 */
interface ContextRow {
    sectionId: string;
    sectionName: string | null;
    sectionCode: string | null;
    gradeLevelName: string | null;
    subjectOfferingId: string;
    subjectCode: string | null;
    subjectName: string | null;
    current: boolean;
    periods: { startsOn: string; endsOn: string | null }[];
}

interface UnitRow {
    syllabusUnitId: string;
    code: string;
    title: string;
    sequence: number;
    state: 'not_started' | 'in_progress' | 'completed' | 'unavailable';
    deliveryId: string | null;
    startedOn: string | null;
    completedOn: string | null;
}

interface Props {
    eligible: boolean;
    contexts: ContextRow[];
    selected: { sectionId: string; subjectOfferingId: string } | null;
    rows: UnitRow[];
    today: string | null;
}

const props = defineProps<Props>();

function contextKey(sectionId: string, subjectOfferingId: string): string {
    return `${sectionId}|${subjectOfferingId}`;
}

const selectedKey = ref(
    props.selected ? contextKey(props.selected.sectionId, props.selected.subjectOfferingId) : '',
);

const selectedContext = computed<ContextRow | undefined>(() =>
    props.contexts.find((c) => contextKey(c.sectionId, c.subjectOfferingId) === selectedKey.value),
);

function choose(): void {
    const context = selectedContext.value;
    router.get(
        '/app/my-curriculum-delivery',
        context
            ? { section_id: context.sectionId, subject_offering_id: context.subjectOfferingId }
            : {},
        { preserveState: false, replace: true },
    );
}

const stateLabels: Record<UnitRow['state'], string> = {
    not_started: 'Not started',
    in_progress: 'In progress',
    completed: 'Completed',
    unavailable: 'Recorded outside your assignment',
};

function periodLabel(period: { startsOn: string; endsOn: string | null }): string {
    return `${period.startsOn} – ${period.endsOn ?? 'open-ended'}`;
}

const startForm = useForm({
    subject_offering_id: '',
    section_id: '',
    syllabus_unit_id: '',
    started_on: '',
});
const startingUnitId = ref<string | null>(null);

function beginStart(row: UnitRow): void {
    startingUnitId.value = row.syllabusUnitId;
    startForm.clearErrors();
    startForm.started_on = props.today ?? '';
}

function submitStart(): void {
    if (startingUnitId.value === null || !props.selected) {
        return;
    }
    startForm.subject_offering_id = props.selected.subjectOfferingId;
    startForm.section_id = props.selected.sectionId;
    startForm.syllabus_unit_id = startingUnitId.value;
    startForm.post('/app/my-curriculum-delivery', {
        preserveScroll: true,
        onSuccess: () => {
            startingUnitId.value = null;
        },
    });
}

const transitionForm = useForm({ expected_status: '', new_status: '', completed_on: '' });
const completingRow = ref<UnitRow | null>(null);

function beginComplete(row: UnitRow): void {
    completingRow.value = row;
    transitionForm.clearErrors();
    transitionForm.completed_on = props.today ?? '';
}

function submitComplete(): void {
    const row = completingRow.value;
    if (row === null || row.deliveryId === null) {
        return;
    }
    transitionForm.expected_status = 'in_progress';
    transitionForm.new_status = 'completed';
    transitionForm.post(`/app/my-curriculum-delivery/${row.deliveryId}/transition`, {
        preserveScroll: true,
        onSuccess: () => {
            completingRow.value = null;
        },
    });
}

function reopen(row: UnitRow): void {
    if (row.deliveryId === null) {
        return;
    }
    router.post(
        `/app/my-curriculum-delivery/${row.deliveryId}/transition`,
        { expected_status: 'completed', new_status: 'in_progress' },
        { preserveScroll: true },
    );
}

const correctForm = useForm({ started_on: '', completed_on: '' });
const correctingRow = ref<UnitRow | null>(null);

function beginCorrect(row: UnitRow): void {
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
    if (row.state === 'completed') {
        payload.completed_on = correctForm.completed_on;
    }
    correctForm
        .transform(() => payload)
        .patch(`/app/my-curriculum-delivery/${row.deliveryId}`, {
            preserveScroll: true,
            onSuccess: () => {
                correctingRow.value = null;
            },
        });
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <h1 class="mt-2 text-xl font-semibold">My Curriculum Delivery</h1>
        <p class="mt-1 text-sm text-slate-500">
            Record which syllabus units your classes have covered. Only the classes and dates of
            your Teaching Assignments appear here.
        </p>

        <EmptyState
            v-if="!eligible"
            class="mt-8"
            title="No teaching record"
            description="Your account is not linked to a current Employee record at this School, so there is nothing to show. Ask your School administrator."
        />

        <EmptyState
            v-else-if="contexts.length === 0"
            class="mt-8"
            title="No classes assigned"
            description="You have no Teaching Assignments yet. Your School administrator assigns classes."
        />

        <template v-else>
            <div class="mt-6">
                <label class="block text-sm text-slate-600" for="my-class">Class</label>
                <select
                    id="my-class"
                    v-model="selectedKey"
                    class="mt-1 w-full max-w-xl rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="choose"
                >
                    <option value="">Select one of your classes</option>
                    <option
                        v-for="context in contexts"
                        :key="contextKey(context.sectionId, context.subjectOfferingId)"
                        :value="contextKey(context.sectionId, context.subjectOfferingId)"
                    >
                        {{ context.subjectCode }} · {{ context.subjectName }} —
                        {{ context.gradeLevelName }} {{ context.sectionName }}
                        {{ context.current ? '' : '(not current)' }}
                    </option>
                </select>
                <p v-if="selectedContext" class="mt-2 text-sm text-slate-500">
                    Your assignment:
                    {{ selectedContext.periods.map(periodLabel).join(', ') }}
                </p>
            </div>

            <EmptyState
                v-if="selected === null"
                class="mt-8"
                title="Choose a class"
                description="Pick one of your classes to see its syllabus and record coverage."
            />

            <template v-else>
                <EmptyState
                    v-if="rows.length === 0"
                    class="mt-8"
                    title="No syllabus units yet"
                    description="This subject has no active syllabus units yet."
                />

                <table v-else class="mt-6 w-full border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="py-2">#</th>
                            <th class="py-2">Code</th>
                            <th class="py-2">Title</th>
                            <th class="py-2">State</th>
                            <th class="py-2">Started</th>
                            <th class="py-2">Completed</th>
                            <th class="py-2"></th>
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
                            <td class="py-2">{{ stateLabels[row.state] }}</td>
                            <td class="py-2">{{ row.startedOn ?? '—' }}</td>
                            <td class="py-2">{{ row.completedOn ?? '—' }}</td>
                            <td class="space-x-3 py-2 text-right">
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
                    v-if="startingUnitId !== null"
                    class="mt-6 rounded border border-slate-300 p-4"
                    @submit.prevent="submitStart"
                >
                    <h2 class="text-sm font-semibold">Start delivery</h2>
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
                        <button
                            type="button"
                            class="text-sm underline"
                            @click="startingUnitId = null"
                        >
                            Cancel
                        </button>
                    </div>
                    <p v-if="startForm.errors.started_on" class="mt-2 text-sm text-red-700">
                        {{ startForm.errors.started_on }}
                    </p>
                </form>

                <form
                    v-if="completingRow !== null"
                    class="mt-6 rounded border border-slate-300 p-4"
                    @submit.prevent="submitComplete"
                >
                    <h2 class="text-sm font-semibold">Mark completed</h2>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <label class="text-sm text-slate-600" for="complete-date">
                            Completed on
                        </label>
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
                        <button
                            type="button"
                            class="text-sm underline"
                            @click="completingRow = null"
                        >
                            Cancel
                        </button>
                    </div>
                    <p v-if="transitionForm.errors.new_status" class="mt-2 text-sm text-red-700">
                        {{ transitionForm.errors.new_status }}
                    </p>
                </form>

                <form
                    v-if="correctingRow !== null"
                    class="mt-6 rounded border border-slate-300 p-4"
                    @submit.prevent="submitCorrect"
                >
                    <h2 class="text-sm font-semibold">Correct dates</h2>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <label class="text-sm text-slate-600" for="correct-started">
                            Started on
                        </label>
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
                        <button
                            type="button"
                            class="text-sm underline"
                            @click="correctingRow = null"
                        >
                            Cancel
                        </button>
                    </div>
                    <p v-if="correctForm.errors.started_on" class="mt-2 text-sm text-red-700">
                        {{ correctForm.errors.started_on }}
                    </p>
                </form>
            </template>
        </template>
    </main>
</template>
