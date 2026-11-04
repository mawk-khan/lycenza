<script setup lang="ts">
export type HubNavTab =
    | 'inbox'
    | 'unread'
    | 'conversations'
    | 'announcements'
    | 'scheduled'
    | 'sent'
    | 'failed'
    | 'approvals'
    | 'analytics'
    | 'templates'
    | 'preferences'
    | 'settings';

interface Props {
    active: HubNavTab;
    totalUnreadCount: number;
    // ORG.9D-1R1: the School announcement list and Sent are Hub
    // (communications.view) pages; a recipient-only reader has neither.
    canViewHub: boolean;
    canAnnounce: boolean;
    canManage: boolean;
    canManageTemplates: boolean;
    canApprove: boolean;
}

defineProps<Props>();
</script>

<template>
    <nav class="text-sm">
        <ul class="space-y-1">
            <li>
                <a
                    href="/app/communications"
                    class="block rounded-xs px-2 py-1"
                    :class="
                        active === 'inbox'
                            ? 'bg-graphite-200 font-medium text-ink'
                            : 'text-ink-muted hover:text-ink'
                    "
                    >Inbox</a
                >
            </li>
            <li>
                <a
                    href="/app/communications/unread"
                    class="flex items-center justify-between rounded-xs px-2 py-1"
                    :class="
                        active === 'unread'
                            ? 'bg-graphite-200 font-medium text-ink'
                            : 'text-ink-muted hover:text-ink'
                    "
                >
                    <span>Unread</span>
                    <span
                        v-if="totalUnreadCount > 0"
                        class="rounded-xs bg-accent-strong px-1.5 py-0.5 text-xs font-medium text-white"
                        >{{ totalUnreadCount }}</span
                    >
                </a>
            </li>
            <li>
                <a
                    href="/app/communications/conversations"
                    class="block rounded-xs px-2 py-1"
                    :class="
                        active === 'conversations'
                            ? 'bg-graphite-200 font-medium text-ink'
                            : 'text-ink-muted hover:text-ink'
                    "
                    >Conversations</a
                >
            </li>
            <li v-if="canViewHub">
                <a
                    href="/app/communications/announcements"
                    class="block rounded-xs px-2 py-1"
                    :class="
                        active === 'announcements'
                            ? 'bg-graphite-200 font-medium text-ink'
                            : 'text-ink-muted hover:text-ink'
                    "
                    >Announcements</a
                >
            </li>
            <li v-if="canAnnounce">
                <a
                    href="/app/communications/announcements?status=scheduled"
                    class="block rounded-xs px-2 py-1"
                    :class="
                        active === 'scheduled'
                            ? 'bg-graphite-200 font-medium text-ink'
                            : 'text-ink-muted hover:text-ink'
                    "
                    >Scheduled</a
                >
            </li>
            <li v-if="canViewHub">
                <a
                    href="/app/communications/sent"
                    class="block rounded-xs px-2 py-1"
                    :class="
                        active === 'sent'
                            ? 'bg-graphite-200 font-medium text-ink'
                            : 'text-ink-muted hover:text-ink'
                    "
                    >Sent</a
                >
            </li>
            <li v-if="canApprove">
                <a
                    href="/app/communications/approvals"
                    class="block rounded-xs px-2 py-1"
                    :class="
                        active === 'approvals'
                            ? 'bg-graphite-200 font-medium text-ink'
                            : 'text-ink-muted hover:text-ink'
                    "
                    >Approvals</a
                >
            </li>
            <li v-if="canManage">
                <a
                    href="/app/communications/failed"
                    class="block rounded-xs px-2 py-1"
                    :class="
                        active === 'failed'
                            ? 'bg-graphite-200 font-medium text-ink'
                            : 'text-ink-muted hover:text-ink'
                    "
                    >Failed</a
                >
            </li>
            <li v-if="canManage">
                <a
                    href="/app/communications/analytics"
                    class="block rounded-xs px-2 py-1"
                    :class="
                        active === 'analytics'
                            ? 'bg-graphite-200 font-medium text-ink'
                            : 'text-ink-muted hover:text-ink'
                    "
                    >Analytics</a
                >
            </li>
            <li v-if="canManageTemplates">
                <a
                    href="/app/communications/templates"
                    class="block rounded-xs px-2 py-1"
                    :class="
                        active === 'templates'
                            ? 'bg-graphite-200 font-medium text-ink'
                            : 'text-ink-muted hover:text-ink'
                    "
                    >Templates</a
                >
            </li>
            <li>
                <a
                    href="/app/communications/preferences"
                    class="block rounded-xs px-2 py-1"
                    :class="
                        active === 'preferences'
                            ? 'bg-graphite-200 font-medium text-ink'
                            : 'text-ink-muted hover:text-ink'
                    "
                    >Preferences</a
                >
            </li>
            <li v-if="canManage">
                <a
                    href="/app/communications/settings/channels"
                    class="block rounded-xs px-2 py-1"
                    :class="
                        active === 'settings'
                            ? 'bg-graphite-200 font-medium text-ink'
                            : 'text-ink-muted hover:text-ink'
                    "
                    >Settings</a
                >
            </li>
        </ul>
    </nav>
</template>
