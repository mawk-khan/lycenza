<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface TemplateDetail {
    id: string;
    name: string;
    templateType: string;
    status: string;
    createdByName: string | null;
    updatedAt: string | null;
    description: string | null;
    subject: string | null;
    body: string;
    priority: string | null;
}

interface Props {
    template: TemplateDetail;
}

const props = defineProps<Props>();

const name = ref(props.template.name);
const description = ref(props.template.description ?? '');
const subject = ref(props.template.subject ?? '');
const body = ref(props.template.body);
const priority = ref(props.template.priority ?? '');
const submitting = ref(false);
const togglingStatus = ref(false);

function submit() {
    submitting.value = true;
    router.put(
        `/app/communications/templates/${props.template.id}`,
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

function toggleStatus() {
    togglingStatus.value = true;
    const action = props.template.status === 'active' ? 'deactivate' : 'activate';
    router.post(
        `/app/communications/templates/${props.template.id}/${action}`,
        {},
        { onFinish: () => (togglingStatus.value = false) },
    );
}

function useTemplate() {
    router.get(`/app/communications/announcements/create?template=${props.template.id}`);
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-xs text-slate-400 underline" href="/app/communications/templates"
            >← Templates</a
        >

        <div class="mt-2 flex items-center gap-2">
            <h1 class="text-xl font-semibold">{{ template.name }}</h1>
            <span
                class="rounded px-1.5 py-0.5 text-xs font-medium"
                :class="
                    template.status === 'active'
                        ? 'bg-emerald-100 text-emerald-700'
                        : 'bg-slate-100 text-slate-500'
                "
            >
                {{ template.status }}
            </span>
        </div>
        <p class="mt-1 text-xs text-slate-500">
            by {{ template.createdByName ?? 'Unknown' }} · updated {{ template.updatedAt }}
        </p>

        <div class="mt-4 flex gap-2">
            <button
                type="button"
                :disabled="template.status !== 'active'"
                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                @click="useTemplate"
            >
                Use Template
            </button>
            <button
                type="button"
                :disabled="togglingStatus"
                class="rounded border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-600 disabled:opacity-50"
                @click="toggleStatus"
            >
                {{ template.status === 'active' ? 'Deactivate' : 'Activate' }}
            </button>
        </div>
        <p v-if="template.status !== 'active'" class="mt-1 text-xs text-slate-400">
            Inactive templates cannot be used to start a new announcement.
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
                Save Changes
            </button>
        </form>
    </main>
</template>
