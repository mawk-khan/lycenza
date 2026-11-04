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
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink">
        <a class="text-xs text-ink-muted underline" href="/app/communications/templates"
            >← Templates</a
        >
        <h1 class="mt-2 text-xl font-semibold">New Template</h1>
        <p class="mt-1 text-xs text-ink-muted">
            Reusable source content -- editing this later never changes an Announcement already
            created from it.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-xs font-medium text-ink-muted">Name</label>
                <input v-model="name" type="text" required maxlength="255" class="input mt-1" />
            </div>

            <div>
                <label class="block text-xs font-medium text-ink-muted"
                    >Description (optional)</label
                >
                <input v-model="description" type="text" maxlength="1000" class="input mt-1" />
            </div>

            <div>
                <label class="block text-xs font-medium text-ink-muted"
                    >Subject / title (optional)</label
                >
                <input v-model="subject" type="text" maxlength="255" class="input mt-1" />
            </div>

            <div>
                <label class="block text-xs font-medium text-ink-muted">Message</label>
                <textarea v-model="body" rows="5" required class="input mt-1"></textarea>
            </div>

            <div>
                <label class="block text-xs font-medium text-ink-muted"
                    >Suggested priority (optional)</label
                >
                <select v-model="priority" class="input mt-1 w-auto">
                    <option value="">No suggestion</option>
                    <option value="normal">Normal</option>
                    <option value="important">Important</option>
                    <option value="urgent">Urgent</option>
                    <option value="critical">Critical</option>
                </select>
            </div>

            <button type="submit" :disabled="submitting" class="btn btn-primary">
                Save Template
            </button>
        </form>
    </main>
</template>
