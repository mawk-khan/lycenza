<script setup lang="ts">
import { formatDateTime } from '../../../datetime';
export interface InboxItem {
    type: 'conversation' | 'announcement' | 'template';
    id: string;
    title: string;
    preview: string | null;
    actorName: string | null;
    latestActivityAt: string | null;
    unread: boolean;
    priority: string | null;
    requirement: string | null;
    status: string | null;
    hasAttachments: boolean;
    route: string;
    isEmergency: boolean;
}

interface Props {
    items: InboxItem[];
    emptyMessage: string;
}

defineProps<Props>();

const typeLabels: Record<InboxItem['type'], string> = {
    conversation: 'Conversation',
    announcement: 'Announcement',
    template: 'Template',
};
</script>

<template>
    <div
        v-if="items.length === 0"
        class="rounded-xs border border-dashed border-control-line p-8 text-center"
    >
        <p class="text-sm text-ink-muted">{{ emptyMessage }}</p>
    </div>

    <ul v-else class="divide-y divide-line rounded-xs border border-line">
        <li v-for="item in items" :key="`${item.type}-${item.id}`">
            <a
                class="block px-4 py-3 hover:bg-graphite-100"
                :class="{ 'bg-graphite-100': item.unread }"
                :href="item.route"
            >
                <div class="flex items-center justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2">
                        <span
                            class="shrink-0 rounded-xs px-1.5 py-0.5 text-xs font-medium"
                            :class="{
                                'bg-accent-100 text-accent-strong':
                                    item.type === 'conversation' || item.type === 'announcement',
                                'bg-graphite-200 text-ink-muted': item.type === 'template',
                            }"
                        >
                            {{ typeLabels[item.type] }}
                        </span>
                        <span
                            class="truncate text-sm"
                            :class="item.unread ? 'font-semibold' : 'font-medium'"
                        >
                            {{ item.title }}
                        </span>
                        <span
                            v-if="item.isEmergency"
                            class="shrink-0 rounded-xs bg-danger px-1.5 py-0.5 text-xs font-semibold text-white"
                            >⚠ Emergency</span
                        >
                        <span
                            v-if="item.requirement === 'required'"
                            class="tag tag-attention shrink-0"
                            >Required</span
                        >
                        <span
                            v-if="item.priority && item.priority !== 'normal'"
                            class="shrink-0 rounded-xs px-1.5 py-0.5 text-xs font-medium"
                            :class="{
                                'tag-info': item.priority === 'important',
                                'tag-attention': item.priority === 'urgent',
                                'tag-negative': item.priority === 'critical',
                            }"
                            >{{ item.priority }}</span
                        >
                    </div>
                    <span class="flex shrink-0 items-center gap-1 text-xs text-ink-muted">
                        <span v-if="item.hasAttachments" title="Has attachment">📎</span>
                        <span
                            v-if="item.unread"
                            class="h-1.5 w-1.5 rounded-full bg-accent-strong"
                        ></span>
                        {{ formatDateTime(item.latestActivityAt) }}
                    </span>
                </div>
                <p class="mt-0.5 truncate text-xs text-ink-muted">
                    <template v-if="item.actorName">{{ item.actorName }} · </template
                    >{{ item.preview ?? 'No content yet.' }}
                </p>
            </a>
        </li>
    </ul>
</template>
