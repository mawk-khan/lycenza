<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface StudentOption {
    id: string;
    studentNumber: string;
    name: string;
}

interface RouteOption {
    id: string;
    code: string;
    name: string;
}

interface StopOption {
    id: string;
    name: string;
    sequence: number;
}

const form = useForm({
    student_id: '',
    route_id: '',
    pickup_stop_id: '',
    dropoff_stop_id: '',
});

const studentQuery = ref('');
const studentResults = ref<StudentOption[]>([]);
const selectedStudent = ref<StudentOption | null>(null);

const routeQuery = ref('');
const routeResults = ref<RouteOption[]>([]);
const selectedRoute = ref<RouteOption | null>(null);

const stops = ref<StopOption[]>([]);

let studentDebounce: ReturnType<typeof setTimeout> | undefined;
let routeDebounce: ReturnType<typeof setTimeout> | undefined;

watch(studentQuery, (value) => {
    clearTimeout(studentDebounce);
    studentDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/transport/assignments/search/students?q=${encodeURIComponent(value)}`,
        );
        const body = await response.json();
        studentResults.value = body.data;
    }, 250);
});

watch(routeQuery, (value) => {
    clearTimeout(routeDebounce);
    routeDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/transport/assignments/search/routes?q=${encodeURIComponent(value)}`,
        );
        const body = await response.json();
        routeResults.value = body.data;
    }, 250);
});

function pickStudent(student: StudentOption): void {
    selectedStudent.value = student;
    form.student_id = student.id;
    studentResults.value = [];
    studentQuery.value = `${student.name} (${student.studentNumber})`;
}

async function pickRoute(route: RouteOption): Promise<void> {
    selectedRoute.value = route;
    form.route_id = route.id;
    form.pickup_stop_id = '';
    form.dropoff_stop_id = '';
    routeResults.value = [];
    routeQuery.value = `${route.name} (${route.code})`;

    const response = await fetch(`/app/transport/assignments/routes/${route.id}/stops`);
    const body = await response.json();
    stops.value = body.data;
}

function submit(): void {
    form.post('/app/transport/assignments');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/transport/assignments">← Transport assignments</a>
        <h1 class="mt-2 text-xl font-semibold">Assign a Student to Transport</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div class="relative">
                <label class="block text-sm text-slate-600" for="student-search">Student</label>
                <input
                    id="student-search"
                    v-model="studentQuery"
                    type="text"
                    placeholder="Search by name or student number"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="studentResults.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                >
                    <li
                        v-for="student in studentResults"
                        :key="student.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        @click="pickStudent(student)"
                    >
                        {{ student.name }} ({{ student.studentNumber }})
                    </li>
                </ul>
                <p v-if="form.errors.student_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.student_id }}
                </p>
            </div>

            <div class="relative">
                <label class="block text-sm text-slate-600" for="route-search">Route</label>
                <input
                    id="route-search"
                    v-model="routeQuery"
                    type="text"
                    placeholder="Search by name or code"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="routeResults.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                >
                    <li
                        v-for="route in routeResults"
                        :key="route.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        @click="pickRoute(route)"
                    >
                        {{ route.name }} ({{ route.code }})
                    </li>
                </ul>
                <p v-if="form.errors.route_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.route_id }}
                </p>
            </div>

            <div v-if="stops.length > 0">
                <label class="block text-sm text-slate-600" for="pickup-stop">
                    Pickup stop (optional)
                </label>
                <select
                    id="pickup-stop"
                    v-model="form.pickup_stop_id"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="">— None —</option>
                    <option v-for="stop in stops" :key="stop.id" :value="stop.id">
                        {{ stop.sequence }}. {{ stop.name }}
                    </option>
                </select>
                <p v-if="form.errors.pickup_stop_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.pickup_stop_id }}
                </p>
            </div>

            <div v-if="stops.length > 0">
                <label class="block text-sm text-slate-600" for="dropoff-stop">
                    Drop-off stop (optional)
                </label>
                <select
                    id="dropoff-stop"
                    v-model="form.dropoff_stop_id"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="">— None —</option>
                    <option v-for="stop in stops" :key="stop.id" :value="stop.id">
                        {{ stop.sequence }}. {{ stop.name }}
                    </option>
                </select>
                <p v-if="form.errors.dropoff_stop_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.dropoff_stop_id }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Assigning…' : 'Assign' }}
                </button>
                <a class="text-sm underline" href="/app/transport/assignments">Cancel</a>
            </div>
        </form>
    </main>
</template>
