<script setup lang="ts">
// SWC.2 (ADR 0073 section 10): Grade Level list, create, edit and
// deactivate/reactivate over GradeLevelAdministrationService. The sequence
// is the pedagogical order (rule 67); codes and sequences are unique per
// School -- the server's rule, shown here as field errors.
import { Head, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import ErrorSummary from '../../../Components/Form/ErrorSummary.vue';
import TextField from '../../../Components/Form/TextField.vue';

interface GradeLevel {
    id: string;
    name: string;
    code: string;
    sequence: number;
    educationStage: string | null;
    status: string;
}

const props = defineProps<{ gradeLevels: GradeLevel[]; canManage: boolean }>();

const create = useForm<{
    name: string;
    code: string;
    sequence: number | null;
    education_stage: string;
}>({
    name: '',
    code: '',
    sequence: null,
    education_stage: '',
});
function submitCreate() {
    create.post('/app/school-setup/grade-levels', {
        preserveScroll: true,
        onSuccess: () => create.reset(),
    });
}

const editing = ref<GradeLevel | null>(null);
const edit = useForm<{
    name: string;
    code: string;
    sequence: number | null;
    education_stage: string;
}>({
    name: '',
    code: '',
    sequence: null,
    education_stage: '',
});
function startEdit(grade: GradeLevel) {
    editing.value = grade;
    edit.clearErrors();
    edit.name = grade.name;
    edit.code = grade.code;
    edit.sequence = grade.sequence;
    edit.education_stage = grade.educationStage ?? '';
    nextTick(() => document.getElementById('edit-grade-name')?.focus());
}
function submitEdit() {
    if (!editing.value) return;
    edit.put(`/app/school-setup/grade-levels/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

const statusForm = useForm({ status: '' });
const pending = ref<GradeLevel | null>(null);
const dialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
function askDeactivate(grade: GradeLevel) {
    pending.value = grade;
    dialog.value?.open();
}
function setStatus(grade: GradeLevel, status: 'active' | 'inactive') {
    statusForm.status = status;
    statusForm.put(`/app/school-setup/grade-levels/${grade.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Grade levels" />
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>
        <h1 class="mt-2 text-xl font-semibold">Grade levels</h1>

        <ErrorSummary :errors="statusForm.errors" :fields="['status']" />

        <p v-if="props.gradeLevels.length === 0" class="mt-4 text-sm text-slate-600">
            No Grade level exists yet.
        </p>
        <div v-else class="relative overflow-x-auto mt-4">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Grade levels in teaching order
                </caption>
                <thead class="border-b border-slate-300 text-slate-600">
                    <tr>
                        <th scope="col" class="pr-3 py-2">Order</th>
                        <th scope="col" class="pr-3 py-2">Name</th>
                        <th scope="col" class="pr-3 py-2">Code</th>
                        <th scope="col" class="pr-3 py-2">Stage</th>
                        <th scope="col" class="pr-3 py-2">Status</th>
                        <th v-if="props.canManage" scope="col" class="pr-3 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <tr v-for="grade in props.gradeLevels" :key="grade.id">
                        <td class="pr-3 py-2">{{ grade.sequence }}</td>
                        <td class="pr-3 py-2 font-medium">{{ grade.name }}</td>
                        <td class="pr-3 py-2">{{ grade.code }}</td>
                        <td class="pr-3 py-2">{{ grade.educationStage ?? '—' }}</td>
                        <td class="pr-3 py-2">
                            {{ grade.status === 'active' ? 'Active' : 'Inactive' }}
                        </td>
                        <td v-if="props.canManage" class="pr-3 space-x-3 py-2 text-right">
                            <button type="button" class="underline" @click="startEdit(grade)">
                                Edit<span class="sr-only"> {{ grade.name }}</span>
                            </button>
                            <button
                                v-if="grade.status === 'active'"
                                type="button"
                                class="text-red-700 underline"
                                @click="askDeactivate(grade)"
                            >
                                Deactivate<span class="sr-only"> {{ grade.name }}</span>
                            </button>
                            <button
                                v-else
                                type="button"
                                class="underline"
                                :disabled="statusForm.processing"
                                @click="setStatus(grade, 'active')"
                            >
                                Reactivate<span class="sr-only"> {{ grade.name }}</span>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <section
            v-if="editing"
            class="mt-6 rounded border border-slate-300 p-4"
            aria-labelledby="edit-grade-heading"
        >
            <h2 id="edit-grade-heading" class="font-semibold">Edit {{ editing.name }}</h2>
            <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitEdit">
                <TextField
                    id="edit-grade-name"
                    v-model="edit.name"
                    label="Name"
                    required
                    :error="edit.errors.name"
                />
                <TextField
                    id="edit-grade-code"
                    v-model="edit.code"
                    label="Code"
                    required
                    :maxlength="32"
                    :error="edit.errors.code"
                />
                <TextField
                    id="edit-grade-sequence"
                    v-model="edit.sequence"
                    label="Order"
                    type="number"
                    :min="0"
                    required
                    :error="edit.errors.sequence"
                />
                <TextField
                    id="edit-grade-stage"
                    v-model="edit.education_stage"
                    label="Education stage"
                    :maxlength="64"
                    :error="edit.errors.education_stage"
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

        <section v-if="props.canManage" class="mt-8" aria-labelledby="add-grade-heading">
            <h2 id="add-grade-heading" class="font-semibold">Add a Grade level</h2>
            <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitCreate">
                <TextField
                    id="grade-name"
                    v-model="create.name"
                    label="Name"
                    required
                    :error="create.errors.name"
                />
                <TextField
                    id="grade-code"
                    v-model="create.code"
                    label="Code"
                    required
                    :maxlength="32"
                    hint="Unique in this School; letters are stored in capitals."
                    :error="create.errors.code"
                />
                <TextField
                    id="grade-sequence"
                    v-model="create.sequence"
                    label="Order"
                    type="number"
                    :min="0"
                    required
                    hint="Teaching order (Nursery before Grade 1); unique in this School."
                    :error="create.errors.sequence"
                />
                <TextField
                    id="grade-stage"
                    v-model="create.education_stage"
                    label="Education stage"
                    :maxlength="64"
                    :error="create.errors.education_stage"
                />
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="create.processing"
                        class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                    >
                        Add Grade level
                    </button>
                </div>
            </form>
        </section>

        <ConfirmDialog
            ref="dialog"
            :title="`Deactivate ${pending?.name ?? 'Grade level'}?`"
            confirm-label="Deactivate"
            @confirm="pending && setStatus(pending, 'inactive')"
        >
            It stays in the records and can be reactivated. It is no longer offered when new
            Sections are created.
        </ConfirmDialog>
    </main>
</template>
