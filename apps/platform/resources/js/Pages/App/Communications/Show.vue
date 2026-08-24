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

interface MessageAttachment {
    id: string;
    displayName: string;
    mimeType: string;
    sizeBytes: number;
}

interface Message {
    id: string;
    senderName: string | null;
    body: string;
    priority: string;
    status: string;
    createdAt: string | null;
    attachments: MessageAttachment[];
}

interface PendingAttachment {
    id: string;
    displayName: string;
    sizeBytes: number;
}

interface Props {
    thread: ThreadDetail;
    participants: Participant[];
    messages: Message[];
    messagesMeta: { currentPage: number; hasOlder: boolean };
    canReply: boolean;
    isParticipant: boolean;
    isArchivedByMe: boolean;
    maxAttachments: number;
    pendingAttachments: PendingAttachment[];
}

const props = defineProps<Props>();

const body = ref('');
const priority = ref<'normal' | 'important' | 'urgent' | 'critical'>('normal');
const submitting = ref(false);
const archiving = ref(false);

// Seeded from the server on every load (brief §16) -- a plain client
// ref alone would lose an upload's id across the full-page reload
// every Inertia POST in this composer causes.
const pendingAttachments = ref<PendingAttachment[]>(props.pendingAttachments);
const uploading = ref(false);
const removingAttachmentId = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

function uploadAttachment(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) {
        return;
    }
    const formData = new FormData();
    formData.append('file', file);

    uploading.value = true;
    router.post(`/app/communications/${props.thread.id}/attachments`, formData, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            uploading.value = false;
            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

function removePendingAttachment(attachmentId: string) {
    removingAttachmentId.value = attachmentId;
    router.delete(`/app/communications/${props.thread.id}/attachments/${attachmentId}`, {
        preserveScroll: true,
        onFinish: () => (removingAttachmentId.value = null),
    });
}

function submitReply() {
    submitting.value = true;
    router.post(
        `/app/communications/${props.thread.id}/messages`,
        {
            body: body.value,
            priority: priority.value,
            attachment_ids: pendingAttachments.value.map((a) => a.id),
        },
        {
            onFinish: () => {
                submitting.value = false;
                body.value = '';
                pendingAttachments.value = [];
            },
        },
    );
}

function loadOlder() {
    router.get(
        `/app/communications/${props.thread.id}`,
        { page: props.messagesMeta.currentPage + 1 },
        { preserveScroll: true },
    );
}

function toggleArchive() {
    archiving.value = true;
    router.post(
        `/app/communications/${props.thread.id}/${props.isArchivedByMe ? 'unarchive' : 'archive'}`,
        {},
        { onFinish: () => (archiving.value = false) },
    );
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-xs text-slate-400 underline" href="/app/communications"
            >← Communication Hub</a
        >

        <div class="mt-2 flex items-center justify-between">
            <h1 class="text-xl font-semibold">
                {{
                    thread.subject ||
                    `${thread.threadType === 'group' ? 'Group' : 'Direct'} conversation`
                }}
            </h1>
            <button
                v-if="isParticipant"
                type="button"
                :disabled="archiving"
                class="shrink-0 rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 disabled:opacity-50"
                @click="toggleArchive"
            >
                {{ isArchivedByMe ? 'Unarchive' : 'Archive' }}
            </button>
        </div>
        <p class="mt-1 text-xs text-slate-500">
            {{
                participants
                    .filter((p) => p.active)
                    .map((p) => p.name)
                    .join(', ')
            }}
            ·
            <span :class="thread.status === 'open' ? 'text-emerald-600' : 'text-slate-400'">{{
                thread.status
            }}</span>
        </p>

        <button
            v-if="messagesMeta.hasOlder"
            type="button"
            class="mt-4 w-full rounded border border-slate-200 py-1.5 text-xs text-slate-500 hover:bg-slate-50"
            @click="loadOlder"
        >
            Load older messages
        </button>

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

                <ul
                    v-if="message.attachments.length > 0"
                    class="mt-2 space-y-1 border-t border-slate-100 pt-2"
                >
                    <li
                        v-for="attachment in message.attachments"
                        :key="attachment.id"
                        class="flex items-center justify-between gap-2 text-xs"
                    >
                        <a
                            :href="`/app/communications/attachments/${attachment.id}/download`"
                            class="truncate text-slate-700 underline"
                        >
                            📎 {{ attachment.displayName }}
                        </a>
                        <span class="shrink-0 text-slate-400">{{
                            formatFileSize(attachment.sizeBytes)
                        }}</span>
                    </li>
                </ul>
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

            <ul v-if="pendingAttachments.length > 0" class="space-y-1">
                <li
                    v-for="a in pendingAttachments"
                    :key="a.id"
                    class="flex items-center justify-between gap-2 text-xs text-slate-500"
                >
                    <span class="truncate">📎 {{ a.displayName }}</span>
                    <span class="flex shrink-0 items-center gap-2">
                        {{ formatFileSize(a.sizeBytes) }}
                        <button
                            type="button"
                            :disabled="removingAttachmentId === a.id"
                            class="text-red-600 underline disabled:opacity-50"
                            @click="removePendingAttachment(a.id)"
                        >
                            Remove
                        </button>
                    </span>
                </li>
            </ul>

            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <select
                        v-model="priority"
                        class="rounded border border-slate-300 px-2 py-1 text-xs"
                    >
                        <option value="normal">Normal</option>
                        <option value="important">Important</option>
                        <option value="urgent">Urgent</option>
                        <option value="critical">Critical</option>
                    </select>
                    <label
                        v-if="pendingAttachments.length < maxAttachments"
                        class="cursor-pointer text-xs text-slate-500 underline"
                    >
                        {{ uploading ? 'Uploading…' : 'Attach file' }}
                        <input
                            ref="fileInput"
                            type="file"
                            class="hidden"
                            :disabled="uploading"
                            @change="uploadAttachment"
                        />
                    </label>
                </div>
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
