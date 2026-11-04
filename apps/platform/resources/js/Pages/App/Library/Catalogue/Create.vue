<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    title: '',
    author: '',
    isbn: '',
});

function submit(): void {
    form.post('/app/library/titles');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/library/titles">← Library catalogue</a>
        <h1 class="mt-2 text-xl font-semibold">Add title</h1>
        <p class="mt-1 text-sm text-ink-muted">
            Register a new bibliographic title. Add physical copies afterward.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-ink-muted" for="title">Title</label>
                <input id="title" v-model="form.title" type="text" class="input mt-1" />
                <p v-if="form.errors.title" class="mt-1 text-sm text-danger">
                    {{ form.errors.title }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-ink-muted" for="author">Author</label>
                <input id="author" v-model="form.author" type="text" class="input mt-1" />
                <p v-if="form.errors.author" class="mt-1 text-sm text-danger">
                    {{ form.errors.author }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-ink-muted" for="isbn">ISBN (optional)</label>
                <input id="isbn" v-model="form.isbn" type="text" class="input mt-1" />
                <p v-if="form.errors.isbn" class="mt-1 text-sm text-danger">
                    {{ form.errors.isbn }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" :disabled="form.processing" class="btn btn-primary">
                    {{ form.processing ? 'Saving…' : 'Add title' }}
                </button>
                <a class="text-sm underline" href="/app/library/titles">Cancel</a>
            </div>
        </form>
    </main>
</template>
