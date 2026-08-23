<script setup lang="ts">
interface Errors {
    first_name?: string;
    middle_name?: string;
    last_name?: string;
}

defineProps<{ errors: Errors }>();

const firstName = defineModel<string>('firstName', { required: true });
const middleName = defineModel<string>('middleName', { required: true });
const lastName = defineModel<string>('lastName', { required: true });
</script>

<template>
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
                autofocus
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
    <p class="mt-1 text-sm text-red-600">{{ errors.first_name }}</p>
</template>
