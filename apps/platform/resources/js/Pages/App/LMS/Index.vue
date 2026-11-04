<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';

/**
 * Phase 0I.2 — the administrative Learning Content surface (ADR 0039).
 *
 * A LearningContent row is a School-authored instructional resource for
 * one SubjectOffering — a reading, a link, a note, or (via the shared
 * Documents API, not this page) an attached file. It records nothing
 * about any Student, Assignment or Submission — there is no roster, no
 * mark and no grade anywhere on this page by design.
 *
 * `status` moves ONLY through the dedicated publish/archive actions,
 * never through the ordinary edit form — mirroring Curriculum
 * Delivery's own transition-route split, not Syllabus's plain-PATCH
 * status field.
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

interface ContentRow {
    id: string;
    title: string;
    description: string | null;
    sequence: number;
    status: string;
}

interface Props {
    academicYears: AcademicYearRow[];
    offerings: OfferingRow[];
    filters: { academicYearId: string; subjectOfferingId: string };
    content: ContentRow[];
    statuses: string[];
    canManage: boolean;
}

const props = defineProps<Props>();

const academicYearId = ref(props.filters.academicYearId);
const subjectOfferingId = ref(props.filters.subjectOfferingId);

function reload(): void {
    router.get(
        '/app/learning-content',
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
    description: '',
    sequence: 0,
});

function submitCreate(): void {
    createForm.subject_offering_id = subjectOfferingId.value;
    createForm.post('/app/learning-content', {
        onSuccess: () => createForm.reset('title', 'description', 'sequence'),
    });
}

const editingId = ref<string | null>(null);
const editForm = useForm({ title: '', description: '', sequence: 0 });

function startEdit(row: ContentRow): void {
    editingId.value = row.id;
    editForm.title = row.title;
    editForm.description = row.description ?? '';
    editForm.sequence = row.sequence;
}

function submitEdit(): void {
    if (editingId.value === null) {
        return;
    }
    editForm.patch(`/app/learning-content/${editingId.value}`, {
        onSuccess: () => {
            editingId.value = null;
        },
    });
}

function publish(row: ContentRow): void {
    router.post(`/app/learning-content/${row.id}/publish`, {}, { preserveScroll: true });
}

function archive(row: ContentRow): void {
    router.post(`/app/learning-content/${row.id}/archive`, {}, { preserveScroll: true });
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <h1 class="mt-2 text-xl font-semibold">Learning Content</h1>
        <p class="mt-1 text-sm text-ink-muted">
            School-authored instructional resources — readings, links and notes — for one Subject
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
            description="Learning Content belongs to one Subject Offering. Pick a year and an Offering to view or edit its resources."
        />

        <template v-else>
            <h2 class="mt-8 text-sm font-semibold text-ink">Content</h2>

            <EmptyState
                v-if="content.length === 0"
                class="mt-2"
                title="No learning content yet"
                description="Add the first resource for this Subject Offering."
            />

            <table v-else class="mt-2 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-ink-muted">
                        <th class="py-2">#</th>
                        <th class="py-2">Title</th>
                        <th class="py-2">Status</th>
                        <th v-if="canManage" class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in content" :key="row.id" class="border-b border-line">
                        <td class="py-2">{{ row.sequence }}</td>
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
                                @click="archive(row)"
                            >
                                Archive
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
                <h3 class="text-sm font-semibold">Edit content</h3>
                <div class="mt-3 flex flex-wrap gap-3">
                    <input
                        v-model="editForm.title"
                        type="text"
                        placeholder="Title"
                        class="input w-80"
                    />
                    <input
                        v-model.number="editForm.sequence"
                        type="number"
                        min="0"
                        class="input w-24"
                    />
                    <button type="submit" class="btn btn-primary" :disabled="editForm.processing">
                        Save
                    </button>
                    <button type="button" class="text-sm underline" @click="editingId = null">
                        Cancel
                    </button>
                </div>
                <textarea
                    v-model="editForm.description"
                    rows="3"
                    placeholder="Description / link / note"
                    class="input mt-3"
                ></textarea>
                <p v-if="editForm.errors.title" class="mt-2 text-sm text-danger">
                    {{ editForm.errors.title }}
                </p>
            </form>

            <form
                v-if="canManage"
                class="mt-6 rounded-xs border border-control-line p-4"
                @submit.prevent="submitCreate"
            >
                <h3 class="text-sm font-semibold">Add content</h3>
                <div class="mt-3 flex flex-wrap gap-3">
                    <input
                        v-model="createForm.title"
                        type="text"
                        placeholder="Title"
                        class="input w-80"
                    />
                    <input
                        v-model.number="createForm.sequence"
                        type="number"
                        min="0"
                        class="input w-24"
                    />
                    <button type="submit" class="btn btn-primary" :disabled="createForm.processing">
                        Add content
                    </button>
                </div>
                <textarea
                    v-model="createForm.description"
                    rows="3"
                    placeholder="Description / link / note"
                    class="input mt-3"
                ></textarea>
                <p v-if="createForm.errors.title" class="mt-2 text-sm text-danger">
                    {{ createForm.errors.title }}
                </p>
            </form>
        </template>
    </main>
</template>
