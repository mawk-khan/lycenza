<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import StudentIdentityFields from '../../../Components/StudentIdentityFields.vue';

interface Props {
    student: {
        id: string;
        studentNumber: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
        dateOfBirth: string;
    };
}

const props = defineProps<Props>();

// dateOfBirth arrives as a plain 'YYYY-MM-DD' string from the backend
// (Student::date_of_birth->toDateString()) and is assigned straight
// into the form's string field -- no Date object is ever constructed
// from it, so no JS-timezone shift is possible (this checkpoint's
// brief, "a Student DOB is a calendar date, not a UTC timestamp").
const form = useForm({
    student_number: props.student.studentNumber,
    first_name: props.student.firstName,
    middle_name: props.student.middleName ?? '',
    last_name: props.student.lastName ?? '',
    date_of_birth: props.student.dateOfBirth,
});

function submit(): void {
    form.put(`/app/students/${props.student.id}`);
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" :href="`/app/students/${student.id}`">← Back</a>
        <h1 class="mt-2 text-xl font-semibold">Edit student</h1>

        <form class="mt-6" @submit.prevent="submit">
            <StudentIdentityFields
                v-model:student-number="form.student_number"
                v-model:first-name="form.first_name"
                v-model:middle-name="form.middle_name"
                v-model:last-name="form.last_name"
                v-model:date-of-birth="form.date_of_birth"
                :errors="form.errors"
            />

            <div class="mt-6 flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Save changes' }}
                </button>
                <a class="text-sm underline" :href="`/app/students/${student.id}`">Cancel</a>
            </div>
        </form>
    </main>
</template>
