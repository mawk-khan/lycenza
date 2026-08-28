<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

interface OfferingRef {
    id: string;
    subject: { id: string; name: string; code: string } | null;
    gradeLevel: { id: string; name: string } | null;
    campus: { id: string; name: string } | null;
    status: 'active' | 'inactive';
    electiveGroup: { id: string; name: string } | null;
}

interface SubjectMapping {
    id: string;
    sourceSubjectOffering: OfferingRef;
    state: 'mapped' | 'omit';
    targetSubjectOffering: OfferingRef | null;
}

interface Props {
    rolloverId: string;
    subjectMappings: SubjectMapping[];
    unmappedSourceSubjectOfferings: OfferingRef[];
    sourceSubjectOfferings: OfferingRef[];
    targetSubjectOfferings: OfferingRef[];
    configurable: boolean;
    canManage: boolean;
}

const props = defineProps<Props>();

// Sentinel used by the target <select> to represent "Omit next year" --
// never confused with an actual (always-uuid) target Offering id, and
// never confused with "Not configured" (which is simply the ABSENCE of
// a row -- there is no select value for it at all, this checkpoint's
// brief, section 21).
const OMIT_VALUE = '__omit__';

function offeringLabel(o: OfferingRef): string {
    const parts = [
        o.subject ? `${o.subject.name} (${o.subject.code})` : null,
        o.gradeLevel?.name ?? null,
        o.campus?.name ?? null,
    ].filter(Boolean);
    let label = parts.join(' · ');
    if (o.electiveGroup) label += ` [${o.electiveGroup.name}]`;
    if (o.status === 'inactive') label += ' (inactive)';

    return label;
}

const mappedSourceIds = computed(
    () => new Set(props.subjectMappings.map((m) => m.sourceSubjectOffering.id)),
);

// Any elective source Offering not already configured is eligible for
// the manual "Add mapping" form -- a superset of
// `unmappedSourceSubjectOfferings` (which only lists Offerings with
// CURRENT eligible participation); this covers configuring ahead of a
// Plan's first dry-run, before any Item/participation exists yet.
const addableSourceOfferings = computed(() =>
    props.sourceSubjectOfferings.filter((o) => !mappedSourceIds.value.has(o.id)),
);

// --- Inline configure/edit forms, keyed per source Offering id --------

const rowForms = reactive<Record<string, ReturnType<typeof useForm<{ target_subject_offering_id: string | null }>>>>({});
const editingSourceId = ref<string | null>(null);

function rowForm(sourceId: string, initialTargetId: string | null | typeof OMIT_VALUE) {
    if (!rowForms[sourceId]) {
        rowForms[sourceId] = useForm({
            target_subject_offering_id: initialTargetId === OMIT_VALUE ? null : initialTargetId,
        });
    }

    return rowForms[sourceId];
}

function selectValue(sourceId: string): string {
    const form = rowForms[sourceId];
    if (!form) return '';

    return form.target_subject_offering_id === null ? OMIT_VALUE : form.target_subject_offering_id;
}

function setSelectValue(sourceId: string, value: string): void {
    const form = rowForm(sourceId, null);
    form.target_subject_offering_id = value === OMIT_VALUE || value === '' ? null : value;
}

function startEdit(mapping: SubjectMapping): void {
    editingSourceId.value = mapping.sourceSubjectOffering.id;
    rowForms[mapping.sourceSubjectOffering.id] = useForm({
        target_subject_offering_id: mapping.state === 'omit' ? null : mapping.targetSubjectOffering!.id,
    });
    if (mapping.state === 'omit') {
        // Force the <select> to the OMIT sentinel rather than an empty
        // string -- "not configured" has no select state at all, so an
        // empty string here would be visually indistinguishable from it.
    }
}

function cancelEdit(): void {
    editingSourceId.value = null;
}

function submitConfigure(sourceId: string, selectValueChosen: string): void {
    const form = rowForm(sourceId, null);
    form.target_subject_offering_id = selectValueChosen === OMIT_VALUE || selectValueChosen === '' ? null : selectValueChosen;
    form.put(`/app/enrollment-rollovers/${props.rolloverId}/subject-mappings/${sourceId}`, {
        onSuccess: () => {
            editingSourceId.value = null;
        },
    });
}

const removingSourceId = ref<string | null>(null);
function resetToUnconfigured(sourceId: string): void {
    removingSourceId.value = sourceId;
    useForm({}).delete(`/app/enrollment-rollovers/${props.rolloverId}/subject-mappings/${sourceId}`, {
        onFinish: () => {
            removingSourceId.value = null;
        },
    });
}

// --- Add mapping (manual) form -----------------------------------------

const showAddForm = ref(false);
const addForm = useForm<{ source_subject_offering_id: string; target_subject_offering_id: string }>({
    source_subject_offering_id: '',
    target_subject_offering_id: '',
});

function submitAdd(): void {
    if (!addForm.source_subject_offering_id) return;

    const sourceId = addForm.source_subject_offering_id;
    const targetValue = addForm.target_subject_offering_id;

    useForm({ target_subject_offering_id: targetValue === OMIT_VALUE || targetValue === '' ? null : targetValue }).put(
        `/app/enrollment-rollovers/${props.rolloverId}/subject-mappings/${sourceId}`,
        {
            onSuccess: () => {
                addForm.reset();
                showAddForm.value = false;
            },
        },
    );
}
</script>

<template>
    <section class="mt-8">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Elective subject mappings</h2>
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
            Only EXPLICIT electives need a mapping -- required subjects are always derived
            automatically and never listed here. A source elective with no decision (Not
            configured) blocks validation until you either map it forward or explicitly mark it
            Omit next year.
        </p>

        <p v-if="!configurable" class="mt-2 text-sm text-amber-700">
            This Plan is no longer configurable (it is executing or has reached a terminal status).
        </p>

        <!-- Configured mappings -->
        <div
            v-if="subjectMappings.length === 0"
            class="mt-4 rounded border border-dashed border-slate-300 p-4 text-sm text-slate-500"
        >
            No elective subject mappings configured yet.
        </div>
        <ul v-else class="mt-4 space-y-2">
            <li
                v-for="m in subjectMappings"
                :key="m.id"
                class="rounded border border-slate-200 p-3 text-sm"
            >
                <template v-if="editingSourceId !== m.sourceSubjectOffering.id">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <span class="font-medium">{{ offeringLabel(m.sourceSubjectOffering) }}</span>
                            <span class="mx-2 text-slate-400">→</span>
                            <span v-if="m.state === 'omit'" class="italic text-slate-500"
                                >Omit next year</span
                            >
                            <span v-else class="font-medium">{{
                                offeringLabel(m.targetSubjectOffering!)
                            }}</span>
                        </div>
                        <div v-if="canManage && configurable" class="flex items-center gap-3">
                            <button type="button" class="text-sm underline" @click="startEdit(m)">
                                Edit
                            </button>
                            <button
                                type="button"
                                :disabled="removingSourceId === m.sourceSubjectOffering.id"
                                class="text-sm text-red-700 underline disabled:opacity-50"
                                @click="resetToUnconfigured(m.sourceSubjectOffering.id)"
                            >
                                Reset to unconfigured
                            </button>
                        </div>
                    </div>
                </template>

                <form
                    v-else
                    class="space-y-3"
                    @submit.prevent="submitConfigure(m.sourceSubjectOffering.id, selectValue(m.sourceSubjectOffering.id))"
                >
                    <p class="text-slate-600">
                        Source: <strong>{{ offeringLabel(m.sourceSubjectOffering) }}</strong>
                    </p>
                    <div>
                        <label
                            class="block text-xs font-medium text-slate-700"
                            :for="`edit-target-${m.sourceSubjectOffering.id}`"
                            >Decision</label
                        >
                        <select
                            :id="`edit-target-${m.sourceSubjectOffering.id}`"
                            :value="selectValue(m.sourceSubjectOffering.id)"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                            @change="setSelectValue(m.sourceSubjectOffering.id, ($event.target as HTMLSelectElement).value)"
                        >
                            <option :value="OMIT_VALUE">Omit next year (do not carry forward)</option>
                            <option v-for="t in targetSubjectOfferings" :key="t.id" :value="t.id">
                                Map to {{ offeringLabel(t) }}
                            </option>
                        </select>
                    </div>
                    <p
                        v-if="rowForms[m.sourceSubjectOffering.id]?.errors.target_subject_offering_id"
                        class="text-sm text-red-600"
                    >
                        {{ rowForms[m.sourceSubjectOffering.id].errors.target_subject_offering_id }}
                    </p>
                    <div class="flex items-center gap-3">
                        <button
                            type="submit"
                            :disabled="rowForms[m.sourceSubjectOffering.id]?.processing"
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

        <!-- Needs configuration -->
        <template v-if="unmappedSourceSubjectOfferings.length > 0">
            <h3 class="mt-6 text-xs font-semibold uppercase tracking-wide text-amber-700">
                Needs configuration
            </h3>
            <p class="mt-1 text-xs text-slate-500">
                These electives have active Students in this Plan but no mapping yet -- dry-run
                will block them as "missing subject mapping" until you decide.
            </p>
            <ul class="mt-2 space-y-2">
                <li
                    v-for="o in unmappedSourceSubjectOfferings"
                    :key="o.id"
                    class="rounded border border-amber-200 bg-amber-50 p-3 text-sm"
                >
                    <template v-if="editingSourceId !== o.id">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium">{{ offeringLabel(o) }}</span>
                            <button
                                v-if="canManage && configurable"
                                type="button"
                                class="text-sm underline"
                                @click="editingSourceId = o.id; rowForm(o.id, OMIT_VALUE)"
                            >
                                Configure
                            </button>
                        </div>
                    </template>
                    <form
                        v-else
                        class="space-y-3"
                        @submit.prevent="submitConfigure(o.id, selectValue(o.id))"
                    >
                        <p class="text-slate-600">
                            Source: <strong>{{ offeringLabel(o) }}</strong>
                        </p>
                        <select
                            :value="selectValue(o.id)"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                            @change="setSelectValue(o.id, ($event.target as HTMLSelectElement).value)"
                        >
                            <option :value="OMIT_VALUE">Omit next year (do not carry forward)</option>
                            <option v-for="t in targetSubjectOfferings" :key="t.id" :value="t.id">
                                Map to {{ offeringLabel(t) }}
                            </option>
                        </select>
                        <p v-if="rowForms[o.id]?.errors.target_subject_offering_id" class="text-sm text-red-600">
                            {{ rowForms[o.id].errors.target_subject_offering_id }}
                        </p>
                        <div class="flex items-center gap-3">
                            <button
                                type="submit"
                                :disabled="rowForms[o.id]?.processing"
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
        </template>

        <!-- Add mapping (manual) -->
        <form
            v-if="showAddForm"
            class="mt-4 space-y-3 rounded border border-slate-200 p-4"
            @submit.prevent="submitAdd"
        >
            <h3 class="text-sm font-medium text-slate-700">Add mapping</h3>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-slate-700" for="source_subject_offering_id"
                        >Source elective</label
                    >
                    <select
                        id="source_subject_offering_id"
                        v-model="addForm.source_subject_offering_id"
                        required
                        :aria-invalid="!!addForm.errors.source_subject_offering_id"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                    >
                        <option value="" disabled>Select…</option>
                        <option v-for="o in addableSourceOfferings" :key="o.id" :value="o.id">
                            {{ offeringLabel(o) }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700" for="target_subject_offering_id"
                        >Decision</label
                    >
                    <select
                        id="target_subject_offering_id"
                        v-model="addForm.target_subject_offering_id"
                        required
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                    >
                        <option value="" disabled>Select…</option>
                        <option :value="OMIT_VALUE">Omit next year (do not carry forward)</option>
                        <option v-for="t in targetSubjectOfferings" :key="t.id" :value="t.id">
                            Map to {{ offeringLabel(t) }}
                        </option>
                    </select>
                </div>
            </div>
            <p v-if="addForm.errors.target_subject_offering_id" class="text-sm text-red-600">
                {{ addForm.errors.target_subject_offering_id }}
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
