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
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/library/titles">← Library catalogue</a>
        <h1 class="mt-2 text-xl font-semibold">Add title</h1>
        <p class="mt-1 text-sm text-slate-500">
            Register a new bibliographic title. Add physical copies afterward.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="title">Title</label>
                <input
                    id="title"
                    v-model="form.title"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">
                    {{ form.errors.title }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="author">Author</label>
                <input
                    id="author"
                    v-model="form.author"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.author" class="mt-1 text-sm text-red-600">
                    {{ form.errors.author }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="isbn">ISBN (optional)</label>
                <input
                    id="isbn"
                    v-model="form.isbn"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.isbn" class="mt-1 text-sm text-red-600">
                    {{ form.errors.isbn }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Add title' }}
                </button>
                <a class="text-sm underline" href="/app/library/titles">Cancel</a>
            </div>
        </form>
    </main>
</template>
