<script setup lang="ts">
export type HubNavTab =
    | 'inbox'
    | 'unread'
    | 'conversations'
    | 'announcements'
    | 'scheduled'
    | 'sent'
    | 'failed'
    | 'templates'
    | 'preferences'
    | 'settings';

interface Props {
    active: HubNavTab;
    totalUnreadCount: number;
    canAnnounce: boolean;
    canManage: boolean;
    canManageTemplates: boolean;
}

defineProps<Props>();
</script>

<template>
    <nav class="text-sm">
        <ul class="space-y-1">
            <li>
                <a
                    href="/app/communications"
                    class="block rounded px-2 py-1"
                    :class="
                        active === 'inbox'
                            ? 'bg-slate-100 font-medium text-slate-900'
                            : 'text-slate-600 hover:text-slate-900'
                    "
                    >Inbox</a
                >
            </li>
            <li>
                <a
                    href="/app/communications/unread"
                    class="flex items-center justify-between rounded px-2 py-1"
                    :class="
                        active === 'unread'
                            ? 'bg-slate-100 font-medium text-slate-900'
                            : 'text-slate-600 hover:text-slate-900'
                    "
                >
                    <span>Unread</span>
                    <span
                        v-if="totalUnreadCount > 0"
                        class="rounded-full bg-slate-900 px-1.5 py-0.5 text-xs font-medium text-white"
                        >{{ totalUnreadCount }}</span
                    >
                </a>
            </li>
            <li>
                <a
                    href="/app/communications/conversations"
                    class="block rounded px-2 py-1"
                    :class="
                        active === 'conversations'
                            ? 'bg-slate-100 font-medium text-slate-900'
                            : 'text-slate-600 hover:text-slate-900'
                    "
                    >Conversations</a
                >
            </li>
            <li>
                <a
                    href="/app/communications/announcements"
                    class="block rounded px-2 py-1"
                    :class="
                        active === 'announcements'
                            ? 'bg-slate-100 font-medium text-slate-900'
                            : 'text-slate-600 hover:text-slate-900'
                    "
                    >Announcements</a
                >
            </li>
            <li v-if="canAnnounce">
                <a
                    href="/app/communications/announcements?status=scheduled"
                    class="block rounded px-2 py-1"
                    :class="
                        active === 'scheduled'
                            ? 'bg-slate-100 font-medium text-slate-900'
                            : 'text-slate-600 hover:text-slate-900'
                    "
                    >Scheduled</a
                >
            </li>
            <li>
                <a
                    href="/app/communications/sent"
                    class="block rounded px-2 py-1"
                    :class="
                        active === 'sent'
                            ? 'bg-slate-100 font-medium text-slate-900'
                            : 'text-slate-600 hover:text-slate-900'
                    "
                    >Sent</a
                >
            </li>
            <li v-if="canManage">
                <a
                    href="/app/communications/failed"
                    class="block rounded px-2 py-1"
                    :class="
                        active === 'failed'
                            ? 'bg-slate-100 font-medium text-slate-900'
                            : 'text-slate-600 hover:text-slate-900'
                    "
                    >Failed</a
                >
            </li>
            <li v-if="canManageTemplates">
                <a
                    href="/app/communications/templates"
                    class="block rounded px-2 py-1"
                    :class="
                        active === 'templates'
                            ? 'bg-slate-100 font-medium text-slate-900'
                            : 'text-slate-600 hover:text-slate-900'
                    "
                    >Templates</a
                >
            </li>
            <li>
                <a
                    href="/app/communications/preferences"
                    class="block rounded px-2 py-1"
                    :class="
                        active === 'preferences'
                            ? 'bg-slate-100 font-medium text-slate-900'
                            : 'text-slate-600 hover:text-slate-900'
                    "
                    >Preferences</a
                >
            </li>
            <li v-if="canManage">
                <a
                    href="/app/communications/settings/channels"
                    class="block rounded px-2 py-1"
                    :class="
                        active === 'settings'
                            ? 'bg-slate-100 font-medium text-slate-900'
                            : 'text-slate-600 hover:text-slate-900'
                    "
                    >Settings</a
                >
            </li>
        </ul>
    </nav>
</template>
