<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { formatMoney, sumAmounts } from '../../../../money';

interface OutletOption {
    id: string;
    code: string;
    name: string;
}

interface StudentCandidate {
    id: string;
    studentNumber: string;
    firstName: string;
    lastName: string | null;
}

interface CanteenItemOption {
    id: string;
    code: string;
    name: string;
    price: string;
}

interface OrderLine {
    canteenItemId: string;
    code: string;
    name: string;
    price: string;
    quantity: number;
}

interface Props {
    outlets: OutletOption[];
}

defineProps<Props>();

const form = useForm({
    student_id: '',
    outlet_id: '',
    lines: [] as Array<{ canteen_item_id: string; quantity: number }>,
});

// --- Student search -----------------------------------------------------

const selectedStudentLabel = ref('');
const studentQuery = ref('');
const studentResults = ref<StudentCandidate[]>([]);
let studentDebounce: ReturnType<typeof setTimeout> | undefined;

function candidateName(s: StudentCandidate): string {
    return [s.firstName, s.lastName].filter(Boolean).join(' ');
}

watch(studentQuery, (value) => {
    clearTimeout(studentDebounce);
    if (value.trim().length < 2) {
        studentResults.value = [];
        return;
    }
    studentDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/canteen-orders/search/students?q=${encodeURIComponent(value)}`,
        );
        const body = await response.json();
        studentResults.value = body.data as StudentCandidate[];
    }, 300);
});

function selectStudent(candidate: StudentCandidate): void {
    form.student_id = candidate.id;
    selectedStudentLabel.value = `${candidateName(candidate)} (${candidate.studentNumber})`;
    studentQuery.value = '';
    studentResults.value = [];
}

function clearStudent(): void {
    form.student_id = '';
    selectedStudentLabel.value = '';
}

// --- Item line builder ----------------------------------------------------

const orderLines = ref<OrderLine[]>([]);
const itemQuery = ref('');
const itemResults = ref<CanteenItemOption[]>([]);
let itemDebounce: ReturnType<typeof setTimeout> | undefined;

watch(itemQuery, (value) => {
    clearTimeout(itemDebounce);
    itemDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/canteen-orders/search/items?q=${encodeURIComponent(value)}`,
        );
        const body = await response.json();
        itemResults.value = body.data as CanteenItemOption[];
    }, 250);
});

function addLine(option: CanteenItemOption): void {
    itemQuery.value = '';
    itemResults.value = [];

    const existing = orderLines.value.find((line) => line.canteenItemId === option.id);
    if (existing) {
        existing.quantity += 1;
    } else {
        orderLines.value.push({
            canteenItemId: option.id,
            code: option.code,
            name: option.name,
            price: option.price,
            quantity: 1,
        });
    }
    syncLines();
}

function removeLine(line: OrderLine): void {
    orderLines.value = orderLines.value.filter((l) => l.canteenItemId !== line.canteenItemId);
    syncLines();
}

function syncLines(): void {
    form.lines = orderLines.value.map((l) => ({
        canteen_item_id: l.canteenItemId,
        quantity: l.quantity,
    }));
}

watch(
    orderLines,
    () => {
        syncLines();
    },
    { deep: true },
);

// Client-side PREVIEW only (money.ts's own docblock) -- the server is
// authoritative for price and total; this never gets submitted. Each
// line's price is repeated `quantity` times so `sumAmounts` (exact
// BigInt-cents arithmetic, never a float) does the only addition.
const runningTotal = computed(() =>
    sumAmounts(orderLines.value.flatMap((line) => Array(line.quantity).fill(line.price))),
);

function submit(): void {
    form.post('/app/canteen-orders');
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/canteen-orders">← Canteen orders</a>
        <h1 class="mt-2 text-xl font-semibold">Place order</h1>

        <form class="mt-6 space-y-6" @submit.prevent="submit">
            <div class="relative">
                <label class="block text-sm text-slate-600" for="student">Student</label>
                <div
                    v-if="selectedStudentLabel"
                    class="mt-1 flex items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <span>{{ selectedStudentLabel }}</span>
                    <button
                        type="button"
                        class="text-slate-400 hover:text-slate-700"
                        @click="clearStudent"
                    >
                        Change
                    </button>
                </div>
                <input
                    v-else
                    id="student"
                    v-model="studentQuery"
                    type="text"
                    placeholder="Search by name or student number…"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="studentResults.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow-sm"
                >
                    <li v-for="candidate in studentResults" :key="candidate.id">
                        <button
                            type="button"
                            class="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50"
                            @click="selectStudent(candidate)"
                        >
                            {{ candidateName(candidate) }}
                            <span class="text-xs text-slate-400"
                                >({{ candidate.studentNumber }})</span
                            >
                        </button>
                    </li>
                </ul>
                <p v-if="form.errors.student_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.student_id }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="outlet_id">Outlet</label>
                <select
                    id="outlet_id"
                    v-model="form.outlet_id"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="" disabled>Select an Outlet</option>
                    <option v-for="outlet in outlets" :key="outlet.id" :value="outlet.id">
                        {{ outlet.code }} — {{ outlet.name }}
                    </option>
                </select>
                <p v-if="form.errors.outlet_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.outlet_id }}
                </p>
            </div>

            <div class="relative">
                <label class="block text-sm text-slate-600" for="item-search">Add item</label>
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
                        v-for="option in itemResults"
                        :key="option.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        @click="addLine(option)"
                    >
                        {{ option.code }} — {{ option.name }} ({{
                            formatMoney(option.price, 'INR')
                        }})
                    </li>
                </ul>
                <p v-if="form.errors.lines" class="mt-1 text-sm text-red-600">
                    {{ form.errors.lines }}
                </p>
            </div>

            <table v-if="orderLines.length > 0" class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Item</th>
                        <th scope="col" class="py-2 font-medium">Quantity</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="line in orderLines" :key="line.canteenItemId">
                        <td class="py-2">{{ line.code }} — {{ line.name }}</td>
                        <td class="py-2">
                            <input
                                v-model.number="line.quantity"
                                type="number"
                                min="1"
                                class="w-20 rounded border border-slate-300 px-2 py-1 text-sm"
                            />
                        </td>
                        <td class="py-2 text-right">
                            <button
                                type="button"
                                class="text-sm text-red-600 underline"
                                @click="removeLine(line)"
                            >
                                Remove
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-if="orderLines.length > 0" class="text-sm text-slate-600">
                Estimated total (client preview only, server is authoritative):
                <span class="font-mono font-medium">{{ formatMoney(runningTotal, 'INR') }}</span>
            </p>

            <button
                type="submit"
                :disabled="form.processing || !form.student_id || orderLines.length === 0"
                class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
            >
                {{ form.processing ? 'Placing…' : 'Place order' }}
            </button>
        </form>
    </main>
</template>
