<script setup lang="ts">
// Phase 0N.5 (ADR 0045 section 5): platform governance of School Groups.
import { useForm } from '@inertiajs/vue3';

defineProps<{
    groups: { id: string; name: string; slug: string; status: string }[];
    canManage: boolean;
}>();

const form = useForm({ name: '', slug: '' });

function create() {
    form.post('/app/platform/groups');
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">School Groups (platform)</h1>

        <ul v-if="groups.length" class="mt-6 space-y-1 text-sm">
            <li v-for="g in groups" :key="g.id">
                <a class="underline" :href="`/app/platform/groups/${g.id}`">{{ g.name }}</a>
                <span class="ml-2 text-slate-500">{{ g.slug }} · {{ g.status }}</span>
            </li>
        </ul>
        <p v-else class="mt-6 text-sm text-slate-600">No School Groups yet.</p>

        <form v-if="canManage" class="mt-8 space-y-3" @submit.prevent="create">
            <h2 class="text-sm font-medium text-slate-500">Create a Group</h2>
            <div>
                <label class="block text-sm text-slate-600" for="name">Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-700">
                    {{ form.errors.name }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="slug">Slug</label>
                <input
                    id="slug"
                    v-model="form.slug"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
                <p v-if="form.errors.slug" class="mt-1 text-sm text-red-700">
                    {{ form.errors.slug }}
                </p>
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
            >
                Create Group
            </button>
        </form>
        <p class="mt-6 text-sm"><a class="underline" href="/app">Back</a></p>
    </main>
</template>
