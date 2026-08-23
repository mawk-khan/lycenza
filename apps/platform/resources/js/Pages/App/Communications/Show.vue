<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface ThreadDetail {
    id: string;
    threadType: string;
    subject: string | null;
    status: string;
    createdByName: string | null;
    lastActivityAt: string | null;
}

interface Participant {
    userId: string;
    name: string;
    active: boolean;
}

interface Message {
    id: string;
    senderName: string | null;
    body: string;
    priority: string;
    status: string;
    createdAt: string | null;
}

interface Props {
    thread: ThreadDetail;
    participants: Participant[];
    messages: Message[];
    canReply: boolean;
}

const props = defineProps<Props>();

const body = ref('');
const priority = ref<'normal' | 'important' | 'urgent' | 'critical'>('normal');
const submitting = ref(false);

function submitReply() {
    submitting.value = true;
    router.post(
        `/app/communications/${props.thread.id}/messages`,
        { body: body.value, priority: priority.value },
        {
            onFinish: () => {
                submitting.value = false;
                body.value = '';
            },
        },
    );
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-xs text-slate-400 underline" href="/app/communications"
            >← Communication Hub</a
        >

        <h1 class="mt-2 text-xl font-semibold">
            {{
                thread.subject ||
                `${thread.threadType === 'group' ? 'Group' : 'Direct'} conversation`
            }}
        </h1>
        <p class="mt-1 text-xs text-slate-500">
            {{ participants.filter((p) => p.active).length }} participant(s) ·
            <span :class="thread.status === 'open' ? 'text-emerald-600' : 'text-slate-400'">{{
                thread.status
            }}</span>
        </p>

        <ul class="mt-6 space-y-3">
            <li
                v-if="messages.length === 0"
                class="rounded border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500"
            >
                No messages yet.
            </li>
            <li
                v-for="message in messages"
                :key="message.id"
                class="rounded border border-slate-200 p-3"
            >
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium">{{ message.senderName ?? 'Unknown' }}</span>
                    <span class="text-xs text-slate-400">{{ message.createdAt }}</span>
                </div>
                <p class="mt-1 text-sm whitespace-pre-wrap">{{ message.body }}</p>
                <span
                    v-if="message.priority !== 'normal'"
                    class="mt-1 inline-block rounded px-1.5 py-0.5 text-xs font-medium"
                    :class="{
                        'bg-amber-100 text-amber-700': message.priority === 'important',
                        'bg-orange-100 text-orange-700': message.priority === 'urgent',
                        'bg-red-100 text-red-700': message.priority === 'critical',
                    }"
                >
                    {{ message.priority }}
                </span>
            </li>
        </ul>

        <form
            v-if="canReply"
            class="mt-6 space-y-2 rounded border border-slate-200 p-4"
            @submit.prevent="submitReply"
        >
            <textarea
                v-model="body"
                rows="3"
                required
                placeholder="Write a message..."
                class="w-full rounded border border-slate-300 px-2 py-1 text-sm"
            ></textarea>
            <div class="flex items-center justify-between">
                <select
                    v-model="priority"
                    class="rounded border border-slate-300 px-2 py-1 text-xs"
                >
                    <option value="normal">Normal</option>
                    <option value="important">Important</option>
                    <option value="urgent">Urgent</option>
                    <option value="critical">Critical</option>
                </select>
                <button
                    type="submit"
                    :disabled="submitting"
                    class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    Send
                </button>
            </div>
        </form>
        <p v-else class="mt-6 text-xs text-slate-400">
            You do not have permission to reply in this conversation.
        </p>
    </main>
</template>
