<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface ItemOption {
    id: string;
    code: string;
    name: string;
    unitOfMeasure: string;
}

interface LocationOption {
    id: string;
    code: string;
    name: string;
}

const form = useForm({
    inventory_item_id: '',
    from_location_id: '',
    to_location_id: '',
    quantity: '',
});

const itemQuery = ref('');
const itemResults = ref<ItemOption[]>([]);

const fromQuery = ref('');
const fromResults = ref<LocationOption[]>([]);

const toQuery = ref('');
const toResults = ref<LocationOption[]>([]);

let itemDebounce: ReturnType<typeof setTimeout> | undefined;
let fromDebounce: ReturnType<typeof setTimeout> | undefined;
let toDebounce: ReturnType<typeof setTimeout> | undefined;

async function search(term: string): Promise<LocationOption[]> {
    const response = await fetch(
        `/app/inventory-stock/search/locations?q=${encodeURIComponent(term)}`,
    );
    const body = await response.json();
    return body.data;
}

watch(itemQuery, (value) => {
    clearTimeout(itemDebounce);
    itemDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/inventory-stock/search/items?q=${encodeURIComponent(value)}`,
        );
        const body = await response.json();
        itemResults.value = body.data;
    }, 250);
});

watch(fromQuery, (value) => {
    clearTimeout(fromDebounce);
    fromDebounce = setTimeout(async () => {
        fromResults.value = await search(value);
    }, 250);
});

watch(toQuery, (value) => {
    clearTimeout(toDebounce);
    toDebounce = setTimeout(async () => {
        toResults.value = await search(value);
    }, 250);
});

function pickItem(item: ItemOption): void {
    form.inventory_item_id = item.id;
    itemResults.value = [];
    itemQuery.value = `${item.code} — ${item.name}`;
}

function pickFrom(location: LocationOption): void {
    form.from_location_id = location.id;
    fromResults.value = [];
    fromQuery.value = `${location.code} — ${location.name}`;
}

function pickTo(location: LocationOption): void {
    form.to_location_id = location.id;
    toResults.value = [];
    toQuery.value = `${location.code} — ${location.name}`;
}

function submit(): void {
    form.post('/app/inventory-stock/transfer');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/inventory-stock">← Inventory stock</a>
        <h1 class="mt-2 text-xl font-semibold">Transfer stock</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div class="relative">
                <label class="block text-sm text-slate-600" for="item-search">Item</label>
                <input
                    id="item-search"
                    v-model="itemQuery"
                    type="text"
                    placeholder="Search by code or name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="itemResults.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                >
                    <li
                        v-for="item in itemResults"
                        :key="item.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        @click="pickItem(item)"
                    >
                        {{ item.code }} — {{ item.name }} ({{ item.unitOfMeasure }})
                    </li>
                </ul>
                <p v-if="form.errors.inventory_item_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.inventory_item_id }}
                </p>
            </div>

            <div class="relative">
                <label class="block text-sm text-slate-600" for="from-search">From Location</label>
                <input
                    id="from-search"
                    v-model="fromQuery"
                    type="text"
                    placeholder="Search by code or name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="fromResults.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                >
                    <li
                        v-for="location in fromResults"
                        :key="location.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        @click="pickFrom(location)"
                    >
                        {{ location.code }} — {{ location.name }}
                    </li>
                </ul>
                <p v-if="form.errors.from_location_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.from_location_id }}
                </p>
            </div>

            <div class="relative">
                <label class="block text-sm text-slate-600" for="to-search">To Location</label>
                <input
                    id="to-search"
                    v-model="toQuery"
                    type="text"
                    placeholder="Search by code or name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="toResults.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                >
                    <li
                        v-for="location in toResults"
                        :key="location.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        @click="pickTo(location)"
                    >
                        {{ location.code }} — {{ location.name }}
                    </li>
                </ul>
                <p v-if="form.errors.to_location_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.to_location_id }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="quantity">Quantity</label>
                <input
                    id="quantity"
                    v-model="form.quantity"
                    type="text"
                    inputmode="decimal"
                    placeholder="e.g. 10 or 1.500"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.quantity" class="mt-1 text-sm text-red-600">
                    {{ form.errors.quantity }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Transferring…' : 'Transfer' }}
                </button>
                <a class="text-sm underline" href="/app/inventory-stock">Cancel</a>
            </div>
        </form>
    </main>
</template>
