<script setup lang="ts">
// SWC.2 (ADR 0073 section 10): Subject list, create, edit and
// deactivate/reactivate over SubjectAdministrationService, with the fields
// the backend already had (short name, type, Department) that the Phase 0D
// form left out. School-scoped codes (rule 71); the type catalog and the
// Department choices come from the server.
import { Head, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import ErrorSummary from '../../../Components/Form/ErrorSummary.vue';
import SelectField from '../../../Components/Form/SelectField.vue';
import TextField from '../../../Components/Form/TextField.vue';

interface Subject {
    id: string;
    name: string;
    code: string;
    shortName: string | null;
    subjectType: string;
    academicDepartmentId: string | null;
    academicDepartmentName: string | null;
    status: string;
}

const props = defineProps<{
    subjects: Subject[];
    departments: Array<{ id: string; name: string; code: string }>;
    subjectTypes: string[];
    canManage: boolean;
}>();

const typeLabel = (type: string) => type.replace('_', '-').replace(/^./, (c) => c.toUpperCase());
const typeOptions = computed(() =>
    props.subjectTypes.map((t) => ({ value: t, label: typeLabel(t) })),
);
const departmentOptions = computed(() =>
    props.departments.map((d) => ({ value: d.id, label: `${d.name} (${d.code})` })),
);

interface SubjectForm {
    name: string;
    code: string;
    short_name: string;
    subject_type: string;
    academic_department_id: string | null;
}

const create = useForm<SubjectForm>({
    name: '',
    code: '',
    short_name: '',
    subject_type: 'core',
    academic_department_id: null,
});
function submitCreate() {
    create.post('/app/school-setup/subjects', {
        preserveScroll: true,
        onSuccess: () => create.reset(),
    });
}

const editing = ref<Subject | null>(null);
const edit = useForm<SubjectForm>({
    name: '',
    code: '',
    short_name: '',
    subject_type: 'core',
    academic_department_id: null,
});
function startEdit(subject: Subject) {
    editing.value = subject;
    edit.clearErrors();
    edit.name = subject.name;
    edit.code = subject.code;
    edit.short_name = subject.shortName ?? '';
    edit.subject_type = subject.subjectType;
    edit.academic_department_id = subject.academicDepartmentId;
    nextTick(() => document.getElementById('edit-subject-name')?.focus());
}
function submitEdit() {
    if (!editing.value) return;
    edit.put(`/app/school-setup/subjects/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

const statusForm = useForm({ status: '' });
const pending = ref<Subject | null>(null);
const dialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
function askDeactivate(subject: Subject) {
    pending.value = subject;
    dialog.value?.open();
}
function setStatus(subject: Subject, status: 'active' | 'inactive') {
    statusForm.status = status;
    statusForm.put(`/app/school-setup/subjects/${subject.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Subjects" />
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>
        <h1 class="mt-2 text-xl font-semibold">Subjects</h1>

        <ErrorSummary :errors="statusForm.errors" :fields="['status']" />

        <p v-if="props.subjects.length === 0" class="mt-4 text-sm text-slate-600">
            No Subject exists yet.
        </p>
        <div v-else class="relative overflow-x-auto mt-4">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Subjects
                </caption>
                <thead class="border-b border-slate-300 text-slate-600">
                    <tr>
                        <th scope="col" class="pr-3 py-2">Name</th>
                        <th scope="col" class="pr-3 py-2">Code</th>
                        <th scope="col" class="pr-3 py-2">Type</th>
                        <th scope="col" class="pr-3 py-2">Department</th>
                        <th scope="col" class="pr-3 py-2">Status</th>
                        <th v-if="props.canManage" scope="col" class="pr-3 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <tr v-for="subject in props.subjects" :key="subject.id">
                        <td class="pr-3 py-2 font-medium">
                            {{ subject.name }}
                            <span v-if="subject.shortName" class="text-slate-500"
                                >({{ subject.shortName }})</span
                            >
                        </td>
                        <td class="pr-3 py-2">{{ subject.code }}</td>
                        <td class="pr-3 py-2">{{ typeLabel(subject.subjectType) }}</td>
                        <td class="pr-3 py-2">{{ subject.academicDepartmentName ?? '—' }}</td>
                        <td class="pr-3 py-2">
                            {{ subject.status === 'active' ? 'Active' : 'Inactive' }}
                        </td>
                        <td v-if="props.canManage" class="pr-3 space-x-3 py-2 text-right">
                            <button type="button" class="underline" @click="startEdit(subject)">
                                Edit<span class="sr-only"> {{ subject.name }}</span>
                            </button>
                            <button
                                v-if="subject.status === 'active'"
                                type="button"
                                class="text-red-700 underline"
                                @click="askDeactivate(subject)"
                            >
                                Deactivate<span class="sr-only"> {{ subject.name }}</span>
                            </button>
                            <button
                                v-else
                                type="button"
                                class="underline"
                                :disabled="statusForm.processing"
                                @click="setStatus(subject, 'active')"
                            >
                                Reactivate<span class="sr-only"> {{ subject.name }}</span>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <section
            v-if="editing"
            class="mt-6 rounded border border-slate-300 p-4"
            aria-labelledby="edit-subject-heading"
        >
            <h2 id="edit-subject-heading" class="font-semibold">Edit {{ editing.name }}</h2>
            <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitEdit">
                <TextField
                    id="edit-subject-name"
                    v-model="edit.name"
                    label="Name"
                    required
                    :error="edit.errors.name"
                />
                <TextField
                    id="edit-subject-code"
                    v-model="edit.code"
                    label="Code"
                    required
                    :maxlength="32"
                    :error="edit.errors.code"
                />
                <TextField
                    id="edit-subject-short"
                    v-model="edit.short_name"
                    label="Short name"
                    :maxlength="32"
                    :error="edit.errors.short_name"
                />
                <SelectField
                    id="edit-subject-type"
                    v-model="edit.subject_type"
                    label="Type"
                    :options="typeOptions"
                    required
                    :error="edit.errors.subject_type"
                />
                <SelectField
                    id="edit-subject-department"
                    v-model="edit.academic_department_id"
                    label="Academic department"
                    :options="departmentOptions"
                    placeholder="None"
                    :error="edit.errors.academic_department_id"
                />
                <div class="flex gap-3 sm:col-span-2">
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

        <section v-if="props.canManage" class="mt-8" aria-labelledby="add-subject-heading">
            <h2 id="add-subject-heading" class="font-semibold">Add a Subject</h2>
            <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitCreate">
                <TextField
                    id="subject-name"
                    v-model="create.name"
                    label="Name"
                    required
                    :error="create.errors.name"
                />
                <TextField
                    id="subject-code"
                    v-model="create.code"
                    label="Code"
                    required
                    :maxlength="32"
                    hint="Unique in this School; letters are stored in capitals."
                    :error="create.errors.code"
                />
                <TextField
                    id="subject-short"
                    v-model="create.short_name"
                    label="Short name"
                    :maxlength="32"
                    :error="create.errors.short_name"
                />
                <SelectField
                    id="subject-type"
                    v-model="create.subject_type"
                    label="Type"
                    :options="typeOptions"
                    required
                    :error="create.errors.subject_type"
                />
                <SelectField
                    id="subject-department"
                    v-model="create.academic_department_id"
                    label="Academic department"
                    :options="departmentOptions"
                    placeholder="None"
                    :error="create.errors.academic_department_id"
                />
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="create.processing"
                        class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                    >
                        Add Subject
                    </button>
                </div>
            </form>
        </section>

        <ConfirmDialog
            ref="dialog"
            :title="`Deactivate ${pending?.name ?? 'Subject'}?`"
            confirm-label="Deactivate"
            @confirm="pending && setStatus(pending, 'inactive')"
        >
            It stays in the records, including every Subject Offering that uses it, and can be
            reactivated.
        </ConfirmDialog>
    </main>
</template>
