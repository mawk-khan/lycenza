<script setup lang="ts">
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
        class="rounded border border-dashed border-slate-300 p-8 text-center"
    >
        <p class="text-sm text-slate-500">{{ emptyMessage }}</p>
    </div>

    <ul v-else class="divide-y divide-slate-200 rounded border border-slate-200">
        <li v-for="item in items" :key="`${item.type}-${item.id}`">
            <a
                class="block px-4 py-3 hover:bg-slate-50"
                :class="{ 'bg-slate-50': item.unread }"
                :href="item.route"
            >
                <div class="flex items-center justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2">
                        <span
                            class="shrink-0 rounded px-1.5 py-0.5 text-xs font-medium"
                            :class="{
                                'bg-sky-100 text-sky-700': item.type === 'conversation',
                                'bg-purple-100 text-purple-700': item.type === 'announcement',
                                'bg-slate-100 text-slate-600': item.type === 'template',
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
                            v-if="item.requirement === 'required'"
                            class="shrink-0 rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-700"
                            >Required</span
                        >
                        <span
                            v-if="item.priority && item.priority !== 'normal'"
                            class="shrink-0 rounded px-1.5 py-0.5 text-xs font-medium"
                            :class="{
                                'bg-amber-100 text-amber-700': item.priority === 'important',
                                'bg-orange-100 text-orange-700': item.priority === 'urgent',
                                'bg-red-100 text-red-700': item.priority === 'critical',
                            }"
                            >{{ item.priority }}</span
                        >
                    </div>
                    <span class="flex shrink-0 items-center gap-1 text-xs text-slate-400">
                        <span v-if="item.hasAttachments" title="Has attachment">📎</span>
                        <span
                            v-if="item.unread"
                            class="h-1.5 w-1.5 rounded-full bg-slate-900"
                        ></span>
                        {{ item.latestActivityAt }}
                    </span>
                </div>
                <p class="mt-0.5 truncate text-xs text-slate-500">
                    <template v-if="item.actorName">{{ item.actorName }} · </template
                    >{{ item.preview ?? 'No content yet.' }}
                </p>
            </a>
        </li>
    </ul>
</template>
