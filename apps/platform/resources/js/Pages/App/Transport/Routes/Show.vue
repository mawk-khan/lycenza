<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface StopRow {
    id: string;
    name: string;
    sequence: number;
    address: string | null;
    status: 'active' | 'inactive';
}

interface Props {
    route: {
        id: string;
        code: string;
        name: string;
        description: string | null;
        status: 'active' | 'inactive';
    };
    stops: StopRow[];
    canManage: boolean;
}

const props = defineProps<Props>();

const showAddStop = ref(false);
const form = useForm({ name: '', sequence: props.stops.length + 1, address: '' });

function submitStop(): void {
    form.post(`/app/transport/routes/${props.route.id}/stops`, {
        onSuccess: () => {
            form.reset();
            showAddStop.value = false;
        },
    });
}

function toggleStopStatus(stop: StopRow): void {
    router.patch(`/app/transport/stops/${stop.id}`, {
        status: stop.status === 'active' ? 'inactive' : 'active',
    });
}

function toggleRouteStatus(): void {
    router.patch(`/app/transport/routes/${props.route.id}`, {
        status: props.route.status === 'active' ? 'inactive' : 'active',
    });
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/transport/routes">← Transport routes</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ route.name }}</h1>
                <p class="mt-1 text-sm text-slate-500">Code {{ route.code }}</p>
                <p v-if="route.description" class="mt-1 text-xs text-slate-400">
                    {{ route.description }}
                </p>
            </div>
            <div class="flex items-center gap-3">
                <StatusBadge :status="route.status" />
                <button
                    v-if="canManage"
                    type="button"
                    class="text-sm underline"
                    @click="toggleRouteStatus"
                >
                    {{ route.status === 'active' ? 'Deactivate' : 'Activate' }}
                </button>
            </div>
        </div>

        <div class="mt-8 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-900">Stops</h2>
            <button
                v-if="canManage"
                type="button"
                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800"
                @click="showAddStop = !showAddStop"
            >
                {{ showAddStop ? 'Cancel' : 'Add stop' }}
            </button>
        </div>

        <form v-if="showAddStop" class="mt-3 flex items-end gap-3" @submit.prevent="submitStop">
            <div>
                <label class="block text-sm text-slate-600" for="stop-name">Name</label>
                <input
                    id="stop-name"
                    v-model="form.name"
                    type="text"
                    class="mt-1 w-48 rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                    {{ form.errors.name }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="stop-sequence">Order</label>
                <input
                    id="stop-sequence"
                    v-model.number="form.sequence"
                    type="number"
                    min="1"
                    class="mt-1 w-24 rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.sequence" class="mt-1 text-sm text-red-600">
                    {{ form.errors.sequence }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="stop-address">
                    Address (optional)
                </label>
                <input
                    id="stop-address"
                    v-model="form.address"
                    type="text"
                    class="mt-1 w-56 rounded border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
            >
                {{ form.processing ? 'Saving…' : 'Add stop' }}
            </button>
        </form>

        <p v-if="stops.length === 0" class="mt-4 text-sm text-slate-500">No stops added yet.</p>

        <table v-else class="mt-4 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">Order</th>
                    <th scope="col" class="py-2 font-medium">Name</th>
                    <th scope="col" class="py-2 font-medium">Address</th>
                    <th scope="col" class="py-2 font-medium">Status</th>
                    <th scope="col" class="py-2 font-medium">
                        <span class="sr-only">Actions</span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="stop in stops" :key="stop.id">
                    <td class="py-3 text-slate-600">{{ stop.sequence }}</td>
                    <td class="py-3 font-medium">{{ stop.name }}</td>
                    <td class="py-3 text-slate-600">{{ stop.address ?? '—' }}</td>
                    <td class="py-3"><StatusBadge :status="stop.status" /></td>
                    <td class="py-3 text-right">
                        <button
                            v-if="canManage"
                            type="button"
                            class="text-sm underline"
                            @click="toggleStopStatus(stop)"
                        >
                            {{ stop.status === 'active' ? 'Deactivate' : 'Activate' }}
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </main>
</template>
