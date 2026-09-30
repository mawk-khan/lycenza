<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';

/**
 * TCH.5C — "My Learning Content": the owned teacher Learning Content page
 * (ADR 0063 §36).
 *
 * The server sends only what this teacher may read (their own rows while
 * they teach every Section they target, published rows for a Section they
 * teach, published Offering-wide rows of an Offering they teach) and only
 * the Sections they teach today for the audience picker. Nothing is
 * filtered here. Rows they do not own are read-only; the audience of a
 * row is fixed when it is created.
 */
interface SectionOption {
    id: string;
    code: string | null;
    name: string | null;
}

interface ContextRow {
    subjectOfferingId: string;
    subjectCode: string | null;
    subjectName: string | null;
    gradeLevelName: string | null;
    campusName: string | null;
    academicYearName: string | null;
    sections: SectionOption[];
}

interface ContentRow {
    id: string;
    subjectOfferingId: string;
    title: string;
    description: string | null;
    sequence: number;
    status: string;
    offeringWide: boolean;
    mine: boolean;
    canEdit: boolean;
    audience: { sectionId: string; sectionCode: string | null }[];
}

interface Props {
    contexts: ContextRow[];
    filters: { subjectOfferingId: string };
    content: ContentRow[];
    canAuthor: boolean;
}

const props = defineProps<Props>();

const subjectOfferingId = ref(props.filters.subjectOfferingId);
watch(subjectOfferingId, () =>
    router.get(
        '/app/my-learning-content',
        { subject_offering_id: subjectOfferingId.value || undefined },
        { preserveState: false, replace: true },
    ),
);

const selectedContext = computed(() =>
    props.contexts.find((c) => c.subjectOfferingId === subjectOfferingId.value),
);

function audienceLabel(row: ContentRow): string {
    if (row.offeringWide) {
        return 'Whole Subject Offering (school)';
    }
    return row.audience.map((a) => a.sectionCode ?? '—').join(', ');
}

const createForm = useForm({
    subject_offering_id: '',
    title: '',
    description: '',
    sequence: 0,
    audience_section_ids: [] as string[],
});

function submitCreate(): void {
    createForm.subject_offering_id = subjectOfferingId.value;
    createForm.post('/app/my-learning-content', {
        onSuccess: () =>
            createForm.reset('title', 'description', 'sequence', 'audience_section_ids'),
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
    editForm.patch(`/app/my-learning-content/${editingId.value}`, {
        onSuccess: () => {
            editingId.value = null;
        },
    });
}

function publish(row: ContentRow): void {
    router.post(`/app/my-learning-content/${row.id}/publish`, {}, { preserveScroll: true });
}

function archive(row: ContentRow): void {
    router.post(`/app/my-learning-content/${row.id}/archive`, {}, { preserveScroll: true });
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <h1 class="mt-2 text-xl font-semibold">My Learning Content</h1>
        <p class="mt-1 text-sm text-slate-500">
            Readings, links and notes for the classes you teach. You can change only the content you
            created, while you still teach every Section it is for.
        </p>

        <EmptyState
            v-if="!canAuthor || contexts.length === 0"
            class="mt-8"
            title="No classes to show"
            description="You have no current teaching assignment. Ask your school administrator if this looks wrong."
        />

        <template v-else>
            <div class="mt-6">
                <label class="block text-sm text-slate-600" for="filter-offering">
                    Subject Offering
                </label>
                <select
                    id="filter-offering"
                    v-model="subjectOfferingId"
                    class="mt-1 w-96 rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="">All my classes</option>
                    <option
                        v-for="context in contexts"
                        :key="context.subjectOfferingId"
                        :value="context.subjectOfferingId"
                    >
                        {{ context.subjectCode }} · {{ context.subjectName }} —
                        {{ context.gradeLevelName }}, {{ context.campusName }}
                    </option>
                </select>
            </div>

            <h2 class="mt-8 text-sm font-semibold text-slate-700">Content</h2>

            <EmptyState
                v-if="content.length === 0"
                class="mt-2"
                title="No learning content yet"
                description="Choose a Subject Offering to add the first resource for your Sections."
            />

            <table v-else class="mt-2 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2">#</th>
                        <th class="py-2">Title</th>
                        <th class="py-2">For</th>
                        <th class="py-2">Status</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in content" :key="row.id" class="border-b border-slate-100">
                        <td class="py-2">{{ row.sequence }}</td>
                        <td class="py-2 font-medium">
                            {{ row.title }}
                            <span v-if="!row.mine" class="ml-1 text-xs text-slate-500">
                                (read only)
                            </span>
                        </td>
                        <td class="py-2">{{ audienceLabel(row) }}</td>
                        <td class="py-2 capitalize">{{ row.status }}</td>
                        <td class="py-2 text-right">
                            <template v-if="row.canEdit">
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
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>

            <form
                v-if="editingId !== null"
                class="mt-6 rounded border border-slate-300 p-4"
                @submit.prevent="submitEdit"
            >
                <h3 class="text-sm font-semibold">Edit content</h3>
                <div class="mt-3 flex flex-wrap gap-3">
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
                <textarea
                    v-model="editForm.description"
                    rows="3"
                    placeholder="Description / link / note"
                    class="mt-3 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                ></textarea>
                <p v-if="editForm.errors.title" class="mt-2 text-sm text-red-700">
                    {{ editForm.errors.title }}
                </p>
            </form>

            <form
                v-if="selectedContext"
                class="mt-6 rounded border border-slate-300 p-4"
                @submit.prevent="submitCreate"
            >
                <h3 class="text-sm font-semibold">Add content for my Sections</h3>
                <fieldset class="mt-3">
                    <legend class="text-sm text-slate-600">For Sections</legend>
                    <label
                        v-for="section in selectedContext.sections"
                        :key="section.id"
                        class="mr-4 inline-flex items-center gap-1 text-sm"
                    >
                        <input
                            v-model="createForm.audience_section_ids"
                            type="checkbox"
                            :value="section.id"
                        />
                        {{ section.code }}
                    </label>
                </fieldset>
                <div class="mt-3 flex flex-wrap gap-3">
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
                        Add content
                    </button>
                </div>
                <textarea
                    v-model="createForm.description"
                    rows="3"
                    placeholder="Description / link / note"
                    class="mt-3 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                ></textarea>
                <p v-if="createForm.errors.title" class="mt-2 text-sm text-red-700">
                    {{ createForm.errors.title }}
                </p>
                <p v-if="createForm.errors.audience_section_ids" class="mt-2 text-sm text-red-700">
                    {{ createForm.errors.audience_section_ids }}
                </p>
            </form>
        </template>
    </main>
</template>
