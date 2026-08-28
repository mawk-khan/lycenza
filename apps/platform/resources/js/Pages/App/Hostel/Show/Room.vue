<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface BedRow {
    id: string;
    code: string;
    status: 'active' | 'inactive';
    occupantName: string | null;
}

interface Props {
    room: {
        id: string;
        code: string;
        floorOrBlock: string | null;
        status: 'active' | 'inactive';
    };
    hostel: {
        id: string;
        code: string;
        name: string;
    };
    beds: BedRow[];
    canManage: boolean;
}

const props = defineProps<Props>();

const showAddBed = ref(false);
const form = useForm({ code: '' });

function submitBed(): void {
    form.post(`/app/hostel-rooms/${props.room.id}/beds`, {
        onSuccess: () => {
            form.reset();
            showAddBed.value = false;
        },
    });
}

function toggleRoomStatus(): void {
    router.patch(`/app/hostel-rooms/${props.room.id}`, {
        status: props.room.status === 'active' ? 'inactive' : 'active',
    });
}

function toggleBedStatus(bed: BedRow): void {
    router.patch(`/app/hostel-beds/${bed.id}`, {
        status: bed.status === 'active' ? 'inactive' : 'active',
    });
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" :href="`/app/hostels/${hostel.id}`">← {{ hostel.name }}</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Room {{ room.code }}</h1>
                <p v-if="room.floorOrBlock" class="mt-1 text-sm text-slate-500">
                    {{ room.floorOrBlock }}
                </p>
            </div>
            <div class="flex items-center gap-3">
                <StatusBadge :status="room.status" />
                <button
                    v-if="canManage"
                    type="button"
                    class="text-sm underline"
                    @click="toggleRoomStatus"
                >
                    {{ room.status === 'active' ? 'Deactivate' : 'Activate' }}
                </button>
            </div>
        </div>

        <div class="mt-8 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-900">Beds</h2>
            <button
                v-if="canManage"
                type="button"
                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800"
                @click="showAddBed = !showAddBed"
            >
                {{ showAddBed ? 'Cancel' : 'Add bed' }}
            </button>
        </div>

        <form v-if="showAddBed" class="mt-3 flex items-end gap-3" @submit.prevent="submitBed">
            <div>
                <label class="block text-sm text-slate-600" for="bed-code">Code</label>
                <input
                    id="bed-code"
                    v-model="form.code"
                    type="text"
                    class="mt-1 w-32 rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">
                    {{ form.errors.code }}
                </p>
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
            >
                {{ form.processing ? 'Saving…' : 'Add bed' }}
            </button>
        </form>

        <p v-if="beds.length === 0" class="mt-4 text-sm text-slate-500">No beds added yet.</p>

        <table v-else class="mt-4 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">Bed</th>
                    <th scope="col" class="py-2 font-medium">Occupant</th>
                    <th scope="col" class="py-2 font-medium">Status</th>
                    <th scope="col" class="py-2 font-medium">
                        <span class="sr-only">Actions</span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="bed in beds" :key="bed.id">
                    <td class="py-3 font-medium">{{ bed.code }}</td>
                    <td class="py-3 text-slate-600">{{ bed.occupantName ?? '—' }}</td>
                    <td class="py-3"><StatusBadge :status="bed.status" /></td>
                    <td class="py-3 text-right">
                        <button
                            v-if="canManage"
                            type="button"
                            class="text-sm underline"
                            @click="toggleBedStatus(bed)"
                        >
                            {{ bed.status === 'active' ? 'Deactivate' : 'Activate' }}
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </main>
</template>
