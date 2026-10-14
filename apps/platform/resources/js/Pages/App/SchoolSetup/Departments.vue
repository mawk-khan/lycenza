<script setup lang="ts">
// SWC.2 (ADR 0073 section 10): Academic Department list, create, edit and
// deactivate/reactivate over AcademicDepartmentAdministrationService. A
// Department groups Subjects; it carries no HR or teacher ownership.
import { Head, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import ErrorSummary from '../../../Components/Form/ErrorSummary.vue';
import TextField from '../../../Components/Form/TextField.vue';

interface Department {
    id: string;
    name: string;
    code: string;
    status: string;
}

const props = defineProps<{ departments: Department[]; canManage: boolean }>();

const create = useForm({ name: '', code: '' });
function submitCreate() {
    create.post('/app/school-setup/departments', {
        preserveScroll: true,
        onSuccess: () => create.reset(),
    });
}

const editing = ref<Department | null>(null);
const edit = useForm({ name: '', code: '' });
function startEdit(department: Department) {
    editing.value = department;
    edit.clearErrors();
    edit.name = department.name;
    edit.code = department.code;
    nextTick(() => document.getElementById('edit-department-name')?.focus());
}
function submitEdit() {
    if (!editing.value) return;
    edit.put(`/app/school-setup/departments/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

const statusForm = useForm({ status: '' });
const pending = ref<Department | null>(null);
const dialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
function askDeactivate(department: Department) {
    pending.value = department;
    dialog.value?.open();
}
function setStatus(department: Department, status: 'active' | 'inactive') {
    statusForm.status = status;
    statusForm.put(`/app/school-setup/departments/${department.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Academic departments" />
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>
        <h1 class="mt-2 text-xl font-semibold">Academic departments</h1>

        <ErrorSummary :errors="statusForm.errors" :fields="['status']" />

        <p v-if="props.departments.length === 0" class="mt-4 text-sm text-slate-600">
            No Academic department exists yet.
        </p>
        <table v-else class="mt-4 w-full text-left text-sm">
            <caption class="sr-only">
                Academic departments
            </caption>
            <thead class="border-b border-slate-300 text-slate-600">
                <tr>
                    <th scope="col" class="py-2">Name</th>
                    <th scope="col" class="py-2">Code</th>
                    <th scope="col" class="py-2">Status</th>
                    <th v-if="props.canManage" scope="col" class="py-2">
                        <span class="sr-only">Actions</span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <tr v-for="department in props.departments" :key="department.id">
                    <td class="py-2 font-medium">{{ department.name }}</td>
                    <td class="py-2">{{ department.code }}</td>
                    <td class="py-2">
                        {{ department.status === 'active' ? 'Active' : 'Inactive' }}
                    </td>
                    <td v-if="props.canManage" class="space-x-3 py-2 text-right">
                        <button type="button" class="underline" @click="startEdit(department)">
                            Edit<span class="sr-only"> {{ department.name }}</span>
                        </button>
                        <button
                            v-if="department.status === 'active'"
                            type="button"
                            class="text-red-700 underline"
                            @click="askDeactivate(department)"
                        >
                            Deactivate<span class="sr-only"> {{ department.name }}</span>
                        </button>
                        <button
                            v-else
                            type="button"
                            class="underline"
                            :disabled="statusForm.processing"
                            @click="setStatus(department, 'active')"
                        >
                            Reactivate<span class="sr-only"> {{ department.name }}</span>
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <section
            v-if="editing"
            class="mt-6 rounded border border-slate-300 p-4"
            aria-labelledby="edit-department-heading"
        >
            <h2 id="edit-department-heading" class="font-semibold">Edit {{ editing.name }}</h2>
            <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitEdit">
                <TextField
                    id="edit-department-name"
                    v-model="edit.name"
                    label="Name"
                    required
                    :error="edit.errors.name"
                />
                <TextField
                    id="edit-department-code"
                    v-model="edit.code"
                    label="Code"
                    required
                    :maxlength="32"
                    :error="edit.errors.code"
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

        <section v-if="props.canManage" class="mt-8" aria-labelledby="add-department-heading">
            <h2 id="add-department-heading" class="font-semibold">Add an Academic department</h2>
            <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitCreate">
                <TextField
                    id="department-name"
                    v-model="create.name"
                    label="Name"
                    required
                    :error="create.errors.name"
                />
                <TextField
                    id="department-code"
                    v-model="create.code"
                    label="Code"
                    required
                    :maxlength="32"
                    hint="Unique in this School; letters are stored in capitals."
                    :error="create.errors.code"
                />
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="create.processing"
                        class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                    >
                        Add Academic department
                    </button>
                </div>
            </form>
        </section>

        <ConfirmDialog
            ref="dialog"
            :title="`Deactivate ${pending?.name ?? 'Academic department'}?`"
            confirm-label="Deactivate"
            @confirm="pending && setStatus(pending, 'inactive')"
        >
            It stays in the records and can be reactivated. It is no longer offered when Subjects
            are created or edited.
        </ConfirmDialog>
    </main>
</template>
