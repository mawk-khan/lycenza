<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface CopyOption {
    id: string;
    code: string;
    title: string;
}

interface StudentOption {
    id: string;
    studentNumber: string;
    name: string;
}

const form = useForm({
    library_copy_id: '',
    student_id: '',
    due_at: '',
});

const copyQuery = ref('');
const copyResults = ref<CopyOption[]>([]);
const selectedCopy = ref<CopyOption | null>(null);

const studentQuery = ref('');
const studentResults = ref<StudentOption[]>([]);
const selectedStudent = ref<StudentOption | null>(null);

let copyDebounce: ReturnType<typeof setTimeout> | undefined;
let studentDebounce: ReturnType<typeof setTimeout> | undefined;

watch(copyQuery, (value) => {
    clearTimeout(copyDebounce);
    copyDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/library/circulation/search/copies?q=${encodeURIComponent(value)}`,
        );
        const body = await response.json();
        copyResults.value = body.data;
    }, 250);
});

watch(studentQuery, (value) => {
    clearTimeout(studentDebounce);
    studentDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/library/circulation/search/students?q=${encodeURIComponent(value)}`,
        );
        const body = await response.json();
        studentResults.value = body.data;
    }, 250);
});

function pickCopy(copy: CopyOption): void {
    selectedCopy.value = copy;
    form.library_copy_id = copy.id;
    copyResults.value = [];
    copyQuery.value = `${copy.title} (${copy.code})`;
}

function pickStudent(student: StudentOption): void {
    selectedStudent.value = student;
    form.student_id = student.id;
    studentResults.value = [];
    studentQuery.value = `${student.name} (${student.studentNumber})`;
}

function submit(): void {
    form.post('/app/library/circulation');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/library/circulation">← Circulation</a>
        <h1 class="mt-2 text-xl font-semibold">Check out an item</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div class="relative">
                <label class="block text-sm text-slate-600" for="copy-search">Library copy</label>
                <input
                    id="copy-search"
                    v-model="copyQuery"
                    type="text"
                    placeholder="Search by title or accession code"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <ul
                    v-if="copyResults.length > 0"
                    class="absolute z-10 mt-1 w-full rounded border border-slate-200 bg-white text-sm shadow"
                >
                    <li
                        v-for="copy in copyResults"
                        :key="copy.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        @click="pickCopy(copy)"
                    >
                        {{ copy.title }} ({{ copy.code }})
                    </li>
                </ul>
                <p v-if="form.errors.library_copy_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.library_copy_id }}
                </p>
            </div>

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

            <div>
                <label class="block text-sm text-slate-600" for="due-at">Due date</label>
                <input
                    id="due-at"
                    v-model="form.due_at"
                    type="date"
                    class="mt-1 w-48 rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.due_at" class="mt-1 text-sm text-red-600">
                    {{ form.errors.due_at }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Checking out…' : 'Check out' }}
                </button>
                <a class="text-sm underline" href="/app/library/circulation">Cancel</a>
            </div>
        </form>
    </main>
</template>
