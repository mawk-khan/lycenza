<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    code: '',
    registration_number: '',
    capacity: '',
});

function submit(): void {
    form.post('/app/transport/vehicles');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/transport/vehicles">← Transport vehicles</a>
        <h1 class="mt-2 text-xl font-semibold">Add vehicle</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-ink-muted" for="code">Code</label>
                <input id="code" v-model="form.code" type="text" class="input mt-1" />
                <p v-if="form.errors.code" class="mt-1 text-sm text-danger">
                    {{ form.errors.code }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-ink-muted" for="registration-number">
                    Registration number
                </label>
                <input
                    id="registration-number"
                    v-model="form.registration_number"
                    type="text"
                    class="input mt-1"
                />
                <p v-if="form.errors.registration_number" class="mt-1 text-sm text-danger">
                    {{ form.errors.registration_number }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-ink-muted" for="capacity"
                    >Capacity (optional)</label
                >
                <input
                    id="capacity"
                    v-model="form.capacity"
                    type="number"
                    min="1"
                    class="input mt-1 w-32"
                />
                <p v-if="form.errors.capacity" class="mt-1 text-sm text-danger">
                    {{ form.errors.capacity }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" :disabled="form.processing" class="btn btn-primary">
                    {{ form.processing ? 'Saving…' : 'Add vehicle' }}
                </button>
                <a class="text-sm underline" href="/app/transport/vehicles">Cancel</a>
            </div>
        </form>
    </main>
</template>
