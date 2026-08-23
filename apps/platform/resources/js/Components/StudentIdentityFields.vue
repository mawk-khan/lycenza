<script setup lang="ts">
// Kept deliberately dumb: this component owns no submission logic,
// only the identity field group shared by Create and Edit (real
// repetition -- this checkpoint's brief, "extract reusable components
// only where repetition is real"). Each field is its own defineModel
// so the parent's Inertia useForm() instance stays the single source
// of truth (v-model:student-number="form.student_number" etc. on the
// call site) without this component mutating a prop directly.
interface Errors {
    student_number?: string;
    first_name?: string;
    middle_name?: string;
    last_name?: string;
    date_of_birth?: string;
}

defineProps<{ errors: Errors }>();

const studentNumber = defineModel<string>('studentNumber', { required: true });
const firstName = defineModel<string>('firstName', { required: true });
const middleName = defineModel<string>('middleName', { required: true });
const lastName = defineModel<string>('lastName', { required: true });
const dateOfBirth = defineModel<string>('dateOfBirth', { required: true });
</script>

<template>
    <div class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-slate-700" for="student_number"
                >Student number</label
            >
            <input
                id="student_number"
                v-model="studentNumber"
                type="text"
                required
                autofocus
                :aria-invalid="!!errors.student_number"
                aria-describedby="student_number-error"
                class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
            />
            <p id="student_number-error" class="mt-1 text-sm text-red-600">
                {{ errors.student_number }}
            </p>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <label class="block text-sm font-medium text-slate-700" for="first_name"
                    >First name</label
                >
                <input
                    id="first_name"
                    v-model="firstName"
                    type="text"
                    required
                    :aria-invalid="!!errors.first_name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700" for="middle_name"
                    >Middle name</label
                >
                <input
                    id="middle_name"
                    v-model="middleName"
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
                    v-model="lastName"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
            </div>
        </div>
        <p class="text-sm text-red-600">{{ errors.first_name }}</p>

        <div>
            <label class="block text-sm font-medium text-slate-700" for="date_of_birth"
                >Date of birth</label
            >
            <input
                id="date_of_birth"
                v-model="dateOfBirth"
                type="date"
                required
                :aria-invalid="!!errors.date_of_birth"
                aria-describedby="date_of_birth-error"
                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 sm:w-56"
            />
            <p id="date_of_birth-error" class="mt-1 text-sm text-red-600">
                {{ errors.date_of_birth }}
            </p>
        </div>
    </div>
</template>
