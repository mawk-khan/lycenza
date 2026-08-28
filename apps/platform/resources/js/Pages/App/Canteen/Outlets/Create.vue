<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface LocationOption {
    id: string;
    code: string;
    name: string;
}

const form = useForm({
    code: '',
    name: '',
    campus_id: '',
    inventory_location_id: '',
});

// Phase 10F fix: uses the Canteen-scoped `/app/canteen-outlets/search/
// inventory-locations` endpoint (CanteenOutletController::searchInventoryLocations()),
// gated by `canteen.directory.manage` -- not Inventory's own
// `inventory.stock.manage`-gated `/app/inventory-stock/search/locations`
// endpoint this page used to call. A user who holds only
// `canteen.directory.manage` can now search Locations here.
const locationQuery = ref('');
const locationResults = ref<LocationOption[]>([]);
const selectedLocationLabel = ref('');
let locationDebounce: ReturnType<typeof setTimeout> | undefined;

watch(locationQuery, (value) => {
    clearTimeout(locationDebounce);
    locationDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/canteen-outlets/search/inventory-locations?q=${encodeURIComponent(value)}`,
        );
        if (!response.ok) {
            locationResults.value = [];
            return;
        }
        const body = await response.json();
        locationResults.value = body.data;
    }, 250);
});

function pickLocation(location: LocationOption): void {
    form.inventory_location_id = location.id;
    selectedLocationLabel.value = `${location.code} — ${location.name}`;
    locationQuery.value = '';
    locationResults.value = [];
}

function clearLocation(): void {
    form.inventory_location_id = '';
    selectedLocationLabel.value = '';
}

function submit(): void {
    form.post('/app/canteen-outlets');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/canteen-outlets">← Canteen outlets</a>
        <h1 class="mt-2 text-xl font-semibold">Add Outlet</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="code">Code</label>
                <input
                    id="code"
                    v-model="form.code"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">
                    {{ form.errors.code }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="name">Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                    {{ form.errors.name }}
                </p>
            </div>

            <div class="relative">
                <label class="block text-sm text-slate-600" for="location-search"
                    >Inventory Location</label
                >
                <div
                    v-if="selectedLocationLabel"
                    class="mt-1 flex items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <span>{{ selectedLocationLabel }}</span>
                    <button
                        type="button"
                        class="text-slate-400 hover:text-slate-700"
                        @click="clearLocation"
                    >
                        Change
                    </button>
                </div>
                <input
                    v-else
                    id="location-search"
                    v-model="locationQuery"
                    type="text"
                    placeholder="Search by code or name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="locationResults.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                >
                    <li
                        v-for="location in locationResults"
                        :key="location.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        @click="pickLocation(location)"
                    >
                        {{ location.code }} — {{ location.name }}
                    </li>
                </ul>
                <p class="mt-1 text-xs text-slate-400">
                    Stock issued to fulfill Orders from this Outlet is drawn from this Location.
                </p>
                <p v-if="form.errors.inventory_location_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.inventory_location_id }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="campus_id"
                    >Campus ID (optional)</label
                >
                <input
                    id="campus_id"
                    v-model="form.campus_id"
                    type="text"
                    placeholder="Leave blank for School-wide"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.campus_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.campus_id }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing || !form.inventory_location_id"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Add Outlet' }}
                </button>
                <a class="text-sm underline" href="/app/canteen-outlets">Cancel</a>
            </div>
        </form>
    </main>
</template>
