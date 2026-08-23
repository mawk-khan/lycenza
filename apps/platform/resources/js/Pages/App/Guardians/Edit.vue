<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import GuardianIdentityFields from '../../../Components/GuardianIdentityFields.vue';

interface Props {
    guardian: {
        id: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
    };
}

const props = defineProps<Props>();

const form = useForm({
    first_name: props.guardian.firstName,
    middle_name: props.guardian.middleName ?? '',
    last_name: props.guardian.lastName ?? '',
});

function submit(): void {
    form.put(`/app/guardians/${props.guardian.id}`);
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" :href="`/app/guardians/${guardian.id}`">← Back</a>
        <h1 class="mt-2 text-xl font-semibold">Edit guardian</h1>

        <form class="mt-6" @submit.prevent="submit">
            <GuardianIdentityFields
                v-model:first-name="form.first_name"
                v-model:middle-name="form.middle_name"
                v-model:last-name="form.last_name"
                :errors="form.errors"
            />

            <div class="mt-6 flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Save changes' }}
                </button>
                <a class="text-sm underline" :href="`/app/guardians/${guardian.id}`">Cancel</a>
            </div>
        </form>
    </main>
</template>
