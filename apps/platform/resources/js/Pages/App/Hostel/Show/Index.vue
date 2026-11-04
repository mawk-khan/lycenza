<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';
import FeeHeadPanel from '../../../../Components/Fees/FeeHeadPanel.vue';

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
    // SWC.3: the Hostel's default OPF fee head.
    feePanel: {
        feeHead: { id: string; code: string; name: string; active?: boolean } | null;
        options: Array<{ id: string; code: string; name: string }>;
        canManage: boolean;
    };
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
    <main class="mx-auto max-w-3xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/hostels">← Hostels</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ hostel.name }}</h1>
                <p class="mt-1 text-sm text-ink-muted">Code {{ hostel.code }}</p>
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
            <h2 class="text-sm font-semibold text-ink">Rooms</h2>
            <button
                v-if="canManage"
                type="button"
                class="btn btn-primary"
                @click="showAddRoom = !showAddRoom"
            >
                {{ showAddRoom ? 'Cancel' : 'Add room' }}
            </button>
        </div>

        <form v-if="showAddRoom" class="mt-3 flex items-end gap-3" @submit.prevent="submitRoom">
            <div>
                <label class="block text-sm text-ink-muted" for="room-code">Code</label>
                <input id="room-code" v-model="form.code" type="text" class="input mt-1 w-40" />
                <p v-if="form.errors.code" class="mt-1 text-sm text-danger">
                    {{ form.errors.code }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="room-floor">
                    Floor/block (optional)
                </label>
                <input
                    id="room-floor"
                    v-model="form.floor_or_block"
                    type="text"
                    class="input mt-1 w-40"
                />
            </div>
            <button type="submit" :disabled="form.processing" class="btn btn-primary">
                {{ form.processing ? 'Saving…' : 'Add room' }}
            </button>
        </form>

        <p v-if="rooms.length === 0" class="mt-4 text-sm text-ink-muted">No rooms added yet.</p>

        <table v-else class="mt-4 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-line text-xs text-ink-muted">
                    <th scope="col" class="py-2 font-medium">Room</th>
                    <th scope="col" class="py-2 font-medium">Floor/block</th>
                    <th scope="col" class="py-2 font-medium">Beds</th>
                    <th scope="col" class="py-2 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="room in rooms" :key="room.id">
                    <td class="py-3 font-medium">
                        <a class="underline" :href="`/app/hostel-rooms/${room.id}`">{{
                            room.code
                        }}</a>
                    </td>
                    <td class="py-3 text-ink-muted">{{ room.floorOrBlock ?? '—' }}</td>
                    <td class="py-3 text-ink-muted">{{ room.bedCount }}</td>
                    <td class="py-3"><StatusBadge :status="room.status" /></td>
                </tr>
            </tbody>
        </table>
        <FeeHeadPanel
            id="hostel-fee-head"
            title="Hostel fee head"
            :action="`/app/hostels/${props.hostel.id}/fee-head`"
            :panel="props.feePanel"
            hint="Applies to every room without its own fee head, for residencies started from now on."
        />
    </main>
</template>
