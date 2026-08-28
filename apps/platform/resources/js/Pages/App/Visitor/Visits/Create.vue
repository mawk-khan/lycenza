<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface VisitorOption {
    id: string;
    fullName: string;
    phone: string | null;
}

interface HostOption {
    id: string;
    fullName: string;
}

interface CampusOption {
    id: string;
    name: string;
}

interface Props {
    campuses: CampusOption[];
}

defineProps<Props>();

const form = useForm({
    visitor_id: '',
    campus_id: '',
    host_employee_id: '',
    purpose: '',
    gate_pass_number: '',
});

const visitorQuery = ref('');
const visitorResults = ref<VisitorOption[]>([]);
const selectedVisitor = ref<VisitorOption | null>(null);

const hostQuery = ref('');
const hostResults = ref<HostOption[]>([]);
const selectedHost = ref<HostOption | null>(null);

let visitorDebounce: ReturnType<typeof setTimeout> | undefined;
let hostDebounce: ReturnType<typeof setTimeout> | undefined;

watch(visitorQuery, (value) => {
    clearTimeout(visitorDebounce);
    visitorDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/visitor/visits/search/visitors?q=${encodeURIComponent(value)}`,
        );
        const body = await response.json();
        visitorResults.value = body.data;
    }, 250);
});

watch(hostQuery, (value) => {
    clearTimeout(hostDebounce);
    hostDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/visitor/visits/search/hosts?q=${encodeURIComponent(value)}`,
        );
        const body = await response.json();
        hostResults.value = body.data;
    }, 250);
});

function pickVisitor(visitor: VisitorOption): void {
    selectedVisitor.value = visitor;
    form.visitor_id = visitor.id;
    visitorResults.value = [];
    visitorQuery.value = visitor.fullName;
}

function pickHost(host: HostOption): void {
    selectedHost.value = host;
    form.host_employee_id = host.id;
    hostResults.value = [];
    hostQuery.value = host.fullName;
}

function clearHost(): void {
    selectedHost.value = null;
    form.host_employee_id = '';
    hostQuery.value = '';
}

function submit(): void {
    form.post('/app/visitor/visits');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/visitor/visits">← Visits</a>
        <h1 class="mt-2 text-xl font-semibold">Check in a Visitor</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div class="relative">
                <label class="block text-sm text-slate-600" for="visitor-search">Visitor</label>
                <input
                    id="visitor-search"
                    v-model="visitorQuery"
                    type="text"
                    placeholder="Search by name or phone, or add a new Visitor first"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="visitorResults.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                >
                    <li
                        v-for="visitor in visitorResults"
                        :key="visitor.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        @click="pickVisitor(visitor)"
                    >
                        {{ visitor.fullName
                        }}<span v-if="visitor.phone"> ({{ visitor.phone }})</span>
                    </li>
                </ul>
                <p v-if="form.errors.visitor_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.visitor_id }}
                </p>
                <p class="mt-1 text-xs text-slate-400">
                    Visitor not listed?
                    <a class="underline" href="/app/visitor/directory/create"
                        >Add them to the directory</a
                    >
                    first.
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="campus">Campus</label>
                <select
                    id="campus"
                    v-model="form.campus_id"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="">— Select —</option>
                    <option v-for="campus in campuses" :key="campus.id" :value="campus.id">
                        {{ campus.name }}
                    </option>
                </select>
                <p v-if="form.errors.campus_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.campus_id }}
                </p>
            </div>

            <div class="relative">
                <label class="block text-sm text-slate-600" for="host-search"
                    >Host (optional)</label
                >
                <input
                    id="host-search"
                    v-model="hostQuery"
                    type="text"
                    placeholder="Search by Employee name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="hostResults.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                >
                    <li
                        v-for="host in hostResults"
                        :key="host.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        @click="pickHost(host)"
                    >
                        {{ host.fullName }}
                    </li>
                </ul>
                <button
                    v-if="selectedHost"
                    type="button"
                    class="mt-1 text-xs text-slate-500 underline"
                    @click="clearHost"
                >
                    Clear host
                </button>
                <p v-if="form.errors.host_employee_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.host_employee_id }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="purpose">Purpose</label>
                <textarea
                    id="purpose"
                    v-model="form.purpose"
                    rows="3"
                    maxlength="500"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                ></textarea>
                <p v-if="form.errors.purpose" class="mt-1 text-sm text-red-600">
                    {{ form.errors.purpose }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="gate-pass">
                    Gate pass number (optional)
                </label>
                <input
                    id="gate-pass"
                    v-model="form.gate_pass_number"
                    type="text"
                    class="mt-1 w-48 rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.gate_pass_number" class="mt-1 text-sm text-red-600">
                    {{ form.errors.gate_pass_number }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Checking in…' : 'Check in' }}
                </button>
                <a class="text-sm underline" href="/app/visitor/visits">Cancel</a>
            </div>
        </form>
    </main>
</template>
