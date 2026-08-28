<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface RoomRow {
    id: string;
    code: string;
    floorOrBlock: string | null;
    status: 'active' | 'inactive';
    bedCount: number;
}

interface Props {
    hostel: {
        id: string;
        code: string;
        name: string;
        status: 'active' | 'inactive';
    };
    rooms: RoomRow[];
    canManage: boolean;
}

const props = defineProps<Props>();

const showAddRoom = ref(false);
const form = useForm({ code: '', floor_or_block: '' });

function submitRoom(): void {
    form.post(`/app/hostels/${props.hostel.id}/rooms`, {
        onSuccess: () => {
            form.reset();
            showAddRoom.value = false;
        },
    });
}

function toggleHostelStatus(): void {
    router.patch(`/app/hostels/${props.hostel.id}`, {
        status: props.hostel.status === 'active' ? 'inactive' : 'active',
    });
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/hostels">← Hostels</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ hostel.name }}</h1>
                <p class="mt-1 text-sm text-slate-500">Code {{ hostel.code }}</p>
            </div>
            <div class="flex items-center gap-3">
                <StatusBadge :status="hostel.status" />
                <button
                    v-if="canManage"
                    type="button"
                    class="text-sm underline"
                    @click="toggleHostelStatus"
                >
                    {{ hostel.status === 'active' ? 'Deactivate' : 'Activate' }}
                </button>
            </div>
        </div>

        <div class="mt-8 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-900">Rooms</h2>
            <button
                v-if="canManage"
                type="button"
                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800"
                @click="showAddRoom = !showAddRoom"
            >
                {{ showAddRoom ? 'Cancel' : 'Add room' }}
            </button>
        </div>

        <form v-if="showAddRoom" class="mt-3 flex items-end gap-3" @submit.prevent="submitRoom">
            <div>
                <label class="block text-sm text-slate-600" for="room-code">Code</label>
                <input
                    id="room-code"
                    v-model="form.code"
                    type="text"
                    class="mt-1 w-40 rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">
                    {{ form.errors.code }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="room-floor">
                    Floor/block (optional)
                </label>
                <input
                    id="room-floor"
                    v-model="form.floor_or_block"
                    type="text"
                    class="mt-1 w-40 rounded border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
            >
                {{ form.processing ? 'Saving…' : 'Add room' }}
            </button>
        </form>

        <p v-if="rooms.length === 0" class="mt-4 text-sm text-slate-500">No rooms added yet.</p>

        <table v-else class="mt-4 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">Room</th>
                    <th scope="col" class="py-2 font-medium">Floor/block</th>
                    <th scope="col" class="py-2 font-medium">Beds</th>
                    <th scope="col" class="py-2 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="room in rooms" :key="room.id">
                    <td class="py-3 font-medium">
                        <a class="underline" :href="`/app/hostel-rooms/${room.id}`">{{
                            room.code
                        }}</a>
                    </td>
                    <td class="py-3 text-slate-600">{{ room.floorOrBlock ?? '—' }}</td>
                    <td class="py-3 text-slate-600">{{ room.bedCount }}</td>
                    <td class="py-3"><StatusBadge :status="room.status" /></td>
                </tr>
            </tbody>
        </table>
    </main>
</template>
