<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import StudentIdentityFields from '../../../Components/StudentIdentityFields.vue';

const form = useForm({
    student_number: '',
    first_name: '',
    middle_name: '',
    last_name: '',
    date_of_birth: '',
});

function submit(): void {
    form.post('/app/students');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/students">← Students</a>
        <h1 class="mt-2 text-xl font-semibold">Add student</h1>
        <p class="mt-1 text-sm text-slate-500">
            Identity only -- grade, section, and enrollment are configured later.
        </p>

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
                    {{ form.processing ? 'Saving…' : 'Add student' }}
                </button>
                <a class="text-sm underline" href="/app/students">Cancel</a>
            </div>
        </form>
    </main>
</template>
