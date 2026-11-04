<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import HubNav from '@/Components/App/Communications/HubNav.vue';
import InboxItemList, { type InboxItem } from '@/Components/App/Communications/InboxItemList.vue';

interface InboxFilters {
    type: string | null;
    priority: string | null;
    hasAttachments: boolean | null;
}

interface Props {
    items: InboxItem[];
    limit: number;
    filters: InboxFilters;
    totalUnreadCount: number;
    canSend: boolean;
    canAnnounce: boolean;
    canViewHub: boolean;
    canManage: boolean;
    canManageChannelPolicy: boolean;
    canManageTemplates: boolean;
    canApprove: boolean;
}

const props = defineProps<Props>();

const searchInput = ref('');
function submitSearch() {
    if (!searchInput.value.trim()) {
        return;
    }
    router.get('/app/communications/search', { q: searchInput.value });
}

const typeFilter = ref(props.filters.type ?? '');
const priorityFilter = ref(props.filters.priority ?? '');
const attachmentsOnly = ref(props.filters.hasAttachments === true);

function applyFilters() {
    router.get(
        '/app/communications',
        {
            type: typeFilter.value || undefined,
            priority: priorityFilter.value || undefined,
            has_attachments: attachmentsOnly.value ? 1 : undefined,
        },
        { preserveState: true },
    );
}

const loadingMore = ref(false);
function showMore() {
    loadingMore.value = true;
    router.get(
        '/app/communications',
        {
            limit: props.limit + 20,
            type: typeFilter.value || undefined,
            priority: priorityFilter.value || undefined,
            has_attachments: attachmentsOnly.value ? 1 : undefined,
        },
        { preserveScroll: true, preserveState: true, onFinish: () => (loadingMore.value = false) },
    );
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-ink">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Communication Hub</h1>
            <div class="flex gap-2">
                <a
                    v-if="canAnnounce"
                    href="/app/communications/announcements/create"
                    class="btn btn-secondary text-ink-muted"
                    >+ Announcement</a
                >
                <a v-if="canSend" href="/app/communications/conversations" class="btn btn-primary"
                    >+ New Message</a
                >
            </div>
        </div>

        <form class="mt-4" @submit.prevent="submitSearch">
            <input
                v-model="searchInput"
                type="text"
                placeholder="Search communications…"
                class="input"
            />
        </form>

        <div class="mt-6 grid grid-cols-1 gap-8 sm:grid-cols-[160px_1fr]">
            <HubNav
                active="inbox"
                :total-unread-count="totalUnreadCount"
                :can-view-hub="canViewHub"
                :can-announce="canAnnounce"
                :can-manage="canManage"
                :can-manage-templates="canManageTemplates"
                :can-approve="canApprove"
            />

            <div>
                <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
                    <select v-model="typeFilter" class="input w-auto" @change="applyFilters">
                        <option value="">All types</option>
                        <option value="conversation">Conversations</option>
                        <option value="announcement">Announcements</option>
                    </select>
                    <select v-model="priorityFilter" class="input w-auto" @change="applyFilters">
                        <option value="">Any priority</option>
                        <option value="normal">Normal</option>
                        <option value="important">Important</option>
                        <option value="urgent">Urgent</option>
                        <option value="critical">Critical</option>
                    </select>
                    <label class="flex items-center gap-1 text-ink-muted">
                        <input
                            v-model="attachmentsOnly"
                            type="checkbox"
                            class="input w-auto"
                            @change="applyFilters"
                        />
                        Has attachments
                    </label>
                </div>

                <InboxItemList :items="items" empty-message="You're all caught up." />

                <button
                    v-if="items.length >= limit"
                    type="button"
                    :disabled="loadingMore"
                    class="mt-4 w-full rounded-xs border border-line py-1.5 text-xs text-ink-muted hover:bg-graphite-100 disabled:opacity-50"
                    @click="showMore"
                >
                    Show more
                </button>
            </div>
        </div>

        <nav class="mt-8 text-sm">
            <a class="underline" href="/app">← Back to dashboard</a>
        </nav>
    </main>
</template>
