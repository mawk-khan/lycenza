<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';

/**
 * Phase 0I.3 — the administrative Assignment surface (ADR 0039).
 *
 * An Assignment is a staff-authored unit of work for one
 * SubjectOffering — title, instructions, an optional due date, and a
 * lifecycle. It records nothing about any Student or Submission — no
 * roster, no submission inbox, no mark and no grade anywhere on this
 * page by design.
 *
 * `status` moves ONLY through the dedicated publish/close actions,
 * never through the ordinary edit form — mirroring the Learning
 * Content page's own transition-route split.
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

interface AssignmentRow {
    id: string;
    title: string;
    instructions: string | null;
    dueOn: string | null;
    status: string;
}

interface Props {
    academicYears: AcademicYearRow[];
    offerings: OfferingRow[];
    filters: { academicYearId: string; subjectOfferingId: string };
    assignments: AssignmentRow[];
    statuses: string[];
    canManage: boolean;
}

const props = defineProps<Props>();

const academicYearId = ref(props.filters.academicYearId);
const subjectOfferingId = ref(props.filters.subjectOfferingId);

function reload(): void {
    router.get(
        '/app/assignments',
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
    title: '',
    instructions: '',
    due_on: '',
});

function submitCreate(): void {
    createForm.subject_offering_id = subjectOfferingId.value;
    createForm.post('/app/assignments', {
        onSuccess: () => createForm.reset('title', 'instructions', 'due_on'),
    });
}

const editingId = ref<string | null>(null);
const editForm = useForm({ title: '', instructions: '', due_on: '' });

function startEdit(row: AssignmentRow): void {
    editingId.value = row.id;
    editForm.title = row.title;
    editForm.instructions = row.instructions ?? '';
    editForm.due_on = row.dueOn ?? '';
}

function submitEdit(): void {
    if (editingId.value === null) {
        return;
    }
    editForm.patch(`/app/assignments/${editingId.value}`, {
        onSuccess: () => {
            editingId.value = null;
        },
    });
}

function publish(row: AssignmentRow): void {
    router.post(`/app/assignments/${row.id}/publish`, {}, { preserveScroll: true });
}

function close(row: AssignmentRow): void {
    router.post(`/app/assignments/${row.id}/close`, {}, { preserveScroll: true });
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <h1 class="mt-2 text-xl font-semibold">Assignments</h1>
        <p class="mt-1 text-sm text-ink-muted">
            Staff-authored units of work — title, instructions and a due date — for one Subject
            Offering.
        </p>

        <div class="mt-6 flex flex-wrap gap-4">
            <div>
                <label class="block text-sm text-ink-muted" for="filter-year">Academic Year</label>
                <select id="filter-year" v-model="academicYearId" class="input mt-1 w-64">
                    <option value="">Select a year</option>
                    <option v-for="year in academicYears" :key="year.id" :value="year.id">
                        {{ year.name }} ({{ year.code }})
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="filter-offering">
                    Subject Offering
                </label>
                <select id="filter-offering" v-model="subjectOfferingId" class="input mt-1 w-96">
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
            description="Assignments belong to one Subject Offering. Pick a year and an Offering to view or edit its assignments."
        />

        <template v-else>
            <h2 class="mt-8 text-sm font-semibold text-ink">Assignments</h2>

            <EmptyState
                v-if="assignments.length === 0"
                class="mt-2"
                title="No assignments yet"
                description="Add the first assignment for this Subject Offering."
            />

            <table v-else class="mt-2 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-ink-muted">
                        <th class="py-2">Due</th>
                        <th class="py-2">Title</th>
                        <th class="py-2">Status</th>
                        <th v-if="canManage" class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in assignments" :key="row.id" class="border-b border-line">
                        <td class="py-2">{{ row.dueOn ?? '—' }}</td>
                        <td class="py-2 font-medium">{{ row.title }}</td>
                        <td class="py-2 capitalize">{{ row.status }}</td>
                        <td v-if="canManage" class="py-2 text-right">
                            <button type="button" class="underline" @click="startEdit(row)">
                                Edit
                            </button>
                            <button
                                v-if="row.status !== 'published'"
                                type="button"
                                class="ml-3 underline"
                                @click="publish(row)"
                            >
                                Publish
                            </button>
                            <button
                                v-if="row.status === 'published'"
                                type="button"
                                class="ml-3 underline"
                                @click="close(row)"
                            >
                                Close
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <form
                v-if="canManage && editingId !== null"
                class="mt-6 rounded-xs border border-control-line p-4"
                @submit.prevent="submitEdit"
            >
                <h3 class="text-sm font-semibold">Edit assignment</h3>
                <div class="mt-3 flex flex-wrap gap-3">
                    <input
                        v-model="editForm.title"
                        type="text"
                        placeholder="Title"
                        class="input w-80"
                    />
                    <input v-model="editForm.due_on" type="date" class="input w-auto" />
                    <button type="submit" class="btn btn-primary" :disabled="editForm.processing">
                        Save
                    </button>
                    <button type="button" class="text-sm underline" @click="editingId = null">
                        Cancel
                    </button>
                </div>
                <textarea
                    v-model="editForm.instructions"
                    rows="3"
                    placeholder="Instructions"
                    class="input mt-3"
                ></textarea>
                <p v-if="editForm.errors.title" class="mt-2 text-sm text-danger">
                    {{ editForm.errors.title }}
                </p>
                <p v-if="editForm.errors.due_on" class="mt-2 text-sm text-danger">
                    {{ editForm.errors.due_on }}
                </p>
            </form>

            <form
                v-if="canManage"
                class="mt-6 rounded-xs border border-control-line p-4"
                @submit.prevent="submitCreate"
            >
                <h3 class="text-sm font-semibold">Add assignment</h3>
                <div class="mt-3 flex flex-wrap gap-3">
                    <input
                        v-model="createForm.title"
                        type="text"
                        placeholder="Title"
                        class="input w-80"
                    />
                    <input v-model="createForm.due_on" type="date" class="input w-auto" />
                    <button type="submit" class="btn btn-primary" :disabled="createForm.processing">
                        Add assignment
                    </button>
                </div>
                <textarea
                    v-model="createForm.instructions"
                    rows="3"
                    placeholder="Instructions"
                    class="input mt-3"
                ></textarea>
                <p v-if="createForm.errors.title" class="mt-2 text-sm text-danger">
                    {{ createForm.errors.title }}
                </p>
                <p v-if="createForm.errors.due_on" class="mt-2 text-sm text-danger">
                    {{ createForm.errors.due_on }}
                </p>
            </form>
        </template>
    </main>
</template>
