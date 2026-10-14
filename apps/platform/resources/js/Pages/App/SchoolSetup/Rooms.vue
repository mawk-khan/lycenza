<script setup lang="ts">
// SWC.2 (ADR 0073 section 10): Rooms of one Campus -- list, create, edit and
// deactivate/reactivate over RoomAdministrationService. A Room belongs to a
// Campus of this School (rule 70); its code is unique per Campus.
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import ErrorSummary from '../../../Components/Form/ErrorSummary.vue';
import SelectField from '../../../Components/Form/SelectField.vue';
import TextField from '../../../Components/Form/TextField.vue';

interface Room {
    id: string;
    name: string;
    code: string;
    roomType: string;
    capacity: number | null;
    status: string;
}

const props = defineProps<{
    campuses: Array<{ id: string; name: string; code: string; status: string }>;
    selectedCampusId: string | null;
    rooms: Room[];
    roomTypes: string[];
    canManage: boolean;
}>();

const campusOptions = computed(() =>
    props.campuses.map((c) => ({
        value: c.id,
        label: c.status === 'active' ? c.name : `${c.name} (inactive)`,
    })),
);
const typeLabel = (type: string) => type.replace(/^./, (c) => c.toUpperCase());
const typeOptions = computed(() => props.roomTypes.map((t) => ({ value: t, label: typeLabel(t) })));
const selectedCampus = ref(props.selectedCampusId);
function changeCampus() {
    router.get(
        '/app/school-setup/rooms',
        { campus_id: selectedCampus.value },
        { preserveState: false },
    );
}
const campusName = computed(
    () => props.campuses.find((c) => c.id === props.selectedCampusId)?.name ?? '',
);

interface RoomForm {
    name: string;
    code: string;
    room_type: string;
    capacity: number | null;
}

const create = useForm<RoomForm>({ name: '', code: '', room_type: 'classroom', capacity: null });
function submitCreate() {
    if (!props.selectedCampusId) return;
    create.post(`/app/school-setup/campuses/${props.selectedCampusId}/rooms`, {
        preserveScroll: true,
        onSuccess: () => create.reset(),
    });
}

const editing = ref<Room | null>(null);
const edit = useForm<RoomForm>({ name: '', code: '', room_type: 'classroom', capacity: null });
function startEdit(room: Room) {
    editing.value = room;
    edit.clearErrors();
    edit.name = room.name;
    edit.code = room.code;
    edit.room_type = room.roomType;
    edit.capacity = room.capacity;
    nextTick(() => document.getElementById('edit-room-name')?.focus());
}
function submitEdit() {
    if (!editing.value) return;
    edit.put(`/app/school-setup/rooms/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

const statusForm = useForm({ status: '' });
const pending = ref<Room | null>(null);
const dialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
function askDeactivate(room: Room) {
    pending.value = room;
    dialog.value?.open();
}
function setStatus(room: Room, status: 'active' | 'inactive') {
    statusForm.status = status;
    statusForm.put(`/app/school-setup/rooms/${room.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Rooms" />
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>
        <h1 class="mt-2 text-xl font-semibold">Rooms</h1>

        <p v-if="props.campuses.length === 0" class="mt-4 text-sm text-slate-600">
            Rooms belong to a Campus. Add a Campus first.
        </p>
        <template v-else>
            <form class="mt-4 flex items-end gap-3" @submit.prevent="changeCampus">
                <div class="w-72">
                    <SelectField
                        id="rooms-campus"
                        v-model="selectedCampus"
                        label="Campus"
                        :options="campusOptions"
                    />
                </div>
                <button type="submit" class="rounded border border-slate-300 px-3 py-2">
                    Show rooms
                </button>
            </form>

            <ErrorSummary :errors="statusForm.errors" :fields="['status']" />

            <p v-if="props.rooms.length === 0" class="mt-4 text-sm text-slate-600">
                {{ campusName }} has no Room yet.
            </p>
            <div v-else class="relative overflow-x-auto mt-4">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Rooms of
                        {{
                            campusName
                        }}
                    </caption>
                    <thead class="border-b border-slate-300 text-slate-600">
                        <tr>
                            <th scope="col" class="pr-3 py-2">Name</th>
                            <th scope="col" class="pr-3 py-2">Code</th>
                            <th scope="col" class="pr-3 py-2">Type</th>
                            <th scope="col" class="pr-3 py-2">Capacity</th>
                            <th scope="col" class="pr-3 py-2">Status</th>
                            <th v-if="props.canManage" scope="col" class="pr-3 py-2">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="room in props.rooms" :key="room.id">
                            <td class="pr-3 py-2 font-medium">{{ room.name }}</td>
                            <td class="pr-3 py-2">{{ room.code }}</td>
                            <td class="pr-3 py-2">{{ typeLabel(room.roomType) }}</td>
                            <td class="pr-3 py-2">{{ room.capacity ?? '—' }}</td>
                            <td class="pr-3 py-2">
                                {{ room.status === 'active' ? 'Active' : 'Inactive' }}
                            </td>
                            <td v-if="props.canManage" class="pr-3 space-x-3 py-2 text-right">
                                <button type="button" class="underline" @click="startEdit(room)">
                                    Edit<span class="sr-only"> {{ room.name }}</span>
                                </button>
                                <button
                                    v-if="room.status === 'active'"
                                    type="button"
                                    class="text-red-700 underline"
                                    @click="askDeactivate(room)"
                                >
                                    Deactivate<span class="sr-only"> {{ room.name }}</span>
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    class="underline"
                                    :disabled="statusForm.processing"
                                    @click="setStatus(room, 'active')"
                                >
                                    Reactivate<span class="sr-only"> {{ room.name }}</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <section
                v-if="editing"
                class="mt-6 rounded border border-slate-300 p-4"
                aria-labelledby="edit-room-heading"
            >
                <h2 id="edit-room-heading" class="font-semibold">Edit {{ editing.name }}</h2>
                <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitEdit">
                    <TextField
                        id="edit-room-name"
                        v-model="edit.name"
                        label="Name"
                        required
                        :error="edit.errors.name"
                    />
                    <TextField
                        id="edit-room-code"
                        v-model="edit.code"
                        label="Code"
                        required
                        :maxlength="32"
                        :error="edit.errors.code"
                    />
                    <SelectField
                        id="edit-room-type"
                        v-model="edit.room_type"
                        label="Type"
                        :options="typeOptions"
                        :error="edit.errors.room_type"
                    />
                    <TextField
                        id="edit-room-capacity"
                        v-model="edit.capacity"
                        label="Capacity"
                        type="number"
                        :min="1"
                        :error="edit.errors.capacity"
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

            <section
                v-if="props.canManage && props.selectedCampusId"
                class="mt-8"
                aria-labelledby="add-room-heading"
            >
                <h2 id="add-room-heading" class="font-semibold">Add a Room to {{ campusName }}</h2>
                <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitCreate">
                    <TextField
                        id="room-name"
                        v-model="create.name"
                        label="Name"
                        required
                        :error="create.errors.name"
                    />
                    <TextField
                        id="room-code"
                        v-model="create.code"
                        label="Code"
                        required
                        :maxlength="32"
                        hint="Unique on this Campus; letters are stored in capitals."
                        :error="create.errors.code"
                    />
                    <SelectField
                        id="room-type"
                        v-model="create.room_type"
                        label="Type"
                        :options="typeOptions"
                        :error="create.errors.room_type"
                    />
                    <TextField
                        id="room-capacity"
                        v-model="create.capacity"
                        label="Capacity"
                        type="number"
                        :min="1"
                        :error="create.errors.capacity"
                    />
                    <div class="sm:col-span-2">
                        <button
                            type="submit"
                            :disabled="create.processing"
                            class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                        >
                            Add Room
                        </button>
                    </div>
                </form>
            </section>
        </template>

        <ConfirmDialog
            ref="dialog"
            :title="`Deactivate ${pending?.name ?? 'Room'}?`"
            confirm-label="Deactivate"
            @confirm="pending && setStatus(pending, 'inactive')"
        >
            It stays in the records and can be reactivated.
        </ConfirmDialog>
    </main>
</template>
