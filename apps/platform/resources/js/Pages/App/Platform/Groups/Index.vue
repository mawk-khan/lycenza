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
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink">
        <h1 class="text-xl font-semibold">School Groups (platform)</h1>

        <ul v-if="groups.length" class="mt-6 space-y-1 text-sm">
            <li v-for="g in groups" :key="g.id">
                <a class="underline" :href="`/app/platform/groups/${g.id}`">{{ g.name }}</a>
                <span class="ml-2 text-ink-muted">{{ g.slug }} · {{ g.status }}</span>
            </li>
        </ul>
        <p v-else class="mt-6 text-sm text-ink-muted">No School Groups yet.</p>

        <form v-if="canManage" class="mt-8 space-y-3" @submit.prevent="create">
            <h2 class="text-sm font-medium text-ink-muted">Create a Group</h2>
            <div>
                <label class="block text-sm text-ink-muted" for="name">Name</label>
                <input id="name" v-model="form.name" class="input mt-1" />
                <p v-if="form.errors.name" class="mt-1 text-sm text-danger">
                    {{ form.errors.name }}
                </p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="slug">Slug</label>
                <input id="slug" v-model="form.slug" class="input mt-1" />
                <p v-if="form.errors.slug" class="mt-1 text-sm text-danger">
                    {{ form.errors.slug }}
                </p>
            </div>
            <button type="submit" :disabled="form.processing" class="btn btn-primary">
                Create Group
            </button>
        </form>
        <p class="mt-6 text-sm"><a class="underline" href="/app">Back</a></p>
    </main>
</template>
