<script setup lang="ts">
// SWC.2 (ADR 0073 section 10): the Sections of one Academic Year -- list,
// create, edit and deactivate/reactivate over SectionAdministrationService.
// A Section belongs to exactly one Year (rule 66); its code is unique per
// (Year, Campus, Grade level); capacity is advisory. Enrollment and the
// timetable select these Sections.
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import ErrorSummary from '../../../Components/Form/ErrorSummary.vue';
import SelectField from '../../../Components/Form/SelectField.vue';
import TextField from '../../../Components/Form/TextField.vue';
import Pagination from '../../../Components/Pagination.vue';

interface Section {
    id: string;
    name: string;
    code: string;
    capacity: number | null;
    status: string;
    // SIS 11.H1: stale-form token, sent back with every edit.
    version: string;
    campus: { id: string; name: string | null };
    gradeLevel: { id: string; name: string | null };
    // SIS 11.3 (OD-SIS-6): strength against advisory capacity (whole-School
    // enrollments.view only; null otherwise or for a closed year's history).
    occupancy: {
        capacity: number | null;
        strength: number | null;
        asOf: string | null;
        state: string;
        label: string;
    } | null;
}

interface Year {
    id: string;
    name: string;
    code: string;
    status: string;
}

const props = defineProps<{
    academicYears: Year[];
    selectedYearId: string | null;
    sections: {
        data: Section[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    } | null;
    campuses: Array<{ id: string; name: string; code: string }>;
    gradeLevels: Array<{ id: string; name: string; code: string }>;
    canManage: boolean;
    canSeeStrength: boolean;
}>();

const yearOptions = computed(() =>
    props.academicYears.map((y) => ({ value: y.id, label: `${y.name} (${y.status})` })),
);
const campusOptions = computed(() => props.campuses.map((c) => ({ value: c.id, label: c.name })));
const gradeOptions = computed(() => props.gradeLevels.map((g) => ({ value: g.id, label: g.name })));
const selectedYear = ref(props.selectedYearId);
// SIS 5.2: a closed year keeps its Sections but takes no new one (the service refuses).
const selectedYearClosed = computed(
    () => props.academicYears.find((y) => y.id === props.selectedYearId)?.status === 'closed',
);
const yearName = computed(
    () => props.academicYears.find((y) => y.id === props.selectedYearId)?.name ?? '',
);
function changeYear() {
    router.get(
        '/app/school-setup/sections',
        { academic_year_id: selectedYear.value },
        { preserveState: false },
    );
}

const create = useForm<{
    campus_id: string | null;
    grade_level_id: string | null;
    name: string;
    code: string;
    capacity: number | null;
}>({
    campus_id: null,
    grade_level_id: null,
    name: '',
    code: '',
    capacity: null,
});
function submitCreate() {
    if (!props.selectedYearId) return;
    create.post(`/app/school-setup/academic-years/${props.selectedYearId}/sections`, {
        preserveScroll: true,
        onSuccess: () => create.reset('name', 'code', 'capacity'),
    });
}

const editing = ref<Section | null>(null);
const edit = useForm<{ name: string; code: string; capacity: number | null; version: string }>({
    name: '',
    code: '',
    capacity: null,
    version: '',
});
function startEdit(section: Section) {
    editing.value = section;
    edit.clearErrors();
    edit.name = section.name;
    edit.code = section.code;
    edit.capacity = section.capacity;
    edit.version = section.version;
    nextTick(() => document.getElementById('edit-section-name')?.focus());
}
// SIS 11.3: capacity is advisory -- it may be set below the current strength,
// with a plain warning before saving (the save is not refused).
const capacityHint = computed(() => {
    const strength = editing.value?.occupancy?.strength ?? null;
    const proposed =
        edit.capacity === null || `${edit.capacity}` === '' ? null : Number(edit.capacity);
    if (strength !== null && proposed !== null && proposed >= 1 && proposed < strength) {
        return `${editing.value?.gradeLevel.name} · ${editing.value?.name} currently has ${strength} Students. A capacity of ${proposed} is allowed because capacity is advisory; the Section will show as over advisory capacity by ${strength - proposed}.`;
    }
    return 'Advisory: a full Section still accepts placements. Leave empty if not configured.';
});
function submitEdit() {
    if (!editing.value) return;
    edit.put(`/app/school-setup/sections/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

const statusForm = useForm({ status: '', version: '' });
const pending = ref<Section | null>(null);
const dialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
function askDeactivate(section: Section) {
    pending.value = section;
    dialog.value?.open();
}
function setStatus(section: Section, status: 'active' | 'inactive') {
    statusForm.status = status;
    statusForm.version = section.version;
    statusForm.put(`/app/school-setup/sections/${section.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Sections" />
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>
        <h1 class="mt-2 text-xl font-semibold">Sections</h1>

        <p v-if="props.academicYears.length === 0" class="mt-4 text-sm text-slate-600">
            Sections belong to an Academic Year. Create an Academic Year first.
        </p>
        <template v-else>
            <form class="mt-4 flex items-end gap-3" @submit.prevent="changeYear">
                <div class="w-72">
                    <SelectField
                        id="sections-year"
                        v-model="selectedYear"
                        label="Academic year"
                        :options="yearOptions"
                    />
                </div>
                <button type="submit" class="rounded border border-slate-300 px-3 py-2">
                    Show sections
                </button>
            </form>

            <ErrorSummary :errors="statusForm.errors" :fields="['status']" />

            <p
                v-if="!props.sections || props.sections.data.length === 0"
                class="mt-4 text-sm text-slate-600"
            >
                {{ yearName }} has no Section yet.
            </p>
            <template v-else>
                <div class="relative overflow-x-auto mt-4">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">
                            Sections of
                            {{
                                yearName
                            }}
                        </caption>
                        <thead class="border-b border-slate-300 text-slate-600">
                            <tr>
                                <th scope="col" class="pr-3 py-2">Name</th>
                                <th scope="col" class="pr-3 py-2">Code</th>
                                <th scope="col" class="pr-3 py-2">Grade level</th>
                                <th scope="col" class="pr-3 py-2">Campus</th>
                                <th scope="col" class="pr-3 py-2">Capacity</th>
                                <th v-if="props.canSeeStrength" scope="col" class="pr-3 py-2">
                                    Students today
                                </th>
                                <th scope="col" class="pr-3 py-2">Status</th>
                                <th v-if="props.canManage" scope="col" class="pr-3 py-2">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr v-for="section in props.sections.data" :key="section.id">
                                <td class="pr-3 py-2 font-medium">{{ section.name }}</td>
                                <td class="pr-3 py-2">{{ section.code }}</td>
                                <td class="pr-3 py-2">{{ section.gradeLevel.name }}</td>
                                <td class="pr-3 py-2">{{ section.campus.name }}</td>
                                <td class="pr-3 py-2">
                                    {{ section.capacity ?? 'Not configured' }}
                                </td>
                                <td v-if="props.canSeeStrength" class="pr-3 py-2">
                                    {{ section.occupancy?.label ?? '—' }}
                                </td>
                                <td class="pr-3 py-2">
                                    {{ section.status === 'active' ? 'Active' : 'Inactive' }}
                                </td>
                                <td v-if="props.canManage" class="pr-3 space-x-3 py-2 text-right">
                                    <button
                                        type="button"
                                        class="underline"
                                        @click="startEdit(section)"
                                    >
                                        Edit<span class="sr-only"> {{ section.name }}</span>
                                    </button>
                                    <button
                                        v-if="section.status === 'active'"
                                        type="button"
                                        class="text-red-700 underline"
                                        @click="askDeactivate(section)"
                                    >
                                        Deactivate<span class="sr-only"> {{ section.name }}</span>
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        class="underline"
                                        :disabled="statusForm.processing"
                                        @click="setStatus(section, 'active')"
                                    >
                                        Reactivate<span class="sr-only"> {{ section.name }}</span>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :links="props.sections.links" />
            </template>

            <section
                v-if="editing"
                class="mt-6 rounded border border-slate-300 p-4"
                aria-labelledby="edit-section-heading"
            >
                <h2 id="edit-section-heading" class="font-semibold">Edit {{ editing.name }}</h2>
                <p class="mt-1 text-sm text-slate-600">
                    {{ editing.gradeLevel.name }} · {{ editing.campus.name }} — the Year, Campus and
                    Grade level of a Section never change.
                </p>
                <form class="mt-3 grid gap-3 sm:grid-cols-3" @submit.prevent="submitEdit">
                    <TextField
                        id="edit-section-name"
                        v-model="edit.name"
                        label="Name"
                        required
                        :error="edit.errors.name"
                    />
                    <TextField
                        id="edit-section-code"
                        v-model="edit.code"
                        label="Code"
                        required
                        :maxlength="32"
                        :error="edit.errors.code"
                    />
                    <TextField
                        v-if="!selectedYearClosed"
                        id="edit-section-capacity"
                        v-model="edit.capacity"
                        label="Advisory capacity"
                        type="number"
                        :min="1"
                        :hint="capacityHint"
                        :error="edit.errors.capacity"
                    />
                    <p v-else class="text-sm text-slate-600">
                        Capacity {{ editing.capacity ?? 'not configured' }} — this Section belongs
                        to a closed year, so its capacity is history and cannot be changed.
                    </p>
                    <div class="flex gap-3 sm:col-span-3">
                        <button
                            type="submit"
                            :disabled="edit.processing"
                            class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                        >
                            Save
                        </button>
                        <button
                            type="button"
                            class="rounded border border-slate-300 px-3 py-2"
                            @click="editing = null"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </section>

            <p
                v-if="props.canManage && props.selectedYearId && selectedYearClosed"
                class="mt-8 text-sm text-slate-600"
            >
                {{ yearName }} is closed: it keeps its Sections but takes no new ones.
            </p>
            <section
                v-else-if="props.canManage && props.selectedYearId"
                class="mt-8"
                aria-labelledby="add-section-heading"
            >
                <h2 id="add-section-heading" class="font-semibold">
                    Add a Section to {{ yearName }}
                </h2>
                <p
                    v-if="props.campuses.length === 0 || props.gradeLevels.length === 0"
                    class="mt-2 text-sm text-slate-600"
                >
                    A Section needs an active Campus and an active Grade level.
                </p>
                <form v-else class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitCreate">
                    <SelectField
                        id="section-grade"
                        v-model="create.grade_level_id"
                        label="Grade level"
                        :options="gradeOptions"
                        placeholder="Select a Grade level"
                        required
                        :error="create.errors.grade_level_id"
                    />
                    <SelectField
                        id="section-campus"
                        v-model="create.campus_id"
                        label="Campus"
                        :options="campusOptions"
                        placeholder="Select a Campus"
                        required
                        :error="create.errors.campus_id"
                    />
                    <TextField
                        id="section-name"
                        v-model="create.name"
                        label="Name"
                        required
                        :error="create.errors.name"
                    />
                    <TextField
                        id="section-code"
                        v-model="create.code"
                        label="Code"
                        required
                        :maxlength="32"
                        hint="Unique for the Grade level and Campus in this Year."
                        :error="create.errors.code"
                    />
                    <TextField
                        id="section-capacity"
                        v-model="create.capacity"
                        label="Capacity"
                        type="number"
                        :min="1"
                        hint="Advisory only."
                        :error="create.errors.capacity"
                    />
                    <div class="sm:col-span-2">
                        <button
                            type="submit"
                            :disabled="create.processing"
                            class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                        >
                            Add Section
                        </button>
                    </div>
                </form>
            </section>
        </template>

        <ConfirmDialog
            ref="dialog"
            :title="`Deactivate ${pending?.name ?? 'Section'}?`"
            confirm-label="Deactivate"
            @confirm="pending && setStatus(pending, 'inactive')"
        >
            It stays in the records, including its enrollment history, and can be reactivated.
        </ConfirmDialog>
    </main>
</template>
