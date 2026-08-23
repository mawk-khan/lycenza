<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const name = ref('');
const description = ref('');
const subject = ref('');
const body = ref('');
const priority = ref<'' | 'normal' | 'important' | 'urgent' | 'critical'>('');
const submitting = ref(false);

function submit() {
    submitting.value = true;
    router.post(
        '/app/communications/templates',
        {
            name: name.value,
            description: description.value || null,
            subject: subject.value || null,
            body: body.value,
            priority: priority.value || null,
        },
        { onFinish: () => (submitting.value = false) },
    );
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-xs text-slate-400 underline" href="/app/communications/templates"
            >← Templates</a
        >
        <h1 class="mt-2 text-xl font-semibold">New Template</h1>
        <p class="mt-1 text-xs text-slate-500">
            Reusable source content -- editing this later never changes an Announcement already
            created from it.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-xs font-medium text-slate-500">Name</label>
                <input
                    v-model="name"
                    type="text"
                    required
                    maxlength="255"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                />
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-500"
                    >Description (optional)</label
                >
                <input
                    v-model="description"
                    type="text"
                    maxlength="1000"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                />
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-500"
                    >Subject / title (optional)</label
                >
                <input
                    v-model="subject"
                    type="text"
                    maxlength="255"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                />
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-500">Message</label>
                <textarea
                    v-model="body"
                    rows="5"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                ></textarea>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-500"
                    >Suggested priority (optional)</label
                >
                <select
                    v-model="priority"
                    class="mt-1 rounded border border-slate-300 px-2 py-1 text-sm"
                >
                    <option value="">No suggestion</option>
                    <option value="normal">Normal</option>
                    <option value="important">Important</option>
                    <option value="urgent">Urgent</option>
                    <option value="critical">Critical</option>
                </select>
            </div>

            <button
                type="submit"
                :disabled="submitting"
                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
            >
                Save Template
            </button>
        </form>
    </main>
</template>
