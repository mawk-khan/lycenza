<script setup lang="ts">
import SearchCombobox from '../../../../Components/Form/SearchCombobox.vue';
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

interface DepartmentOption {
    id: string;
    code: string;
    name: string;
}

const props = defineProps<{ idempotencyKey: string }>();

const form = useForm({
    inventory_item_id: '',
    inventory_location_id: '',
    quantity: '',
    recipient_department_id: '' as string | null,
    // ORG.9C-2: the server-issued key this submission is claimed on (a retry replays).
    idempotency_key: props.idempotencyKey,
});

const departmentQuery = ref('');
const departmentResults = ref<DepartmentOption[]>([]);
let departmentDebounce: ReturnType<typeof setTimeout> | undefined;

watch(departmentQuery, (value) => {
    clearTimeout(departmentDebounce);
    if (value === '') {
        form.recipient_department_id = '';
    }
    departmentDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/inventory-stock/search/departments?q=${encodeURIComponent(value)}`,
        );
        const body = response.ok ? await response.json().catch(() => ({ data: [] })) : { data: [] };
        departmentResults.value = body.data;
    }, 250);
});

function pickDepartment(department: DepartmentOption): void {
    form.recipient_department_id = department.id;
    departmentResults.value = [];
    departmentQuery.value = `${department.code} — ${department.name}`;
}

const itemQuery = ref('');
const itemResults = ref<ItemOption[]>([]);

const locationQuery = ref('');
const locationResults = ref<LocationOption[]>([]);

let itemDebounce: ReturnType<typeof setTimeout> | undefined;
let locationDebounce: ReturnType<typeof setTimeout> | undefined;

watch(itemQuery, (value) => {
    clearTimeout(itemDebounce);
    itemDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/inventory-stock/search/items?q=${encodeURIComponent(value)}`,
        );
        // UAT-C4: a refused or non-JSON answer is simply no results.
        const body = response.ok ? await response.json().catch(() => ({ data: [] })) : { data: [] };
        itemResults.value = body.data;
    }, 250);
});

watch(locationQuery, (value) => {
    clearTimeout(locationDebounce);
    locationDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/inventory-stock/search/locations?q=${encodeURIComponent(value)}`,
        );
        // UAT-C4: a refused or non-JSON answer is simply no results.
        const body = response.ok ? await response.json().catch(() => ({ data: [] })) : { data: [] };
        locationResults.value = body.data;
    }, 250);
});

function pickItem(item: ItemOption): void {
    form.inventory_item_id = item.id;
    itemResults.value = [];
    itemQuery.value = `${item.code} — ${item.name}`;
}

function pickLocation(location: LocationOption): void {
    form.inventory_location_id = location.id;
    locationResults.value = [];
    locationQuery.value = `${location.code} — ${location.name}`;
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        recipient_department_id:
            data.recipient_department_id === '' ? null : data.recipient_department_id,
    })).post('/app/inventory-stock/issue');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/inventory-stock">← Inventory stock</a>
        <h1 class="mt-2 text-xl font-semibold">Issue stock</h1>
        <p class="mt-1 text-sm text-slate-500">
            Stock used up or handed out for use. Moving stock to another location is a transfer.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div class="relative">
                <label class="block text-sm text-slate-600" for="item-search">Item</label>
                <SearchCombobox
                    id="item-search"
                    v-model="itemQuery"
                    placeholder="Search by code or name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    :options="itemResults"
                    :option-key="(o) => o.id"
                    :error="form.errors.inventory_item_id"
                    @select="pickItem"
                >
                    <template #option="{ option: item }">
                        {{ item.code }} — {{ item.name }} ({{ item.unitOfMeasure }})
                    </template>
                </SearchCombobox>
            </div>

            <div class="relative">
                <label class="block text-sm text-slate-600" for="location-search">Location</label>
                <SearchCombobox
                    id="location-search"
                    v-model="locationQuery"
                    placeholder="Search by code or name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    :options="locationResults"
                    :option-key="(o) => o.id"
                    :error="form.errors.inventory_location_id"
                    @select="pickLocation"
                >
                    <template #option="{ option: location }">
                        {{ location.code }} — {{ location.name }}
                    </template>
                </SearchCombobox>
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

            <div class="relative">
                <label class="block text-sm text-slate-600" for="department-search">
                    Issued to department (optional)
                </label>
                <SearchCombobox
                    id="department-search"
                    v-model="departmentQuery"
                    placeholder="Search by code or name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    :options="departmentResults"
                    :option-key="(o) => o.id"
                    :error="form.errors.recipient_department_id"
                    @select="pickDepartment"
                >
                    <template #option="{ option: department }">
                        {{ department.code }} — {{ department.name }}
                    </template>
                </SearchCombobox>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Issuing…' : 'Issue' }}
                </button>
                <a class="text-sm underline" href="/app/inventory-stock">Cancel</a>
            </div>
        </form>
    </main>
</template>
