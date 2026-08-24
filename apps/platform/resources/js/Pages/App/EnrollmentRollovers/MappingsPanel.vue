<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Ref {
    id: string;
    name: string;
}

interface Mapping {
    id: string;
    sourceGradeLevel: Ref;
    sourceSection: Ref | null;
    targetGradeLevel: Ref;
    targetSection: Ref | null;
    isRepeat: boolean;
}

interface SectionOption {
    id: string;
    label: string;
    gradeLevelId: string;
}

interface Props {
    rolloverId: string;
    mappings: Mapping[];
    configurable: boolean;
    canManage: boolean;
    gradeLevels: Ref[];
    sourceSections: SectionOption[];
    targetSections: SectionOption[];
}

const props = defineProps<Props>();

const showAddForm = ref(false);
const editingMappingId = ref<string | null>(null);

const addForm = useForm({
    source_grade_level_id: '',
    source_section_id: '',
    target_grade_level_id: '',
    target_section_id: '',
});

function submitAdd(): void {
    addForm.post(`/app/enrollment-rollovers/${props.rolloverId}/mappings`, {
        onSuccess: () => {
            addForm.reset();
            showAddForm.value = false;
        },
    });
}

const editForm = useForm({
    target_grade_level_id: '',
    target_section_id: '',
});

function startEdit(mapping: Mapping): void {
    editingMappingId.value = mapping.id;
    editForm.target_grade_level_id = mapping.targetGradeLevel.id;
    editForm.target_section_id = mapping.targetSection?.id ?? '';
}

function cancelEdit(): void {
    editingMappingId.value = null;
    editForm.clearErrors();
}

function submitEdit(mappingId: string): void {
    editForm.patch(`/app/enrollment-rollovers/${props.rolloverId}/mappings/${mappingId}`, {
        onSuccess: () => {
            editingMappingId.value = null;
        },
    });
}

// Convenience grouping only -- selection remains fully explicit, never
// automatic (this checkpoint's brief, section 21): a Section option is
// merely SORTED by whether its Grade matches the currently-selected
// Grade, never pre-selected or filtered out.
const sourceSectionsForSelectedGrade = computed(() =>
    [...props.sourceSections].sort((a, b) =>
        a.gradeLevelId === addForm.source_grade_level_id
            ? -1
            : b.gradeLevelId === addForm.source_grade_level_id
              ? 1
              : 0,
    ),
);
</script>

<template>
    <section class="mt-8">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Grade &amp; Section mappings</h2>
            <button
                v-if="canManage && configurable && !showAddForm"
                type="button"
                class="text-sm underline"
                @click="showAddForm = true"
            >
                Add mapping
            </button>
        </div>
        <p class="mt-1 text-sm text-slate-500">
            A Grade-level default applies to every Student in that source Grade unless a
            Section-specific override exists. The target Section is always authoritative -- it is
            never inferred from a Section's name or code.
        </p>

        <p v-if="!configurable" class="mt-2 text-sm text-amber-700">
            This Plan is no longer configurable (it is executing or has reached a terminal status).
        </p>

        <div
            v-if="mappings.length === 0"
            class="mt-4 rounded border border-dashed border-slate-300 p-4 text-sm text-slate-500"
        >
            No mappings configured yet.
        </div>

        <ul v-else class="mt-4 space-y-2">
            <li
                v-for="m in mappings"
                :key="m.id"
                class="rounded border border-slate-200 p-3 text-sm"
            >
                <template v-if="editingMappingId !== m.id">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <span class="font-medium">{{ m.sourceGradeLevel.name }}</span>
                            <span v-if="m.sourceSection" class="text-slate-500">
                                · Section {{ m.sourceSection.name }} only</span
                            >
                            <span v-else class="text-slate-500"> · Grade default</span>
                            <span class="mx-2 text-slate-400">→</span>
                            <span class="font-medium">{{ m.targetGradeLevel.name }}</span>
                            <span v-if="m.targetSection">
                                · Section {{ m.targetSection.name }}</span
                            >
                            <span v-if="m.isRepeat" class="ml-2 text-xs text-slate-500"
                                >(repeat)</span
                            >
                        </div>
                        <button
                            v-if="canManage && configurable"
                            type="button"
                            class="text-sm underline"
                            @click="startEdit(m)"
                        >
                            Edit target
                        </button>
                    </div>
                </template>

                <form v-else class="space-y-3" @submit.prevent="submitEdit(m.id)">
                    <p class="text-slate-600">
                        Source: <strong>{{ m.sourceGradeLevel.name }}</strong>
                        <span v-if="m.sourceSection"> · Section {{ m.sourceSection.name }}</span>
                        <span v-else> (Grade default)</span> -- unchanged.
                    </p>
                    <div>
                        <label
                            class="block text-xs font-medium text-slate-700"
                            :for="`edit-target-grade-${m.id}`"
                            >Target Grade</label
                        >
                        <select
                            :id="`edit-target-grade-${m.id}`"
                            v-model="editForm.target_grade_level_id"
                            required
                            :aria-invalid="!!editForm.errors.target_grade_level_id"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                        >
                            <option v-for="g in gradeLevels" :key="g.id" :value="g.id">
                                {{ g.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label
                            class="block text-xs font-medium text-slate-700"
                            :for="`edit-target-section-${m.id}`"
                            >Target Section (optional default)</label
                        >
                        <select
                            :id="`edit-target-section-${m.id}`"
                            v-model="editForm.target_section_id"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                        >
                            <option value="">No default Section</option>
                            <option v-for="s in targetSections" :key="s.id" :value="s.id">
                                {{ s.label }}
                            </option>
                        </select>
                    </div>
                    <p v-if="editForm.errors.target_grade_level_id" class="text-sm text-red-600">
                        {{ editForm.errors.target_grade_level_id }}
                    </p>
                    <div class="flex items-center gap-3">
                        <button
                            type="submit"
                            :disabled="editForm.processing"
                            class="rounded bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                        >
                            Save
                        </button>
                        <button type="button" class="text-xs underline" @click="cancelEdit">
                            Cancel
                        </button>
                    </div>
                </form>
            </li>
        </ul>

        <form
            v-if="showAddForm"
            class="mt-4 space-y-3 rounded border border-slate-200 p-4"
            @submit.prevent="submitAdd"
        >
            <h3 class="text-sm font-medium text-slate-700">Add mapping</h3>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label
                        class="block text-xs font-medium text-slate-700"
                        for="source_grade_level_id"
                        >Source Grade</label
                    >
                    <select
                        id="source_grade_level_id"
                        v-model="addForm.source_grade_level_id"
                        required
                        :aria-invalid="!!addForm.errors.source_grade_level_id"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                    >
                        <option value="" disabled>Select…</option>
                        <option v-for="g in gradeLevels" :key="g.id" :value="g.id">
                            {{ g.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700" for="source_section_id"
                        >Source Section (leave blank for a Grade-level default)</label
                    >
                    <select
                        id="source_section_id"
                        v-model="addForm.source_section_id"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                    >
                        <option value="">Grade-level default</option>
                        <option
                            v-for="s in sourceSectionsForSelectedGrade"
                            :key="s.id"
                            :value="s.id"
                        >
                            {{ s.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label
                        class="block text-xs font-medium text-slate-700"
                        for="target_grade_level_id"
                        >Target Grade</label
                    >
                    <select
                        id="target_grade_level_id"
                        v-model="addForm.target_grade_level_id"
                        required
                        :aria-invalid="!!addForm.errors.target_grade_level_id"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                    >
                        <option value="" disabled>Select…</option>
                        <option v-for="g in gradeLevels" :key="g.id" :value="g.id">
                            {{ g.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700" for="target_section_id"
                        >Target Section (optional default)</label
                    >
                    <select
                        id="target_section_id"
                        v-model="addForm.target_section_id"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                    >
                        <option value="">No default Section</option>
                        <option v-for="s in targetSections" :key="s.id" :value="s.id">
                            {{ s.label }}
                        </option>
                    </select>
                </div>
            </div>
            <p v-if="addForm.errors.source_grade_level_id" class="text-sm text-red-600">
                {{ addForm.errors.source_grade_level_id }}
            </p>
            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="addForm.processing"
                    class="rounded bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    Add mapping
                </button>
                <button type="button" class="text-xs underline" @click="showAddForm = false">
                    Cancel
                </button>
            </div>
        </form>
    </section>
</template>
