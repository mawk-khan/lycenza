<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';

/**
 * Phase 0H.3A — the administrative Syllabus surface.
 *
 * A SyllabusUnit is EXPECTED instructional content for one
 * SubjectOffering. It records nothing about what was actually taught,
 * nothing about a lesson, and nothing about any Student — there is no
 * roster, no mark and no grade anywhere on this page by design.
 *
 * `status` is edited through the ordinary update, exactly like every
 * other Academic Structure reference entity; there is deliberately no
 * activate/deactivate control and no delete.
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
    isRequired: boolean;
}

interface UnitRow {
    id: string;
    code: string;
    title: string;
    sequence: number;
    status: string;
}

interface Props {
    academicYears: AcademicYearRow[];
    offerings: OfferingRow[];
    filters: { academicYearId: string; subjectOfferingId: string };
    units: UnitRow[];
    statuses: string[];
    canManage: boolean;
}

const props = defineProps<Props>();

const academicYearId = ref(props.filters.academicYearId);
const subjectOfferingId = ref(props.filters.subjectOfferingId);

function reload(): void {
    router.get(
        '/app/syllabus',
        {
            academic_year_id: academicYearId.value || undefined,
            subject_offering_id: subjectOfferingId.value || undefined,
        },
        { preserveState: false, replace: true },
    );
}

watch(academicYearId, () => {
    // Changing the year invalidates the chosen Offering.
    subjectOfferingId.value = '';
    reload();
});

watch(subjectOfferingId, () => reload());

const createForm = useForm({
    subject_offering_id: '',
    code: '',
    title: '',
    sequence: 0,
    status: 'active',
});

function submitCreate(): void {
    createForm.subject_offering_id = subjectOfferingId.value;
    createForm.post('/app/syllabus', {
        onSuccess: () => createForm.reset('code', 'title', 'sequence'),
    });
}

const editingId = ref<string | null>(null);
const editForm = useForm({ code: '', title: '', sequence: 0, status: 'active' });

function startEdit(unit: UnitRow): void {
    editingId.value = unit.id;
    editForm.code = unit.code;
    editForm.title = unit.title;
    editForm.sequence = unit.sequence;
    editForm.status = unit.status;
}

function submitEdit(): void {
    if (editingId.value === null) {
        return;
    }
    editForm.patch(`/app/syllabus/${editingId.value}`, {
        onSuccess: () => {
            editingId.value = null;
        },
    });
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <h1 class="mt-2 text-xl font-semibold">Syllabus</h1>
        <p class="mt-1 text-sm text-slate-500">
            The ordered units of instructional content each Subject Offering is expected to cover.
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
                        <template v-if="!offering.isRequired"> (elective)</template>
                    </option>
                </select>
            </div>
        </div>

        <EmptyState
            v-if="!subjectOfferingId"
            class="mt-8"
            title="Choose a Subject Offering"
            description="Syllabus units belong to one Subject Offering. Pick a year and an Offering to view or edit its units."
        />

        <template v-else>
            <h2 class="mt-8 text-sm font-semibold text-slate-700">Units</h2>

            <EmptyState
                v-if="units.length === 0"
                class="mt-2"
                title="No syllabus units yet"
                description="Add the first unit of instructional content for this Subject Offering."
            />

            <table v-else class="mt-2 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2">#</th>
                        <th class="py-2">Code</th>
                        <th class="py-2">Title</th>
                        <th class="py-2">Status</th>
                        <th v-if="canManage" class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="unit in units" :key="unit.id" class="border-b border-slate-100">
                        <td class="py-2">{{ unit.sequence }}</td>
                        <td class="py-2 font-medium">{{ unit.code }}</td>
                        <td class="py-2">{{ unit.title }}</td>
                        <td class="py-2 capitalize">{{ unit.status }}</td>
                        <td v-if="canManage" class="py-2 text-right">
                            <button type="button" class="underline" @click="startEdit(unit)">
                                Edit
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <form
                v-if="canManage && editingId !== null"
                class="mt-6 rounded border border-slate-300 p-4"
                @submit.prevent="submitEdit"
            >
                <h3 class="text-sm font-semibold">Edit unit</h3>
                <div class="mt-3 flex flex-wrap gap-3">
                    <input
                        v-model="editForm.code"
                        type="text"
                        placeholder="Code"
                        class="w-32 rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <input
                        v-model="editForm.title"
                        type="text"
                        placeholder="Title"
                        class="w-80 rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <input
                        v-model.number="editForm.sequence"
                        type="number"
                        min="0"
                        class="w-24 rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <select
                        v-model="editForm.status"
                        class="w-32 rounded border border-slate-300 px-3 py-2 text-sm capitalize"
                    >
                        <option v-for="status in statuses" :key="status" :value="status">
                            {{ status }}
                        </option>
                    </select>
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                        :disabled="editForm.processing"
                    >
                        Save
                    </button>
                    <button type="button" class="text-sm underline" @click="editingId = null">
                        Cancel
                    </button>
                </div>
                <p v-if="editForm.errors.code" class="mt-2 text-sm text-red-700">
                    {{ editForm.errors.code }}
                </p>
            </form>

            <form
                v-if="canManage"
                class="mt-6 rounded border border-slate-300 p-4"
                @submit.prevent="submitCreate"
            >
                <h3 class="text-sm font-semibold">Add a unit</h3>
                <div class="mt-3 flex flex-wrap gap-3">
                    <input
                        v-model="createForm.code"
                        type="text"
                        placeholder="Code"
                        class="w-32 rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <input
                        v-model="createForm.title"
                        type="text"
                        placeholder="Title"
                        class="w-80 rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <input
                        v-model.number="createForm.sequence"
                        type="number"
                        min="0"
                        class="w-24 rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                        :disabled="createForm.processing"
                    >
                        Add unit
                    </button>
                </div>
                <p v-if="createForm.errors.code" class="mt-2 text-sm text-red-700">
                    {{ createForm.errors.code }}
                </p>
            </form>
        </template>
    </main>
</template>
