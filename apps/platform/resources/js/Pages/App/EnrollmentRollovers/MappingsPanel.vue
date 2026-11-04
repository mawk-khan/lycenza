<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

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
    // SIS 11.3 (OD-SIS-6): advisory occupancy, e.g. "28 / 30 · 2 seats available" (information only).
    occupancy?: string | null;
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

// UAT-C1 (UAT-YR-04): a mapping's Section must be a Section of that side's
// Grade (the service refuses anything else), so each Section list offers only
// the selected Grade's Sections, and changing the Grade clears a Section that
// no longer belongs to it. Selection itself stays explicit, never automatic.
function sectionsOfGrade(sections: SectionOption[], gradeLevelId: string): SectionOption[] {
    return gradeLevelId === '' ? [] : sections.filter((s) => s.gradeLevelId === gradeLevelId);
}

const addSourceSections = computed(() =>
    sectionsOfGrade(props.sourceSections, addForm.source_grade_level_id),
);
const addTargetSections = computed(() =>
    sectionsOfGrade(props.targetSections, addForm.target_grade_level_id),
);
const editTargetSections = computed(() =>
    sectionsOfGrade(props.targetSections, editForm.target_grade_level_id),
);

function clearIfStale(selected: string, options: SectionOption[]): string {
    return options.some((s) => s.id === selected) ? selected : '';
}

watch(addSourceSections, (options) => {
    addForm.source_section_id = clearIfStale(addForm.source_section_id, options);
});
watch(addTargetSections, (options) => {
    addForm.target_section_id = clearIfStale(addForm.target_section_id, options);
});
watch(editTargetSections, (options) => {
    editForm.target_section_id = clearIfStale(editForm.target_section_id, options);
});
</script>

<template>
    <section class="mt-8">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-ink">Grade &amp; Section mappings</h2>
            <button
                v-if="canManage && configurable && !showAddForm"
                type="button"
                class="text-sm underline"
                @click="showAddForm = true"
            >
                Add mapping
            </button>
        </div>
        <p class="mt-1 text-sm text-ink-muted">
            A Grade-level default applies to every Student in that source Grade unless a
            Section-specific override exists. The target Section is always authoritative -- it is
            never inferred from a Section's name or code.
        </p>

        <p v-if="!configurable" class="mt-2 text-sm text-ink">
            This Plan is no longer configurable (it is executing or has reached a terminal status).
        </p>

        <div
            v-if="mappings.length === 0"
            class="mt-4 rounded-xs border border-dashed border-control-line p-4 text-sm text-ink-muted"
        >
            No mappings configured yet.
        </div>

        <ul v-else class="mt-4 space-y-2">
            <li v-for="m in mappings" :key="m.id" class="rounded-xs border border-line p-3 text-sm">
                <template v-if="editingMappingId !== m.id">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <span class="font-medium">{{ m.sourceGradeLevel.name }}</span>
                            <span v-if="m.sourceSection" class="text-ink-muted">
                                · Section {{ m.sourceSection.name }} only</span
                            >
                            <span v-else class="text-ink-muted"> · Grade default</span>
                            <span class="mx-2 text-ink-muted">→</span>
                            <span class="font-medium">{{ m.targetGradeLevel.name }}</span>
                            <span v-if="m.targetSection">
                                · Section {{ m.targetSection.name }}</span
                            >
                            <span v-if="m.isRepeat" class="ml-2 text-xs text-ink-muted"
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
                    <p class="text-ink-muted">
                        Source: <strong>{{ m.sourceGradeLevel.name }}</strong>
                        <span v-if="m.sourceSection"> · Section {{ m.sourceSection.name }}</span>
                        <span v-else> (Grade default)</span> -- unchanged.
                    </p>
                    <div>
                        <label
                            class="block text-xs font-medium text-ink"
                            :for="`edit-target-grade-${m.id}`"
                            >Target Grade</label
                        >
                        <select
                            :id="`edit-target-grade-${m.id}`"
                            v-model="editForm.target_grade_level_id"
                            required
                            :aria-invalid="!!editForm.errors.target_grade_level_id"
                            class="input mt-1"
                        >
                            <option v-for="g in gradeLevels" :key="g.id" :value="g.id">
                                {{ g.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label
                            class="block text-xs font-medium text-ink"
                            :for="`edit-target-section-${m.id}`"
                            >Target Section (optional default)</label
                        >
                        <select
                            :id="`edit-target-section-${m.id}`"
                            v-model="editForm.target_section_id"
                            class="input mt-1"
                        >
                            <option value="">No default Section</option>
                            <option v-for="s in editTargetSections" :key="s.id" :value="s.id">
                                {{ s.occupancy ? `${s.label} — ${s.occupancy}` : s.label }}
                            </option>
                        </select>
                    </div>
                    <p v-if="editForm.errors.target_grade_level_id" class="text-sm text-danger">
                        {{ editForm.errors.target_grade_level_id }}
                    </p>
                    <p v-if="editForm.errors.target_section_id" class="text-sm text-danger">
                        {{ editForm.errors.target_section_id }}
                    </p>
                    <div class="flex items-center gap-3">
                        <button
                            type="submit"
                            :disabled="editForm.processing"
                            class="btn btn-primary text-xs"
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
            class="mt-4 space-y-3 rounded-xs border border-line p-4"
            @submit.prevent="submitAdd"
        >
            <h3 class="text-sm font-medium text-ink">Add mapping</h3>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-ink" for="source_grade_level_id"
                        >Source Grade</label
                    >
                    <select
                        id="source_grade_level_id"
                        v-model="addForm.source_grade_level_id"
                        required
                        :aria-invalid="!!addForm.errors.source_grade_level_id"
                        class="input mt-1"
                    >
                        <option value="" disabled>Select…</option>
                        <option v-for="g in gradeLevels" :key="g.id" :value="g.id">
                            {{ g.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-ink" for="source_section_id"
                        >Source Section (leave blank for a Grade-level default)</label
                    >
                    <select
                        id="source_section_id"
                        v-model="addForm.source_section_id"
                        class="input mt-1"
                    >
                        <option value="">Grade-level default</option>
                        <option v-for="s in addSourceSections" :key="s.id" :value="s.id">
                            {{ s.occupancy ? `${s.label} — ${s.occupancy}` : s.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-ink" for="target_grade_level_id"
                        >Target Grade</label
                    >
                    <select
                        id="target_grade_level_id"
                        v-model="addForm.target_grade_level_id"
                        required
                        :aria-invalid="!!addForm.errors.target_grade_level_id"
                        class="input mt-1"
                    >
                        <option value="" disabled>Select…</option>
                        <option v-for="g in gradeLevels" :key="g.id" :value="g.id">
                            {{ g.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-ink" for="target_section_id"
                        >Target Section (optional default)</label
                    >
                    <select
                        id="target_section_id"
                        v-model="addForm.target_section_id"
                        class="input mt-1"
                    >
                        <option value="">No default Section</option>
                        <option v-for="s in addTargetSections" :key="s.id" :value="s.id">
                            {{ s.occupancy ? `${s.label} — ${s.occupancy}` : s.label }}
                        </option>
                    </select>
                </div>
            </div>
            <p v-if="addForm.errors.source_grade_level_id" class="text-sm text-danger">
                {{ addForm.errors.source_grade_level_id }}
            </p>
            <p v-if="addForm.errors.source_section_id" class="text-sm text-danger">
                {{ addForm.errors.source_section_id }}
            </p>
            <p v-if="addForm.errors.target_section_id" class="text-sm text-danger">
                {{ addForm.errors.target_section_id }}
            </p>
            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="addForm.processing"
                    class="btn btn-primary text-xs"
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
