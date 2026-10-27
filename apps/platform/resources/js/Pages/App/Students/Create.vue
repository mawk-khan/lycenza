<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import StudentIdentityFields from '../../../Components/StudentIdentityFields.vue';
import DuplicateStudentWarning, {
    type DuplicateWarning,
} from '../../../Components/DuplicateStudentWarning.vue';

// SIS 2.3: the Student number is allocated by the School's one counter when
// the Student is created -- never typed here. A same-name-and-birthday match
// comes back as `duplicateWarning`; "create a different Student" resubmits
// with its acknowledgement and the server checks again.
defineProps<{ duplicateWarning: DuplicateWarning | null; today: string }>();

const form = useForm({
    first_name: '',
    middle_name: '',
    last_name: '',
    date_of_birth: '',
    duplicate_acknowledgement: '',
});

// A refused submit always shows the warning again, even when it is identical
// to one the operator dismissed.
const warningDismissed = ref(false);

function submit(acknowledgement = ''): void {
    form.duplicate_acknowledgement = acknowledgement;
    form.post('/app/students', { onError: () => (warningDismissed.value = false) });
}
</script>

<template>
    <main class="mx-auto max-w-xl px-4 py-6 sm:p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/students">← Students</a>
        <h1 class="mt-2 text-xl font-semibold">Add student</h1>
        <p class="mt-1 text-sm text-slate-500">
            Identity only -- grade, section, and enrollment are configured later. The student number
            is assigned automatically when the student is created.
        </p>

        <DuplicateStudentWarning
            v-if="duplicateWarning && !warningDismissed"
            :warning="duplicateWarning"
            :processing="form.processing"
            continue-label="These are different people — add a new student"
            @cancel="warningDismissed = true"
            @continue="submit(duplicateWarning.acknowledgement)"
        />

        <form class="mt-6" @submit.prevent="submit()">
            <StudentIdentityFields
                v-model:first-name="form.first_name"
                v-model:middle-name="form.middle_name"
                v-model:last-name="form.last_name"
                v-model:date-of-birth="form.date_of_birth"
                :errors="form.errors"
                :with-number="false"
                :max-date="today"
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
