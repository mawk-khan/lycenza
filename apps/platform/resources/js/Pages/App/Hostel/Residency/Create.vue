<script setup lang="ts">
import SearchCombobox from '../../../../Components/Form/SearchCombobox.vue';
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface StudentOption {
    id: string;
    studentNumber: string;
    name: string;
}

interface BedOption {
    id: string;
    code: string;
    roomCode: string;
    hostelName: string;
}

const form = useForm({
    student_id: '',
    hostel_bed_id: '',
});

const studentQuery = ref('');
const studentResults = ref<StudentOption[]>([]);
const selectedStudent = ref<StudentOption | null>(null);

const bedQuery = ref('');
const bedResults = ref<BedOption[]>([]);
const selectedBed = ref<BedOption | null>(null);

let studentDebounce: ReturnType<typeof setTimeout> | undefined;
let bedDebounce: ReturnType<typeof setTimeout> | undefined;

watch(studentQuery, (value) => {
    clearTimeout(studentDebounce);
    studentDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/hostel-residency/search/students?q=${encodeURIComponent(value)}`,
        );
        // UAT-C4: a refused or non-JSON answer is simply no results.
        const body = response.ok ? await response.json().catch(() => ({ data: [] })) : { data: [] };
        studentResults.value = body.data;
    }, 250);
});

watch(bedQuery, (value) => {
    clearTimeout(bedDebounce);
    bedDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/hostel-residency/search/beds?q=${encodeURIComponent(value)}`,
        );
        // UAT-C4: a refused or non-JSON answer is simply no results.
        const body = response.ok ? await response.json().catch(() => ({ data: [] })) : { data: [] };
        bedResults.value = body.data;
    }, 250);
});

function pickStudent(student: StudentOption): void {
    selectedStudent.value = student;
    form.student_id = student.id;
    studentResults.value = [];
    studentQuery.value = `${student.name} (${student.studentNumber})`;
}

function pickBed(bed: BedOption): void {
    selectedBed.value = bed;
    form.hostel_bed_id = bed.id;
    bedResults.value = [];
    bedQuery.value = `${bed.hostelName} / ${bed.roomCode} / Bed ${bed.code}`;
}

function submit(): void {
    form.post('/app/hostel-residency');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/hostel-residency">← Hostel residency</a>
        <h1 class="mt-2 text-xl font-semibold">Assign a Student to a Hostel Bed</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div class="relative">
                <label class="block text-sm text-slate-600" for="student-search">Student</label>
                <SearchCombobox
                    id="student-search"
                    v-model="studentQuery"
                    placeholder="Search by name or student number"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    :options="studentResults"
                    :option-key="(o) => o.id"
                    :error="form.errors.student_id"
                    @select="pickStudent"
                >
                    <template #option="{ option: student }">
                        {{ student.name }} ({{ student.studentNumber }})
                    </template>
                </SearchCombobox>
            </div>

            <div class="relative">
                <label class="block text-sm text-slate-600" for="bed-search">Bed</label>
                <SearchCombobox
                    id="bed-search"
                    v-model="bedQuery"
                    placeholder="Search by bed code"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    :options="bedResults"
                    :option-key="(o) => o.id"
                    :error="form.errors.hostel_bed_id"
                    @select="pickBed"
                >
                    <template #option="{ option: bed }">
                        {{ bed.hostelName }} / {{ bed.roomCode }} / Bed {{ bed.code }}
                    </template>
                </SearchCombobox>
                <p class="mt-1 text-xs text-slate-400">Only available (unoccupied) Beds appear.</p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Assigning…' : 'Assign' }}
                </button>
                <a class="text-sm underline" href="/app/hostel-residency">Cancel</a>
            </div>
        </form>
    </main>
</template>
