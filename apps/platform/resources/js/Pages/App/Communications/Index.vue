<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface ThreadSummary {
    id: string;
    threadType: string;
    subject: string | null;
    status: string;
    createdByName: string | null;
    lastActivityAt: string | null;
}

interface Props {
    threads: ThreadSummary[];
    canSend: boolean;
}

defineProps<Props>();

const showCompose = ref(false);
const subject = ref('');
const threadType = ref<'direct' | 'group'>('direct');
const participantIds = ref('');
const submitting = ref(false);

function submitCompose() {
    submitting.value = true;
    router.post(
        '/app/communications',
        {
            subject: subject.value || null,
            thread_type: threadType.value,
            participant_user_ids: participantIds.value
                .split(',')
                .map((id) => id.trim())
                .filter(Boolean),
        },
        {
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

const futureSections = ['Announcements', 'Scheduled', 'Drafts', 'Failed'];
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Communication Hub</h1>
            <button
                v-if="canSend"
                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white"
                @click="showCompose = !showCompose"
            >
                + New Message
            </button>
        </div>

        <div class="mt-6 grid grid-cols-[160px_1fr] gap-8">
            <nav class="text-sm">
                <ul class="space-y-1">
                    <li class="rounded bg-slate-100 px-2 py-1 font-medium text-slate-900">Inbox</li>
                    <li
                        v-for="section in futureSections"
                        :key="section"
                        class="px-2 py-1 text-slate-400"
                        :title="`${section} is not implemented yet`"
                    >
                        {{ section }}
                    </li>
                </ul>
            </nav>

            <div>
                <form
                    v-if="showCompose"
                    class="mb-6 space-y-3 rounded border border-slate-200 p-4"
                    @submit.prevent="submitCompose"
                >
                    <div>
                        <label class="block text-xs font-medium text-slate-500"
                            >Subject (optional)</label
                        >
                        <input
                            v-model="subject"
                            type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500">Type</label>
                        <select
                            v-model="threadType"
                            class="mt-1 rounded border border-slate-300 px-2 py-1 text-sm"
                        >
                            <option value="direct">Direct</option>
                            <option value="group">Group</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500"
                            >Participant user IDs (comma-separated)</label
                        >
                        <input
                            v-model="participantIds"
                            type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                        />
                    </div>
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                    >
                        Start conversation
                    </button>
                </form>

                <div
                    v-if="threads.length === 0"
                    class="rounded border border-dashed border-slate-300 p-8 text-center"
                >
                    <p class="text-sm text-slate-500">No conversations yet.</p>
                    <p v-if="canSend" class="mt-1 text-xs text-slate-400">
                        Start one with "New Message" above.
                    </p>
                </div>

                <ul v-else class="divide-y divide-slate-200 rounded border border-slate-200">
                    <li v-for="thread in threads" :key="thread.id">
                        <a
                            class="block px-4 py-3 hover:bg-slate-50"
                            :href="`/app/communications/${thread.id}`"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium">
                                    {{
                                        thread.subject ||
                                        `${thread.threadType === 'group' ? 'Group' : 'Direct'} conversation`
                                    }}
                                </span>
                                <span class="text-xs text-slate-400">{{
                                    thread.lastActivityAt
                                }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Started by {{ thread.createdByName ?? 'Unknown' }}
                            </p>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <nav class="mt-8 text-sm">
            <a class="underline" href="/app">← Back to dashboard</a>
        </nav>
    </main>
</template>
