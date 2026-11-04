<script setup lang="ts">
import { formatDateTime } from '../../../datetime';
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
    // Phase 5D.1b §28: Guardian/Student domain provenance only -- see
    // CommunicationHubController::show()'s docblock. Never a broader
    // authorization signal, purely a display badge.
    domainParticipantType: 'guardian' | 'student' | null;
}

function participantBadgeLabel(type: Participant['domainParticipantType']): string | null {
    return type === 'guardian' ? 'Guardian' : type === 'student' ? 'Student' : null;
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
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink">
        <a class="text-xs text-ink-muted underline" href="/app/communications"
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
                class="btn btn-secondary shrink-0 text-xs text-ink-muted"
                @click="toggleArchive"
            >
                {{ isArchivedByMe ? 'Unarchive' : 'Archive' }}
            </button>
        </div>
        <p class="mt-1 flex flex-wrap items-center gap-x-1 text-xs text-ink-muted">
            <template v-for="(p, index) in participants.filter((p) => p.active)" :key="p.userId">
                <span v-if="index > 0">, </span>
                <span
                    >{{ p.name
                    }}<span
                        v-if="participantBadgeLabel(p.domainParticipantType)"
                        class="ml-0.5 rounded-xs px-1 py-px text-[10px] font-medium"
                        :class="
                            p.domainParticipantType === 'guardian'
                                ? 'bg-accent-100 text-accent-strong'
                                : 'bg-graphite-100 text-ink'
                        "
                        >{{ participantBadgeLabel(p.domainParticipantType) }}</span
                    ></span
                >
            </template>
            ·
            <span :class="thread.status === 'open' ? 'text-ink' : 'text-ink-muted'">{{
                thread.status
            }}</span>
        </p>

        <button
            v-if="messagesMeta.hasOlder"
            type="button"
            class="mt-4 w-full rounded-xs border border-line py-1.5 text-xs text-ink-muted hover:bg-graphite-100"
            @click="loadOlder"
        >
            Load older messages
        </button>

        <ul class="mt-6 space-y-3">
            <li
                v-if="messages.length === 0"
                class="rounded-xs border border-dashed border-control-line p-6 text-center text-sm text-ink-muted"
            >
                No messages yet.
            </li>
            <li
                v-for="message in messages"
                :key="message.id"
                class="rounded-xs border border-line p-3"
            >
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium">{{ message.senderName ?? 'Unknown' }}</span>
                    <span class="text-xs text-ink-muted">{{
                        formatDateTime(message.createdAt)
                    }}</span>
                </div>
                <p class="mt-1 text-sm whitespace-pre-wrap">{{ message.body }}</p>
                <span
                    v-if="message.priority !== 'normal'"
                    class="mt-1 inline-block rounded-xs px-1.5 py-0.5 text-xs font-medium"
                    :class="{
                        'tag-info': message.priority === 'important',
                        'tag-attention': message.priority === 'urgent',
                        'tag-negative': message.priority === 'critical',
                    }"
                >
                    {{ message.priority }}
                </span>

                <ul
                    v-if="message.attachments.length > 0"
                    class="mt-2 space-y-1 border-t border-line pt-2"
                >
                    <li
                        v-for="attachment in message.attachments"
                        :key="attachment.id"
                        class="flex items-center justify-between gap-2 text-xs"
                    >
                        <a
                            :href="`/app/communications/attachments/${attachment.id}/download`"
                            class="truncate text-ink underline"
                        >
                            📎 {{ attachment.displayName }}
                        </a>
                        <span class="shrink-0 text-ink-muted">{{
                            formatFileSize(attachment.sizeBytes)
                        }}</span>
                    </li>
                </ul>
            </li>
        </ul>

        <form
            v-if="canReply"
            class="mt-6 space-y-2 rounded-xs border border-line p-4"
            @submit.prevent="submitReply"
        >
            <textarea
                v-model="body"
                rows="3"
                required
                placeholder="Write a message..."
                class="input"
            ></textarea>

            <ul v-if="pendingAttachments.length > 0" class="space-y-1">
                <li
                    v-for="a in pendingAttachments"
                    :key="a.id"
                    class="flex items-center justify-between gap-2 text-xs text-ink-muted"
                >
                    <span class="truncate">📎 {{ a.displayName }}</span>
                    <span class="flex shrink-0 items-center gap-2">
                        {{ formatFileSize(a.sizeBytes) }}
                        <button
                            type="button"
                            :disabled="removingAttachmentId === a.id"
                            class="text-danger underline disabled:opacity-50"
                            @click="removePendingAttachment(a.id)"
                        >
                            Remove
                        </button>
                    </span>
                </li>
            </ul>

            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <select v-model="priority" class="input text-xs w-auto">
                        <option value="normal">Normal</option>
                        <option value="important">Important</option>
                        <option value="urgent">Urgent</option>
                        <option value="critical">Critical</option>
                    </select>
                    <label
                        v-if="pendingAttachments.length < maxAttachments"
                        class="cursor-pointer text-xs text-ink-muted underline"
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
                <button type="submit" :disabled="submitting" class="btn btn-primary">Send</button>
            </div>
        </form>
        <p v-else class="mt-6 text-xs text-ink-muted">
            You do not have permission to reply in this conversation.
        </p>
    </main>
</template>
