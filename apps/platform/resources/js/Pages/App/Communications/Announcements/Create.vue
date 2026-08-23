<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface TemplatePrefill {
    id: string;
    title: string | null;
    body: string;
    priority: string | null;
}

interface Props {
    emailChannelEnabled: boolean;
    schoolTimezone: string;
    template: TemplatePrefill | null;
}

const props = defineProps<Props>();

const title = ref(props.template?.title ?? '');
const body = ref(props.template?.body ?? '');
const priority = ref<'normal' | 'important' | 'urgent' | 'critical'>(
    (props.template?.priority as 'normal' | 'important' | 'urgent' | 'critical' | undefined) ??
        'normal',
);
const audienceType = ref<'school_wide' | 'individual'>('school_wide');
const memberIds = ref('');
const emailSelected = ref(false);
const submitting = ref(false);

function submit() {
    submitting.value = true;
    router.post(
        '/app/communications/announcements',
        {
            title: title.value,
            body: body.value,
            priority: priority.value,
            audience_type: audienceType.value,
            member_user_ids:
                audienceType.value === 'individual'
                    ? memberIds.value
                          .split(',')
                          .map((id) => id.trim())
                          .filter(Boolean)
                    : [],
            channels:
                props.emailChannelEnabled && emailSelected.value ? ['in_app', 'email'] : ['in_app'],
            source_template_id: props.template?.id ?? null,
        },
        {
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-xs text-slate-400 underline" href="/app/communications/announcements"
            >← Announcements</a
        >
        <h1 class="mt-2 text-xl font-semibold">New Announcement</h1>
        <p class="mt-1 text-xs text-slate-500">
            Saved as a draft first -- you'll see an audience preview before publishing or
            scheduling.
        </p>
        <p v-if="template" class="mt-1 text-xs text-slate-400">
            Pre-filled from template. Editing here does not change the template.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-xs font-medium text-slate-500">Title</label>
                <input
                    v-model="title"
                    type="text"
                    required
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
                <label class="block text-xs font-medium text-slate-500">Priority</label>
                <select
                    v-model="priority"
                    class="mt-1 rounded border border-slate-300 px-2 py-1 text-sm"
                >
                    <option value="normal">Normal</option>
                    <option value="important">Important</option>
                    <option value="urgent">Urgent</option>
                    <option value="critical">Critical</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-500">Audience</label>
                <select
                    v-model="audienceType"
                    class="mt-1 rounded border border-slate-300 px-2 py-1 text-sm"
                >
                    <option value="school_wide">Entire School</option>
                    <option value="individual">Selected Members</option>
                </select>
            </div>

            <div v-if="audienceType === 'individual'">
                <label class="block text-xs font-medium text-slate-500"
                    >Member user IDs (comma-separated)</label
                >
                <input
                    v-model="memberIds"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                />
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-500">Delivery</label>
                <div class="mt-1 space-y-1">
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" checked disabled class="rounded border-slate-300" />
                        In-app
                    </label>
                    <label
                        class="flex items-center gap-2 text-sm"
                        :class="emailChannelEnabled ? 'text-slate-600' : 'text-slate-400'"
                    >
                        <input
                            v-model="emailSelected"
                            type="checkbox"
                            :disabled="!emailChannelEnabled"
                            class="rounded border-slate-300"
                        />
                        Email
                        <span v-if="!emailChannelEnabled" class="text-xs text-slate-400"
                            >(not currently available for this school)</span
                        >
                    </label>
                </div>
            </div>

            <button
                type="submit"
                :disabled="submitting"
                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
            >
                Save Draft
            </button>
        </form>
    </main>
</template>
