<script setup lang="ts">
import EmptyState from '../../../../Components/EmptyState.vue';

/**
 * POR.4 — Guardian portal: conversations the School started with you as a
 * Guardian in this School. You can read and reply; you cannot start a new
 * conversation here.
 */
interface Conversation {
    id: string;
    subject: string | null;
    open: boolean;
    participants: string[];
    lastActivityAt: string | null;
    preview: string | null;
    latestFromMe: boolean;
    unread: boolean;
}

interface Props {
    schoolName: string;
    conversations: Conversation[];
}

defineProps<Props>();

function formatDate(value: string | null): string {
    return value === null ? '' : new Date(value).toLocaleString();
}
</script>

<template>
    <main class="mx-auto max-w-3xl px-6 py-10">
        <a class="text-sm underline" href="/app">Back</a>
        <h1 class="mt-4 text-xl font-semibold text-slate-900">
            Conversations with {{ schoolName }}
        </h1>

        <EmptyState
            v-if="conversations.length === 0"
            class="mt-6"
            title="No conversations"
            description="When the School starts a conversation with you, it appears here."
        />

        <ul v-else class="mt-6 divide-y divide-slate-200 rounded border border-slate-200">
            <li v-for="conversation in conversations" :key="conversation.id" class="px-4 py-3">
                <a class="block" :href="`/app/portal/conversations/${conversation.id}`">
                    <span class="flex items-center gap-2">
                        <span v-if="conversation.unread" class="text-xs font-semibold text-blue-700"
                            >New</span
                        >
                        <span
                            :class="
                                conversation.unread
                                    ? 'font-semibold text-slate-900'
                                    : 'text-slate-800'
                            "
                            >{{ conversation.subject ?? 'Conversation' }}</span
                        >
                        <span v-if="!conversation.open" class="text-xs text-slate-500"
                            >(closed)</span
                        >
                    </span>
                    <span class="mt-1 block text-xs text-slate-500">{{
                        conversation.participants.join(', ')
                    }}</span>
                    <span v-if="conversation.preview" class="mt-1 block text-sm text-slate-600"
                        ><template v-if="conversation.latestFromMe">You: </template
                        >{{ conversation.preview }}</span
                    >
                    <span class="mt-1 block text-xs text-slate-500">{{
                        formatDate(conversation.lastActivityAt)
                    }}</span>
                </a>
            </li>
        </ul>
    </main>
</template>
