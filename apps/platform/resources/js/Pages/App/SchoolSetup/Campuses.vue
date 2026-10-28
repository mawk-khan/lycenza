<script setup lang="ts">
// SWC.2 (ADR 0073 section 10): Campus list, create, edit and
// deactivate/reactivate (`status`, never a delete) over the shared
// CampusAdministrationService. The server decides every rule; this page only
// shows its field errors.
// ORG.12C (ADR 0080 §8): rows stay short (name, code, city, status,
// services); the full profile and its edit form are on each Campus's page.
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import ErrorSummary from '../../../Components/Form/ErrorSummary.vue';
import TextField from '../../../Components/Form/TextField.vue';

interface Campus {
    id: string;
    name: string;
    code: string;
    status: string;
    city: string | null;
    services: string[];
}

const props = defineProps<{
    campuses: Campus[];
    serviceOptions: Array<{ code: string; label: string }>;
    canManage: boolean;
}>();

const serviceLabel = (code: string) =>
    props.serviceOptions.find((option) => option.code === code)?.label ?? code;

const create = useForm({ name: '', code: '' });
function submitCreate() {
    create.post('/app/school-setup/campuses', {
        preserveScroll: true,
        onSuccess: () => create.reset(),
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
    <main class="mx-auto max-w-4xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>
        <h1 class="mt-2 text-xl font-semibold">Campuses</h1>

        <ErrorSummary :errors="statusForm.errors" :fields="['status', 'code', 'name']" />

        <p v-if="props.campuses.length === 0" class="mt-4 text-sm text-slate-600">
            No Campus exists yet.
        </p>
        <div v-else class="relative overflow-x-auto mt-4">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Campuses
                </caption>
                <thead class="border-b border-slate-300 text-slate-600">
                    <tr>
                        <th scope="col" class="pr-3 py-2">Name</th>
                        <th scope="col" class="pr-3 py-2">Code</th>
                        <th scope="col" class="pr-3 py-2">City</th>
                        <th scope="col" class="pr-3 py-2">Status</th>
                        <th scope="col" class="pr-3 py-2">Services</th>
                        <th v-if="props.canManage" scope="col" class="pr-3 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <tr v-for="campus in props.campuses" :key="campus.id">
                        <td class="pr-3 py-2 font-medium">
                            <a
                                class="underline"
                                :href="`/app/school-setup/campuses/${campus.id}`"
                                >{{ campus.name }}</a
                            >
                        </td>
                        <td class="pr-3 py-2">{{ campus.code }}</td>
                        <td class="pr-3 py-2 text-slate-600">{{ campus.city ?? '—' }}</td>
                        <td class="pr-3 py-2">
                            {{ campus.status === 'active' ? 'Active' : 'Inactive' }}
                        </td>
                        <td class="pr-3 py-2 text-slate-600">
                            {{ campus.services.map(serviceLabel).join(', ') || '—' }}
                        </td>
                        <td v-if="props.canManage" class="pr-3 space-x-3 py-2 text-right">
                            <a class="underline" :href="`/app/school-setup/campuses/${campus.id}`">
                                Edit<span class="sr-only"> {{ campus.name }}</span>
                            </a>
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
        </div>

        <section v-if="props.canManage" class="mt-8" aria-labelledby="add-campus-heading">
            <h2 id="add-campus-heading" class="font-semibold">Add a Campus</h2>
            <p class="mt-1 text-sm text-slate-600">
                Add the address, contact details, location and services on the Campus's page.
            </p>
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
