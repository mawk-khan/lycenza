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
    // SIS.EXP-1: the stale-form token; the server refuses a save if the details changed since.
    version: string;
}

const props = defineProps<Props>();

const form = useForm({
    first_name: props.student.firstName,
    middle_name: props.student.middleName ?? '',
    last_name: props.student.lastName ?? '',
    expected_version: props.version,
});

function submit(): void {
    form.put(`/app/students/${props.student.id}/names`);
}
</script>

<template>
    <main class="mx-auto max-w-xl px-4 py-6 sm:p-8 font-sans text-ink">
        <a class="text-sm underline" :href="`/app/students/${student.id}/overview`">← Back</a>
        <h1 class="mt-2 text-xl font-semibold">Edit names</h1>
        <p class="mt-1 text-sm text-ink-muted">Student number {{ student.studentNumber }}</p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label for="first_name" class="block text-sm font-medium">First name</label>
                <input
                    id="first_name"
                    v-model="form.first_name"
                    type="text"
                    required
                    class="input mt-1"
                />
                <p v-if="form.errors.first_name" class="mt-1 text-sm text-danger">
                    {{ form.errors.first_name }}
                </p>
            </div>
            <div>
                <label for="middle_name" class="block text-sm font-medium">Middle name</label>
                <input id="middle_name" v-model="form.middle_name" type="text" class="input mt-1" />
                <p v-if="form.errors.middle_name" class="mt-1 text-sm text-danger">
                    {{ form.errors.middle_name }}
                </p>
            </div>
            <div>
                <label for="last_name" class="block text-sm font-medium">Last name</label>
                <input id="last_name" v-model="form.last_name" type="text" class="input mt-1" />
                <p v-if="form.errors.last_name" class="mt-1 text-sm text-danger">
                    {{ form.errors.last_name }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" :disabled="form.processing" class="btn btn-primary">
                    {{ form.processing ? 'Saving…' : 'Save names' }}
                </button>
                <a class="text-sm underline" :href="`/app/students/${student.id}/overview`"
                    >Cancel</a
                >
            </div>
        </form>
    </main>
</template>
