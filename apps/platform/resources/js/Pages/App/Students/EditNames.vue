<script setup lang="ts">
// ORG.7F-2 (ADR 0076 §46): the minimal name edit -- first, middle and last
// name only. The Student number, date of birth and status are never edited
// here; the server re-decides the editor's Campus scope under lock.
import { useForm } from '@inertiajs/vue3';

interface Props {
    student: {
        id: string;
        studentNumber: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
    };
}

const props = defineProps<Props>();

const form = useForm({
    first_name: props.student.firstName,
    middle_name: props.student.middleName ?? '',
    last_name: props.student.lastName ?? '',
});

function submit(): void {
    form.put(`/app/students/${props.student.id}/names`);
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" :href="`/app/students/${student.id}/overview`">← Back</a>
        <h1 class="mt-2 text-xl font-semibold">Edit names</h1>
        <p class="mt-1 text-sm text-slate-500">Student number {{ student.studentNumber }}</p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
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
                <p v-if="form.errors.middle_name" class="mt-1 text-sm text-red-700">
                    {{ form.errors.middle_name }}
                </p>
            </div>
            <div>
                <label for="last_name" class="block text-sm font-medium">Last name</label>
                <input
                    id="last_name"
                    v-model="form.last_name"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.last_name" class="mt-1 text-sm text-red-700">
                    {{ form.errors.last_name }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Save names' }}
                </button>
                <a class="text-sm underline" :href="`/app/students/${student.id}/overview`"
                    >Cancel</a
                >
            </div>
        </form>
    </main>
</template>
