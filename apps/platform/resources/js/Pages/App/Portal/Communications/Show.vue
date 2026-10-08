<script setup lang="ts">
/**
 * POR.1 — one School announcement addressed to you as a Guardian
 * (read-only). Opening it marks only your own copy as read.
 */
interface Attachment {
    id: string;
    name: string;
    mimeType: string;
    sizeBytes: number;
}

interface Props {
    schoolName: string;
    announcement: {
        id: string;
        title: string;
        body: string;
        sender: string | null;
        publishedAt: string | null;
        priority: string;
        attachments: Attachment[];
    };
}

const props = defineProps<Props>();

function formatSize(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function downloadUrl(attachmentId: string): string {
    return `/app/portal/communications/announcements/${props.announcement.id}/attachments/${attachmentId}/download`;
}
</script>

<template>
    <main class="mx-auto max-w-3xl px-6 py-10">
        <a class="text-sm underline" href="/app/portal/communications">Back to messages</a>
        <h1 class="mt-4 text-xl font-semibold text-slate-900">{{ announcement.title }}</h1>
        <p class="mt-1 text-xs text-slate-500">
            {{ announcement.sender ?? schoolName }}
            <template v-if="announcement.publishedAt">
                · {{ new Date(announcement.publishedAt).toLocaleString() }}</template
            >
        </p>

        <div class="mt-6 whitespace-pre-line text-sm text-slate-800">{{ announcement.body }}</div>

        <section v-if="announcement.attachments.length > 0" class="mt-8">
            <h2 class="text-sm font-medium text-slate-500">Attachments</h2>
            <ul class="mt-2 space-y-1 text-sm">
                <li v-for="attachment in announcement.attachments" :key="attachment.id">
                    <a class="underline" :href="downloadUrl(attachment.id)">{{
                        attachment.name
                    }}</a>
                    <span class="text-xs text-slate-500">
                        ({{ formatSize(attachment.sizeBytes) }})</span
                    >
                </li>
            </ul>
        </section>
    </main>
</template>
