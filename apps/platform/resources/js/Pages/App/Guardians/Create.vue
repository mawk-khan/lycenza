<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import GuardianIdentityFields from '../../../Components/GuardianIdentityFields.vue';

const form = useForm({
    first_name: '',
    middle_name: '',
    last_name: '',
});

function submit(): void {
    form.post('/app/guardians');
}
</script>

<template>
    <main class="mx-auto max-w-xl px-4 py-6 sm:p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/guardians">← Guardians</a>
        <h1 class="mt-2 text-xl font-semibold">Add guardian</h1>
        <p class="mt-1 text-sm text-ink-muted">
            Identity only. A Guardian is valid without contact information -- add email or mobile
            afterward from their page.
        </p>

        <form class="mt-6" @submit.prevent="submit">
            <GuardianIdentityFields
                v-model:first-name="form.first_name"
                v-model:middle-name="form.middle_name"
                v-model:last-name="form.last_name"
                :errors="form.errors"
            />

            <div class="mt-6 flex items-center gap-3">
                <button type="submit" :disabled="form.processing" class="btn btn-primary">
                    {{ form.processing ? 'Saving…' : 'Add guardian' }}
                </button>
                <a class="text-sm underline" href="/app/guardians">Cancel</a>
            </div>
        </form>
    </main>
</template>
