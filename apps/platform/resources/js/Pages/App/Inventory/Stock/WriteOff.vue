<script setup lang="ts">
// ORG.9C-2 (ADR 0076 §70): writing stock off names a listed reason, an
// explicit confirmation and a fresh authentication code. The server
// enforces every part and re-reads the actor's authority; the reasons come
// from the server.
import MfaCodeField from '../../../../Components/MfaCodeField.vue';
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

const props = defineProps<{
    reasons: Record<string, string>;
    hasMfaFactor: boolean;
    idempotencyKey: string;
}>();

const form = useForm({
    inventory_item_id: '',
    inventory_location_id: '',
    quantity: '',
    reason_code: '',
    confirm: false,
    mfa_code: '',
    // The server-issued key this submission is claimed on (a retry replays).
    idempotency_key: props.idempotencyKey,
});

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
    form.post('/app/inventory-stock/write-off', { onError: () => form.reset('mfa_code') });
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/inventory-stock">← Inventory stock</a>
        <h1 class="mt-2 text-xl font-semibold">Write off stock</h1>
        <p class="mt-1 text-sm text-slate-500">
            For stock that is damaged, expired or lost. A write-off is permanent: it stays in the
            movement history and cannot be edited or deleted. If the stock turns up again, record it
            as a new receipt.
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
                    placeholder="e.g. 2 or 0.500"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.quantity" class="mt-1 text-sm text-red-600">
                    {{ form.errors.quantity }}
                </p>
            </div>

            <fieldset>
                <legend class="text-sm text-slate-600">Reason</legend>
                <div class="mt-1 space-y-1">
                    <label
                        v-for="(label, code) in reasons"
                        :key="code"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            v-model="form.reason_code"
                            type="radio"
                            name="reason_code"
                            :value="code"
                        />
                        {{ label }}
                    </label>
                </div>
                <p v-if="form.errors.reason_code" class="mt-1 text-sm text-red-600">
                    {{ form.errors.reason_code }}
                </p>
            </fieldset>

            <div>
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.confirm" type="checkbox" class="mt-1" />
                    I confirm these units are no longer usable or present and should be removed from
                    stock permanently.
                </label>
                <p v-if="form.errors.confirm" class="mt-1 text-sm text-red-600">
                    {{ form.errors.confirm }}
                </p>
            </div>

            <MfaCodeField
                id="write-off-mfa"
                v-model="form.mfa_code"
                class="w-72"
                :has-mfa-factor="hasMfaFactor"
                action="Writing off stock"
                :error="form.errors.mfa_code"
            />

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="
                        form.processing ||
                        !form.confirm ||
                        form.reason_code === '' ||
                        form.mfa_code === ''
                    "
                    class="rounded bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Writing off…' : 'Write off' }}
                </button>
                <a class="text-sm underline" href="/app/inventory-stock">Cancel</a>
            </div>
        </form>
    </main>
</template>
