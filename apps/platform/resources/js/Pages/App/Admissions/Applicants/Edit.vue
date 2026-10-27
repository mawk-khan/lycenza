<script setup lang="ts">
// SIS 3.1: correct an Applicant's identity (until an application is
// converted -- after that the Student record is the one to correct).
import { Head, useForm } from '@inertiajs/vue3';
import ErrorSummary from '../../../../Components/Form/ErrorSummary.vue';
import TextField from '../../../../Components/Form/TextField.vue';

interface Props {
    applicant: {
        id: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
        dateOfBirth: string;
        updatedAt: string;
    };
    // SIS 3.2: the School's date -- a date of birth is never later.
    today: string;
}

const props = defineProps<Props>();

const form = useForm({
    first_name: props.applicant.firstName,
    middle_name: props.applicant.middleName ?? '',
    last_name: props.applicant.lastName ?? '',
    date_of_birth: props.applicant.dateOfBirth,
    expected_updated_at: props.applicant.updatedAt,
});

function submit(): void {
    form.put(`/app/admissions/applicants/${props.applicant.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Correct applicant" />
    <main class="mx-auto max-w-xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" :href="`/app/admissions/applicants/${applicant.id}`">← Back</a>
        <h1 class="mt-2 text-xl font-semibold">Correct applicant details</h1>
        <p class="mt-1 text-sm text-slate-500">
            For a typing mistake. Every application of this applicant shows the corrected details;
            the change is recorded in the audit log.
        </p>

        <ErrorSummary :errors="form.errors" :fields="['correction', 'expected_updated_at']" />

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
                :max="today"
                :error="form.errors.date_of_birth"
            />

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Save correction' }}
                </button>
                <a class="text-sm underline" :href="`/app/admissions/applicants/${applicant.id}`"
                    >Cancel</a
                >
            </div>
        </form>
    </main>
</template>
