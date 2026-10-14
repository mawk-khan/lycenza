<script setup lang="ts">
// Academic Years: create, activate, close and (SWC.2) rename over
// AcademicYearService. One active Year per School (rule 64): activating a
// draft Year closes the active one in the same transaction (rule 65); a
// closed Year cannot be reopened. Since SWC.2 (ADR 0073 section 2.5) the
// service's refusals -- an overlapping date range, a stale transition, a
// concurrent activation -- come back as form errors, never a 500.
import { Head, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import ErrorSummary from '../../../Components/Form/ErrorSummary.vue';
import TextField from '../../../Components/Form/TextField.vue';

interface AcademicYear {
    id: string;
    name: string;
    code: string;
    startsOn: string;
    endsOn: string;
    status: string;
}

const props = defineProps<{ academicYears: AcademicYear[]; canManage: boolean }>();

const create = useForm({ name: '', code: '', starts_on: '', ends_on: '' });
function submitCreate() {
    create.post('/app/school-setup/academic-years', {
        preserveScroll: true,
        onSuccess: () => create.reset(),
    });
}

const editing = ref<AcademicYear | null>(null);
const edit = useForm({ name: '', code: '' });
function startEdit(year: AcademicYear) {
    editing.value = year;
    edit.clearErrors();
    edit.name = year.name;
    edit.code = year.code;
    nextTick(() => document.getElementById('edit-year-name')?.focus());
}
function submitEdit() {
    if (!editing.value) return;
    edit.put(`/app/school-setup/academic-years/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

const transition = useForm({});
const pending = ref<{ year: AcademicYear; action: 'activate' | 'close' } | null>(null);
const dialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
function ask(year: AcademicYear, action: 'activate' | 'close') {
    pending.value = { year, action };
    dialog.value?.open();
}
function runTransition() {
    if (!pending.value) return;
    transition.post(
        `/app/school-setup/academic-years/${pending.value.year.id}/${pending.value.action}`,
        { preserveScroll: true },
    );
}

const statusLabel: Record<string, string> = { draft: 'Draft', active: 'Active', closed: 'Closed' };
</script>

<template>
    <Head title="Academic years" />
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>
        <h1 class="mt-2 text-xl font-semibold">Academic years</h1>

        <ErrorSummary :errors="transition.errors" :fields="['academic_year']" />

        <p v-if="props.academicYears.length === 0" class="mt-4 text-sm text-slate-600">
            No Academic Year exists yet. Create the first one to configure Terms and Sections.
        </p>
        <table v-else class="mt-4 w-full text-left text-sm">
            <caption class="sr-only">
                Academic years, newest first
            </caption>
            <thead class="border-b border-slate-300 text-slate-600">
                <tr>
                    <th scope="col" class="py-2">Name</th>
                    <th scope="col" class="py-2">Code</th>
                    <th scope="col" class="py-2">Dates</th>
                    <th scope="col" class="py-2">Status</th>
                    <th v-if="props.canManage" scope="col" class="py-2">
                        <span class="sr-only">Actions</span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <tr v-for="year in props.academicYears" :key="year.id">
                    <td class="py-2 font-medium">{{ year.name }}</td>
                    <td class="py-2">{{ year.code }}</td>
                    <td class="py-2">{{ year.startsOn }} – {{ year.endsOn }}</td>
                    <td class="py-2">{{ statusLabel[year.status] ?? year.status }}</td>
                    <td v-if="props.canManage" class="space-x-3 py-2 text-right">
                        <button type="button" class="underline" @click="startEdit(year)">
                            Rename<span class="sr-only"> {{ year.name }}</span>
                        </button>
                        <button
                            v-if="year.status === 'draft'"
                            type="button"
                            class="underline"
                            :disabled="transition.processing"
                            @click="ask(year, 'activate')"
                        >
                            Activate<span class="sr-only"> {{ year.name }}</span>
                        </button>
                        <button
                            v-if="year.status === 'active'"
                            type="button"
                            class="text-red-700 underline"
                            :disabled="transition.processing"
                            @click="ask(year, 'close')"
                        >
                            Close<span class="sr-only"> {{ year.name }}</span>
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <section
            v-if="editing"
            class="mt-6 rounded border border-slate-300 p-4"
            aria-labelledby="edit-year-heading"
        >
            <h2 id="edit-year-heading" class="font-semibold">Rename {{ editing.name }}</h2>
            <p class="mt-1 text-sm text-slate-600">
                Dates and status have their own controls and do not change here.
            </p>
            <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitEdit">
                <TextField
                    id="edit-year-name"
                    v-model="edit.name"
                    label="Name"
                    required
                    :error="edit.errors.name"
                />
                <TextField
                    id="edit-year-code"
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

        <section v-if="props.canManage" class="mt-8" aria-labelledby="add-year-heading">
            <h2 id="add-year-heading" class="font-semibold">Add an Academic year</h2>
            <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitCreate">
                <TextField
                    id="year-name"
                    v-model="create.name"
                    label="Name"
                    required
                    :error="create.errors.name"
                />
                <TextField
                    id="year-code"
                    v-model="create.code"
                    label="Code"
                    required
                    :maxlength="32"
                    hint="Unique in this School; letters are stored in capitals."
                    :error="create.errors.code"
                />
                <TextField
                    id="year-starts"
                    v-model="create.starts_on"
                    label="Starts on"
                    type="date"
                    required
                    hint="Must not overlap another Academic Year."
                    :error="create.errors.starts_on"
                />
                <TextField
                    id="year-ends"
                    v-model="create.ends_on"
                    label="Ends on"
                    type="date"
                    required
                    :error="create.errors.ends_on"
                />
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="create.processing"
                        class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                    >
                        Add Academic year
                    </button>
                </div>
            </form>
        </section>

        <ConfirmDialog
            ref="dialog"
            :title="
                pending?.action === 'activate'
                    ? `Activate ${pending.year.name}?`
                    : `Close ${pending?.year.name ?? 'Academic year'}?`
            "
            :confirm-label="pending?.action === 'activate' ? 'Activate' : 'Close'"
            :tone="pending?.action === 'activate' ? 'neutral' : 'danger'"
            @confirm="runTransition"
        >
            <template v-if="pending?.action === 'activate'">
                Any currently active Academic Year is closed at the same time.
            </template>
            <template v-else>It becomes historical and cannot be reopened.</template>
        </ConfirmDialog>
    </main>
</template>
