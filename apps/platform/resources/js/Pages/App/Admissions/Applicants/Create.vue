<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import TextField from '../../../../Components/Form/TextField.vue';

// SIS 3.2: the same labelled fields (and the same "not in the future" date of
// birth, by the School's date) as the correction form.
const props = defineProps<{ today: string }>();

const form = useForm({
    first_name: '',
    middle_name: '',
    last_name: '',
    date_of_birth: '',
});

function submit(): void {
    form.post('/app/admissions/applicants');
}
</script>

<template>
    <main class="mx-auto max-w-xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/admissions/applicants">← Applicants</a>
        <h1 class="mt-2 text-xl font-semibold">Add applicant</h1>
        <p class="mt-1 text-sm text-slate-500">
            Identity only -- the Admission Application (Academic Year, Campus, Grade) is created
            separately, once you know what this applicant is applying for.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <TextField
                    id="first_name"
                    v-model="form.first_name"
                    label="First name"
                    required
                    :maxlength="255"
                    :error="form.errors.first_name"
                />
                <TextField
                    id="middle_name"
                    v-model="form.middle_name"
                    label="Middle name"
                    :maxlength="255"
                    :error="form.errors.middle_name"
                />
                <TextField
                    id="last_name"
                    v-model="form.last_name"
                    label="Last name"
                    :maxlength="255"
                    :error="form.errors.last_name"
                />
            </div>
            <TextField
                id="date_of_birth"
                v-model="form.date_of_birth"
                type="date"
                label="Date of birth"
                required
                :max="props.today"
                :error="form.errors.date_of_birth"
            />

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Add applicant' }}
                </button>
                <a class="text-sm underline" href="/app/admissions/applicants">Cancel</a>
            </div>
        </form>
    </main>
</template>
