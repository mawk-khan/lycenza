<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

/**
 * POR.4 — one conversation with the School. You can reply with text while
 * it is open; you cannot edit or delete a message, add people or attach
 * files here. Opening it marks it read for you only.
 */
interface Attachment {
    id: string;
    name: string;
    mimeType: string;
    sizeBytes: number;
}

interface Message {
    id: string;
    sender: string;
    mine: boolean;
    body: string;
    sentAt: string | null;
    attachments: Attachment[];
}

interface Props {
    schoolName: string;
    conversation: {
        id: string;
        subject: string | null;
        open: boolean;
        canReply: boolean;
        participants: string[];
        messages: Message[];
        hasOlder: boolean;
        page: number;
    };
    replyKey: string;
    maxLength: number;
}

const props = defineProps<Props>();

const form = useForm({
    body: '',
    idempotency_key: props.replyKey,
});

// Each render issues a new key; a kept component must send the new one,
// while a retry of the same submission keeps the old one.
watch(
    () => props.replyKey,
    (key) => {
        form.idempotency_key = key;
    },
);

function submit(): void {
    form.post(`/app/portal/conversations/${props.conversation.id}/replies`, {
        preserveScroll: true,
        onSuccess: () => form.reset('body'),
    });
}

function formatSize(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function downloadUrl(attachmentId: string): string {
    return `/app/portal/conversations/${props.conversation.id}/attachments/${attachmentId}/download`;
}
</script>

<template>
    <main class="mx-auto max-w-3xl px-6 py-10">
        <a class="text-sm underline" href="/app/portal/conversations">Back to conversations</a>
        <h1 class="mt-4 text-xl font-semibold text-ink">
            {{ conversation.subject ?? 'Conversation' }}
        </h1>
        <p class="mt-1 text-xs text-ink-muted">With {{ conversation.participants.join(', ') }}</p>

        <a
            v-if="conversation.hasOlder"
            class="mt-6 block text-sm underline"
            :href="`/app/portal/conversations/${conversation.id}?page=${conversation.page + 1}`"
            >Older messages</a
        >

        <ol class="mt-6 space-y-4">
            <li
                v-for="message in conversation.messages"
                :key="message.id"
                class="rounded-xs border px-4 py-3"
                :class="message.mine ? 'border-accent-300 bg-accent-100' : 'border-line'"
            >
                <p class="text-xs text-ink-muted">
                    {{ message.sender }}
                    <template v-if="message.sentAt">
                        · {{ new Date(message.sentAt).toLocaleString() }}</template
                    >
                </p>
                <div class="mt-1 whitespace-pre-line text-sm text-ink">
                    {{ message.body }}
                </div>
                <ul v-if="message.attachments.length > 0" class="mt-2 space-y-1 text-sm">
                    <li v-for="attachment in message.attachments" :key="attachment.id">
                        <a class="underline" :href="downloadUrl(attachment.id)">{{
                            attachment.name
                        }}</a>
                        <span class="text-xs text-ink-muted">
                            ({{ formatSize(attachment.sizeBytes) }})</span
                        >
                    </li>
                </ul>
            </li>
        </ol>

        <a
            v-if="conversation.page > 1"
            class="mt-6 block text-sm underline"
            :href="`/app/portal/conversations/${conversation.id}`"
            >Latest messages</a
        >

        <form v-if="conversation.canReply" class="mt-8" @submit.prevent="submit">
            <label class="block text-sm font-medium text-ink" for="reply-body">Reply</label>
            <textarea
                id="reply-body"
                v-model="form.body"
                class="input mt-1 block"
                rows="4"
                :maxlength="maxLength"
                required
            ></textarea>
            <p v-if="form.errors.body" class="mt-1 text-sm text-danger">{{ form.errors.body }}</p>
            <p v-if="form.errors.idempotency_key" class="mt-1 text-sm text-danger">
                {{ form.errors.idempotency_key }}
            </p>
            <button type="submit" class="btn btn-primary mt-3" :disabled="form.processing">
                Send reply
            </button>
        </form>
        <p v-else-if="!conversation.open" class="mt-8 text-sm text-ink-muted">
            This conversation is closed to replies.
        </p>
    </main>
</template>
