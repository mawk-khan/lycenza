<script setup lang="ts">
import SearchCombobox from '../../../../Components/Form/SearchCombobox.vue';
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';
import { formatMoney } from '../../../../money';

interface RecipeRow {
    id: string;
    inventoryItemId: string;
    inventoryItemCode: string;
    inventoryItemName: string;
    quantityRequired: string;
}

interface ItemDetail {
    id: string;
    code: string;
    name: string;
    price: string;
    currency: string;
    status: 'active' | 'inactive';
    recipe: RecipeRow[];
}

interface Props {
    item: ItemDetail;
    canManage: boolean;
}

const props = defineProps<Props>();

function toggleStatus(): void {
    router.patch(`/app/canteen-items/${props.item.id}`, {
        status: props.item.status === 'active' ? 'inactive' : 'active',
    });
}

// Phase 10F fix: uses the Canteen-scoped `/app/canteen-items/search/
// inventory-items` endpoint (CanteenItemController::searchInventoryItems()),
// gated by `canteen.directory.manage` -- not Inventory's own
// `inventory.stock.manage`-gated `/app/inventory-stock/search/items`
// endpoint this page used to call. A user who holds only
// `canteen.directory.manage` can now compose a recipe here.
interface InventoryItemOption {
    id: string;
    code: string;
    name: string;
    unitOfMeasure: string;
}

const inventoryItemQuery = ref('');
const inventoryItemResults = ref<InventoryItemOption[]>([]);
let inventoryItemDebounce: ReturnType<typeof setTimeout> | undefined;

watch(inventoryItemQuery, (value) => {
    clearTimeout(inventoryItemDebounce);
    inventoryItemDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/canteen-items/search/inventory-items?q=${encodeURIComponent(value)}`,
        );
        if (!response.ok) {
            inventoryItemResults.value = [];
            return;
        }
        // UAT-C4: a refused or non-JSON answer is simply no results.
        const body = response.ok ? await response.json().catch(() => ({ data: [] })) : { data: [] };
        inventoryItemResults.value = body.data;
    }, 250);
});

const requirementForm = useForm({
    inventory_item_id: '',
    quantity_required: '',
});
const selectedInventoryItemLabel = ref('');

function pickInventoryItem(option: InventoryItemOption): void {
    requirementForm.inventory_item_id = option.id;
    selectedInventoryItemLabel.value = `${option.code} — ${option.name} (${option.unitOfMeasure})`;
    inventoryItemQuery.value = '';
    inventoryItemResults.value = [];
}

function clearInventoryItem(): void {
    requirementForm.inventory_item_id = '';
    selectedInventoryItemLabel.value = '';
}

function addRequirement(): void {
    requirementForm.post(`/app/canteen-items/${props.item.id}/recipe`, {
        preserveScroll: true,
        onSuccess: () => {
            requirementForm.reset();
            selectedInventoryItemLabel.value = '';
        },
    });
}

function removeRequirement(requirement: RecipeRow): void {
    router.delete(`/app/canteen-items/${props.item.id}/recipe/${requirement.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/canteen-items">← Canteen items</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ item.name }}</h1>
                <p class="mt-1 text-sm text-ink-muted">
                    {{ item.code }} · {{ formatMoney(item.price, item.currency) }}
                </p>
            </div>
            <StatusBadge :status="item.status" />
        </div>

        <button v-if="canManage" type="button" class="mt-4 text-sm underline" @click="toggleStatus">
            {{ item.status === 'active' ? 'Deactivate' : 'Activate' }} this Item
        </button>

        <section class="mt-8 border-t border-line pt-6">
            <h2 class="text-sm font-semibold text-ink">Recipe</h2>
            <p class="mt-1 text-xs text-ink-muted">
                Inventory consumed from the fulfilling Outlet's Location each time one unit of this
                Item is fulfilled.
            </p>

            <EmptyState
                v-if="item.recipe.length === 0"
                class="mt-4"
                title="No recipe defined"
                description="This Item does not yet consume any Inventory when fulfilled."
            />

            <table v-else class="mt-4 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-line text-xs text-ink-muted">
                        <th scope="col" class="py-2 font-medium">Inventory Item</th>
                        <th scope="col" class="py-2 font-medium">Quantity required</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="requirement in item.recipe" :key="requirement.id">
                        <td class="py-3 font-medium">
                            {{ requirement.inventoryItemCode }} —
                            {{ requirement.inventoryItemName }}
                        </td>
                        <td class="py-3">{{ requirement.quantityRequired }}</td>
                        <td class="py-3 text-right">
                            <button
                                v-if="canManage"
                                type="button"
                                class="text-sm text-danger underline"
                                @click="removeRequirement(requirement)"
                            >
                                Remove
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <form
                v-if="canManage"
                class="mt-6 flex flex-wrap items-end gap-3"
                @submit.prevent="addRequirement"
            >
                <div class="relative">
                    <label class="block text-sm text-ink-muted" for="inventory-item-search"
                        >Inventory Item</label
                    >
                    <div
                        v-if="selectedInventoryItemLabel"
                        class="mt-1 flex items-center gap-2 rounded-xs border border-control-line px-3 py-2 text-sm"
                    >
                        <span>{{ selectedInventoryItemLabel }}</span>
                        <button
                            type="button"
                            class="text-ink-muted hover:text-ink"
                            @click="clearInventoryItem"
                        >
                            ×
                        </button>
                    </div>
                    <SearchCombobox
                        v-else
                        id="inventory-item-search"
                        v-model="inventoryItemQuery"
                        placeholder="Search by code or name"
                        class="mt-1 w-64 rounded-xs border border-control-line px-3 py-2 text-sm"
                        :options="inventoryItemResults"
                        :option-key="(o) => o.id"
                        list-class="absolute z-10 mt-1 max-h-72 w-64 overflow-y-auto rounded-xs border border-control-line bg-white text-sm text-ink shadow-md"
                        @select="pickInventoryItem"
                    >
                        <template #option="{ option: option }">
                            {{ option.code }} — {{ option.name }} ({{ option.unitOfMeasure }})
                        </template>
                    </SearchCombobox>
                    <p
                        v-if="requirementForm.errors.inventory_item_id"
                        class="mt-1 text-sm text-danger"
                    >
                        {{ requirementForm.errors.inventory_item_id }}
                    </p>
                </div>

                <div>
                    <label class="block text-sm text-ink-muted" for="quantity-required"
                        >Quantity required</label
                    >
                    <input
                        id="quantity-required"
                        v-model="requirementForm.quantity_required"
                        type="text"
                        inputmode="decimal"
                        placeholder="e.g. 1 or 0.250"
                        class="input mt-1 w-40"
                    />
                    <p
                        v-if="requirementForm.errors.quantity_required"
                        class="mt-1 text-sm text-danger"
                    >
                        {{ requirementForm.errors.quantity_required }}
                    </p>
                </div>

                <button
                    type="submit"
                    :disabled="requirementForm.processing || !requirementForm.inventory_item_id"
                    class="btn btn-primary"
                >
                    Add requirement
                </button>
            </form>
        </section>
    </main>
</template>
