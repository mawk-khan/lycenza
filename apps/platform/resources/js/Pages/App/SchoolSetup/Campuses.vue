<script setup lang="ts">
// SWC.2 (ADR 0073 section 10): Campus list, create, edit and
// deactivate/reactivate (`status`, never a delete) over the shared
// CampusAdministrationService. The server decides every rule; this page only
// shows its field errors.
import { Head, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import ErrorSummary from '../../../Components/Form/ErrorSummary.vue';
import TextField from '../../../Components/Form/TextField.vue';

interface Campus {
    id: string;
    name: string;
    code: string;
    status: string;
    phone: string | null;
    email: string | null;
    address: string | null;
}

const props = defineProps<{ campuses: Campus[]; canManage: boolean }>();

const create = useForm({ name: '', code: '', phone: '', email: '', address: '' });
function submitCreate() {
    create.post('/app/school-setup/campuses', {
        preserveScroll: true,
        onSuccess: () => create.reset(),
    });
}

const editing = ref<Campus | null>(null);
const edit = useForm({ name: '', code: '', phone: '', email: '', address: '' });
function startEdit(campus: Campus) {
    editing.value = campus;
    edit.clearErrors();
    edit.name = campus.name;
    edit.code = campus.code;
    edit.phone = campus.phone ?? '';
    edit.email = campus.email ?? '';
    edit.address = campus.address ?? '';
    nextTick(() => document.getElementById('edit-campus-name')?.focus());
}
function submitEdit() {
    if (!editing.value) return;
    edit.put(`/app/school-setup/campuses/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

const statusForm = useForm({ status: '' });
const pending = ref<Campus | null>(null);
const dialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
function askDeactivate(campus: Campus) {
    pending.value = campus;
    dialog.value?.open();
}
function setStatus(campus: Campus, status: 'active' | 'inactive') {
    statusForm.status = status;
    statusForm.put(`/app/school-setup/campuses/${campus.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Campuses" />
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>
        <h1 class="mt-2 text-xl font-semibold">Campuses</h1>

        <ErrorSummary :errors="statusForm.errors" :fields="['status', 'code', 'name']" />

        <p v-if="props.campuses.length === 0" class="mt-4 text-sm text-slate-600">
            No Campus exists yet.
        </p>
        <table v-else class="mt-4 w-full text-left text-sm">
            <caption class="sr-only">
                Campuses
            </caption>
            <thead class="border-b border-slate-300 text-slate-600">
                <tr>
                    <th scope="col" class="py-2">Name</th>
                    <th scope="col" class="py-2">Code</th>
                    <th scope="col" class="py-2">Contact</th>
                    <th scope="col" class="py-2">Status</th>
                    <th v-if="props.canManage" scope="col" class="py-2">
                        <span class="sr-only">Actions</span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <tr v-for="campus in props.campuses" :key="campus.id">
                    <td class="py-2 font-medium">{{ campus.name }}</td>
                    <td class="py-2">{{ campus.code }}</td>
                    <td class="py-2 text-slate-600">
                        {{ [campus.phone, campus.email].filter(Boolean).join(' · ') || '—' }}
                    </td>
                    <td class="py-2">{{ campus.status === 'active' ? 'Active' : 'Inactive' }}</td>
                    <td v-if="props.canManage" class="space-x-3 py-2 text-right">
                        <button type="button" class="underline" @click="startEdit(campus)">
                            Edit<span class="sr-only"> {{ campus.name }}</span>
                        </button>
                        <button
                            v-if="campus.status === 'active'"
                            type="button"
                            class="text-red-700 underline"
                            @click="askDeactivate(campus)"
                        >
                            Deactivate<span class="sr-only"> {{ campus.name }}</span>
                        </button>
                        <button
                            v-else
                            type="button"
                            class="underline"
                            :disabled="statusForm.processing"
                            @click="setStatus(campus, 'active')"
                        >
                            Reactivate<span class="sr-only"> {{ campus.name }}</span>
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <section
            v-if="editing"
            class="mt-6 rounded border border-slate-300 p-4"
            aria-labelledby="edit-campus-heading"
        >
            <h2 id="edit-campus-heading" class="font-semibold">Edit {{ editing.name }}</h2>
            <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitEdit">
                <TextField
                    id="edit-campus-name"
                    v-model="edit.name"
                    label="Name"
                    required
                    :error="edit.errors.name"
                />
                <TextField
                    id="edit-campus-code"
                    v-model="edit.code"
                    label="Code"
                    required
                    :maxlength="32"
                    :error="edit.errors.code"
                />
                <TextField
                    id="edit-campus-phone"
                    v-model="edit.phone"
                    label="Phone"
                    type="tel"
                    :error="edit.errors.phone"
                />
                <TextField
                    id="edit-campus-email"
                    v-model="edit.email"
                    label="Email"
                    type="email"
                    :error="edit.errors.email"
                />
                <div class="sm:col-span-2">
                    <TextField
                        id="edit-campus-address"
                        v-model="edit.address"
                        label="Address"
                        :error="edit.errors.address"
                    />
                </div>
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

        <section v-if="props.canManage" class="mt-8" aria-labelledby="add-campus-heading">
            <h2 id="add-campus-heading" class="font-semibold">Add a Campus</h2>
            <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitCreate">
                <TextField
                    id="campus-name"
                    v-model="create.name"
                    label="Name"
                    required
                    :error="create.errors.name"
                />
                <TextField
                    id="campus-code"
                    v-model="create.code"
                    label="Code"
                    required
                    :maxlength="32"
                    hint="Unique in this School; letters are stored in capitals."
                    :error="create.errors.code"
                />
                <TextField
                    id="campus-phone"
                    v-model="create.phone"
                    label="Phone"
                    type="tel"
                    :error="create.errors.phone"
                />
                <TextField
                    id="campus-email"
                    v-model="create.email"
                    label="Email"
                    type="email"
                    :error="create.errors.email"
                />
                <div class="sm:col-span-2">
                    <TextField
                        id="campus-address"
                        v-model="create.address"
                        label="Address"
                        :error="create.errors.address"
                    />
                </div>
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="create.processing"
                        class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                    >
                        Add Campus
                    </button>
                </div>
            </form>
        </section>

        <ConfirmDialog
            ref="dialog"
            :title="`Deactivate ${pending?.name ?? 'Campus'}?`"
            confirm-label="Deactivate"
            @confirm="pending && setStatus(pending, 'inactive')"
        >
            It stays in the records and can be reactivated. It is no longer offered when new
            Sections are created.
        </ConfirmDialog>
    </main>
</template>
