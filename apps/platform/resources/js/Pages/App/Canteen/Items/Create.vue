<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    code: '',
    name: '',
    price: '',
});

function submit(): void {
    form.post('/app/canteen-items');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/canteen-items">← Canteen items</a>
        <h1 class="mt-2 text-xl font-semibold">Add Item</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-ink-muted" for="code">Code</label>
                <input id="code" v-model="form.code" type="text" class="input mt-1" />
                <p v-if="form.errors.code" class="mt-1 text-sm text-danger">
                    {{ form.errors.code }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-ink-muted" for="name">Name</label>
                <input id="name" v-model="form.name" type="text" class="input mt-1" />
                <p v-if="form.errors.name" class="mt-1 text-sm text-danger">
                    {{ form.errors.name }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-ink-muted" for="price">Price (INR)</label>
                <input
                    id="price"
                    v-model="form.price"
                    type="text"
                    inputmode="decimal"
                    placeholder="0.00"
                    pattern="\d{1,12}(\.\d{1,2})?"
                    class="input mt-1"
                />
                <p v-if="form.errors.price" class="mt-1 text-sm text-danger">
                    {{ form.errors.price }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" :disabled="form.processing" class="btn btn-primary">
                    {{ form.processing ? 'Saving…' : 'Add Item' }}
                </button>
                <a class="text-sm underline" href="/app/canteen-items">Cancel</a>
            </div>
        </form>
    </main>
</template>
