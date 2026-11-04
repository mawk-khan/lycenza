<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    code: '',
    name: '',
    start_time: '',
    end_time: '',
    sort_order: '',
});

function submit(): void {
    form.transform((data) => ({
        ...data,
        start_time: data.start_time ? `${data.start_time}:00` : '',
        end_time: data.end_time ? `${data.end_time}:00` : '',
        sort_order: data.sort_order === '' ? null : Number(data.sort_order),
    })).post('/app/timetable-periods');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/timetable-periods">← Timetable periods</a>
        <h1 class="mt-2 text-xl font-semibold">Add Period</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-ink-muted" for="code">Code</label>
                <input
                    id="code"
                    v-model="form.code"
                    type="text"
                    placeholder="P1"
                    class="input mt-1"
                />
                <p v-if="form.errors.code" class="mt-1 text-sm text-danger">
                    {{ form.errors.code }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-ink-muted" for="name">Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    placeholder="Period 1"
                    class="input mt-1"
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-danger">
                    {{ form.errors.name }}
                </p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-ink-muted" for="start_time">Start time</label>
                    <input
                        id="start_time"
                        v-model="form.start_time"
                        type="time"
                        class="input mt-1"
                    />
                    <p v-if="form.errors.start_time" class="mt-1 text-sm text-danger">
                        {{ form.errors.start_time }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-ink-muted" for="end_time">End time</label>
                    <input id="end_time" v-model="form.end_time" type="time" class="input mt-1" />
                    <p v-if="form.errors.end_time" class="mt-1 text-sm text-danger">
                        {{ form.errors.end_time }}
                    </p>
                </div>
            </div>

            <div>
                <label class="block text-sm text-ink-muted" for="sort_order"
                    >Sort order (optional)</label
                >
                <input id="sort_order" v-model="form.sort_order" type="number" class="input mt-1" />
                <p v-if="form.errors.sort_order" class="mt-1 text-sm text-danger">
                    {{ form.errors.sort_order }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" :disabled="form.processing" class="btn btn-primary">
                    {{ form.processing ? 'Saving…' : 'Add Period' }}
                </button>
                <a class="text-sm underline" href="/app/timetable-periods">Cancel</a>
            </div>
        </form>
    </main>
</template>
