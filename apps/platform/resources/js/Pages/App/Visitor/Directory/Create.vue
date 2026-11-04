<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    full_name: '',
    phone: '',
});

function submit(): void {
    form.post('/app/visitor/directory');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/visitor/directory">← Visitor directory</a>
        <h1 class="mt-2 text-xl font-semibold">Add Visitor</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-ink-muted" for="full-name">Full name</label>
                <input id="full-name" v-model="form.full_name" type="text" class="input mt-1" />
                <p v-if="form.errors.full_name" class="mt-1 text-sm text-danger">
                    {{ form.errors.full_name }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-ink-muted" for="phone">Phone (optional)</label>
                <input id="phone" v-model="form.phone" type="text" class="input mt-1" />
                <p v-if="form.errors.phone" class="mt-1 text-sm text-danger">
                    {{ form.errors.phone }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" :disabled="form.processing" class="btn btn-primary">
                    {{ form.processing ? 'Saving…' : 'Add Visitor' }}
                </button>
                <a class="text-sm underline" href="/app/visitor/directory">Cancel</a>
            </div>
        </form>
    </main>
</template>
