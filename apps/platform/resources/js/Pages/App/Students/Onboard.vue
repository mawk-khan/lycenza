<script setup lang="ts">
// ORG.7F-2 (ADR 0076 §46): atomic Campus onboarding -- a NEW Student and their
// current placement together. The Student number is allocated by the server
// (never typed); date of birth is entered once and never shown back here. No
// Guardian, status or existing Student is ever part of it.
import { useForm } from '@inertiajs/vue3';

interface Props {
    sections: Array<{ id: string; label: string }>;
}

defineProps<Props>();

const form = useForm({
    first_name: '',
    middle_name: '',
    last_name: '',
    date_of_birth: '',
    section_id: '',
    roll_number: '',
});

function submit(): void {
    form.post('/app/students/onboard');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/students">← Students</a>
        <h1 class="mt-2 text-xl font-semibold">Onboard a new student</h1>
        <p class="mt-1 text-sm text-slate-500">
            The student number is assigned automatically when the student is created.
        </p>

        <p v-if="sections.length === 0" class="mt-6 text-sm text-slate-600">
            No active class of your campus is open in the current academic year.
        </p>

        <form v-else class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label for="first_name" class="block text-sm font-medium">First name</label>
                <input
                    id="first_name"
                    v-model="form.first_name"
                    type="text"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.first_name" class="mt-1 text-sm text-red-700">
                    {{ form.errors.first_name }}
                </p>
            </div>
            <div>
                <label for="middle_name" class="block text-sm font-medium">Middle name</label>
                <input
                    id="middle_name"
                    v-model="form.middle_name"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <div>
                <label for="last_name" class="block text-sm font-medium">Last name</label>
                <input
                    id="last_name"
                    v-model="form.last_name"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <div>
                <label for="date_of_birth" class="block text-sm font-medium">Date of birth</label>
                <input
                    id="date_of_birth"
                    v-model="form.date_of_birth"
                    type="date"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.date_of_birth" class="mt-1 text-sm text-red-700">
                    {{ form.errors.date_of_birth }}
                </p>
            </div>
            <div>
                <label for="section_id" class="block text-sm font-medium">Class</label>
                <select
                    id="section_id"
                    v-model="form.section_id"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="" disabled>Choose a class</option>
                    <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.label }}</option>
                </select>
                <p v-if="form.errors.section_id" class="mt-1 text-sm text-red-700">
                    {{ form.errors.section_id }}
                </p>
            </div>
            <div>
                <label for="roll_number" class="block text-sm font-medium">Roll number</label>
                <input
                    id="roll_number"
                    v-model="form.roll_number"
                    type="text"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.roll_number" class="mt-1 text-sm text-red-700">
                    {{ form.errors.roll_number }}
                </p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
            >
                {{ form.processing ? 'Onboarding…' : 'Onboard student' }}
            </button>
        </form>
    </main>
</template>
