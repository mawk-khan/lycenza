<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface VehicleOption {
    id: string;
    code: string;
    registrationNumber: string;
}

interface DriverOption {
    id: string;
    employeeNumber: string;
    fullName: string;
}

interface AssignmentRow {
    id: string;
    status: 'active' | 'ended';
    startsOn: string;
    endsOn: string | null;
    vehicleCode: string;
    driverName: string;
}

interface Props {
    route: { id: string; code: string; name: string };
    assignments: AssignmentRow[];
    canManage: boolean;
}

const props = defineProps<Props>();

const current = props.assignments.find((a) => a.status === 'active') ?? null;

const form = useForm({
    vehicle_id: '',
    driver_employee_id: '',
});

const vehicleQuery = ref('');
const vehicleResults = ref<VehicleOption[]>([]);
const selectedVehicle = ref<VehicleOption | null>(null);

const driverQuery = ref('');
const driverResults = ref<DriverOption[]>([]);
const selectedDriver = ref<DriverOption | null>(null);

let vehicleDebounce: ReturnType<typeof setTimeout> | undefined;
let driverDebounce: ReturnType<typeof setTimeout> | undefined;

watch(vehicleQuery, (value) => {
    clearTimeout(vehicleDebounce);
    vehicleDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/transport/operations/search/vehicles?q=${encodeURIComponent(value)}`,
        );
        const body = await response.json();
        vehicleResults.value = body.data;
    }, 250);
});

watch(driverQuery, (value) => {
    clearTimeout(driverDebounce);
    driverDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/transport/operations/search/drivers?q=${encodeURIComponent(value)}`,
        );
        const body = await response.json();
        driverResults.value = body.data;
    }, 250);
});

function pickVehicle(vehicle: VehicleOption): void {
    selectedVehicle.value = vehicle;
    form.vehicle_id = vehicle.id;
    vehicleResults.value = [];
    vehicleQuery.value = `${vehicle.code} (${vehicle.registrationNumber})`;
}

function pickDriver(driver: DriverOption): void {
    selectedDriver.value = driver;
    form.driver_employee_id = driver.id;
    driverResults.value = [];
    driverQuery.value = `${driver.fullName} (${driver.employeeNumber})`;
}

function submit(): void {
    form.post(`/app/transport/operations/${props.route.id}`, {
        onSuccess: () => form.reset(),
    });
}

function endAssignment(assignmentId: string): void {
    router.post(`/app/transport/route-assignments/${assignmentId}/end`);
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/transport/operations">← Route operations</a>

        <h1 class="mt-2 text-xl font-semibold">{{ route.name }}</h1>
        <p class="mt-1 text-sm text-slate-500">Code {{ route.code }}</p>

        <div class="mt-8">
            <h2 class="text-sm font-semibold text-slate-900">Current assignment</h2>
            <div
                v-if="current"
                class="mt-3 flex items-center justify-between rounded border border-slate-200 p-4 text-sm"
            >
                <div>
                    <p class="font-medium">{{ current.vehicleCode }}</p>
                    <p class="text-slate-600">{{ current.driverName }}</p>
                    <p class="mt-1 text-xs text-slate-400">Since {{ current.startsOn }}</p>
                </div>
                <button
                    v-if="canManage"
                    type="button"
                    class="text-sm underline"
                    @click="endAssignment(current.id)"
                >
                    End assignment
                </button>
            </div>
            <p v-else class="mt-3 text-sm text-slate-500">No Vehicle/Driver currently assigned.</p>
        </div>

        <div v-if="canManage" class="mt-8">
            <h2 class="text-sm font-semibold text-slate-900">
                {{ current ? 'Reassign' : 'Assign' }} Vehicle / Driver
            </h2>

            <form class="mt-3 space-y-4" @submit.prevent="submit">
                <div class="relative">
                    <label class="block text-sm text-slate-600" for="vehicle-search">Vehicle</label>
                    <input
                        id="vehicle-search"
                        v-model="vehicleQuery"
                        type="text"
                        placeholder="Search by code or registration number"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <ul
                        v-if="vehicleResults.length > 0"
                        class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                    >
                        <li
                            v-for="vehicle in vehicleResults"
                            :key="vehicle.id"
                            class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                            @click="pickVehicle(vehicle)"
                        >
                            {{ vehicle.code }} ({{ vehicle.registrationNumber }})
                        </li>
                    </ul>
                    <p v-if="form.errors.vehicle_id" class="mt-1 text-sm text-red-600">
                        {{ form.errors.vehicle_id }}
                    </p>
                </div>

                <div class="relative">
                    <label class="block text-sm text-slate-600" for="driver-search">Driver</label>
                    <input
                        id="driver-search"
                        v-model="driverQuery"
                        type="text"
                        placeholder="Search by name or employee number"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <ul
                        v-if="driverResults.length > 0"
                        class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                    >
                        <li
                            v-for="driver in driverResults"
                            :key="driver.id"
                            class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                            @click="pickDriver(driver)"
                        >
                            {{ driver.fullName }} ({{ driver.employeeNumber }})
                        </li>
                    </ul>
                    <p v-if="form.errors.driver_employee_id" class="mt-1 text-sm text-red-600">
                        {{ form.errors.driver_employee_id }}
                    </p>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : current ? 'Reassign' : 'Assign' }}
                </button>
            </form>
        </div>

        <div class="mt-8">
            <h2 class="text-sm font-semibold text-slate-900">History</h2>
            <table class="mt-3 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Vehicle</th>
                        <th scope="col" class="py-2 font-medium">Driver</th>
                        <th scope="col" class="py-2 font-medium">From</th>
                        <th scope="col" class="py-2 font-medium">To</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="assignment in assignments" :key="assignment.id">
                        <td class="py-3 font-medium">{{ assignment.vehicleCode }}</td>
                        <td class="py-3 text-slate-600">{{ assignment.driverName }}</td>
                        <td class="py-3 text-slate-600">{{ assignment.startsOn }}</td>
                        <td class="py-3 text-slate-600">{{ assignment.endsOn ?? 'Current' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </main>
</template>
