<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';
import RoomFeeHeadPanel from '../../../../Components/Fees/RoomFeeHeadPanel.vue';

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
    // SWC.3: the room override, the Hostel default and which governs.
    feePanel: {
        feeHead: { id: string; code: string; name: string; active?: boolean } | null;
        hostelDefault: { id: string; code: string; name: string; active?: boolean } | null;
        governing: 'room' | 'hostel' | null;
        options: Array<{ id: string; code: string; name: string }>;
        canManage: boolean;
    };
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
    <main class="mx-auto max-w-3xl p-8 font-sans text-ink">
        <a class="text-sm underline" :href="`/app/hostels/${hostel.id}`">← {{ hostel.name }}</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Room {{ room.code }}</h1>
                <p v-if="room.floorOrBlock" class="mt-1 text-sm text-ink-muted">
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
            <h2 class="text-sm font-semibold text-ink">Beds</h2>
            <button
                v-if="canManage"
                type="button"
                class="btn btn-primary"
                @click="showAddBed = !showAddBed"
            >
                {{ showAddBed ? 'Cancel' : 'Add bed' }}
            </button>
        </div>

        <form v-if="showAddBed" class="mt-3 flex items-end gap-3" @submit.prevent="submitBed">
            <div>
                <label class="block text-sm text-ink-muted" for="bed-code">Code</label>
                <input id="bed-code" v-model="form.code" type="text" class="input mt-1 w-32" />
                <p v-if="form.errors.code" class="mt-1 text-sm text-danger">
                    {{ form.errors.code }}
                </p>
            </div>
            <button type="submit" :disabled="form.processing" class="btn btn-primary">
                {{ form.processing ? 'Saving…' : 'Add bed' }}
            </button>
        </form>

        <p v-if="beds.length === 0" class="mt-4 text-sm text-ink-muted">No beds added yet.</p>

        <table v-else class="mt-4 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-line text-xs text-ink-muted">
                    <th scope="col" class="py-2 font-medium">Bed</th>
                    <th scope="col" class="py-2 font-medium">Occupant</th>
                    <th scope="col" class="py-2 font-medium">Status</th>
                    <th scope="col" class="py-2 font-medium">
                        <span class="sr-only">Actions</span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="bed in beds" :key="bed.id">
                    <td class="py-3 font-medium">{{ bed.code }}</td>
                    <td class="py-3 text-ink-muted">{{ bed.occupantName ?? '—' }}</td>
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
        <RoomFeeHeadPanel :room-id="props.room.id" :panel="props.feePanel" />
    </main>
</template>
