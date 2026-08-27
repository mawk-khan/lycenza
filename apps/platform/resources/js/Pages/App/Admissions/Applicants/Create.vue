<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

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
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/admissions/applicants">← Applicants</a>
        <h1 class="mt-2 text-xl font-semibold">Add applicant</h1>
        <p class="mt-1 text-sm text-slate-500">
            Identity only -- the Admission Application (Academic Year, Campus, Grade) is created
            separately, once you know what this applicant is applying for.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700" for="first_name"
                        >First name</label
                    >
                    <input
                        id="first_name"
                        v-model="form.first_name"
                        type="text"
                        required
                        :aria-invalid="!!form.errors.first_name"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700" for="middle_name"
                        >Middle name</label
                    >
                    <input
                        id="middle_name"
                        v-model="form.middle_name"
                        type="text"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700" for="last_name"
                        >Last name</label
                    >
                    <input
                        id="last_name"
                        v-model="form.last_name"
                        type="text"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                </div>
            </div>
            <p class="text-sm text-red-600">{{ form.errors.first_name }}</p>

            <div>
                <label class="block text-sm font-medium text-slate-700" for="date_of_birth"
                    >Date of birth</label
                >
                <input
                    id="date_of_birth"
                    v-model="form.date_of_birth"
                    type="date"
                    required
                    :aria-invalid="!!form.errors.date_of_birth"
                    aria-describedby="date_of_birth-error"
                    class="mt-1 rounded border border-slate-300 px-3 py-2 sm:w-56"
                />
                <p id="date_of_birth-error" class="mt-1 text-sm text-red-600">
                    {{ form.errors.date_of_birth }}
                </p>
            </div>

            <div class="flex items-center gap-3">
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
