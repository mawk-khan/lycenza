<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import HubNav from '@/Components/App/Communications/HubNav.vue';
import InboxItemList, { type InboxItem } from '@/Components/App/Communications/InboxItemList.vue';

interface Props {
    query: string | null;
    items: InboxItem[];
    totalUnreadCount: number;
    canAnnounce: boolean;
    canManage: boolean;
    canManageTemplates: boolean;
    canApprove: boolean;
}

const props = defineProps<Props>();

const searchInput = ref(props.query ?? '');
function submitSearch() {
    router.get('/app/communications/search', { q: searchInput.value || undefined });
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-xs text-slate-400 underline" href="/app/communications"
            >← Communication Hub</a
        >
        <h1 class="mt-1 text-xl font-semibold">Search</h1>

        <form class="mt-4" @submit.prevent="submitSearch">
            <input
                v-model="searchInput"
                type="text"
                placeholder="Search communications…"
                class="w-full rounded border border-slate-300 px-3 py-2 text-sm"
            />
        </form>

        <div class="mt-6 grid grid-cols-1 gap-8 sm:grid-cols-[160px_1fr]">
            <HubNav
                active="inbox"
                :total-unread-count="totalUnreadCount"
                :can-announce="canAnnounce"
                :can-manage="canManage"
                :can-manage-templates="canManageTemplates"
                :can-approve="canApprove"
            />

            <div>
                <p v-if="!query" class="text-sm text-slate-500">
                    Enter a search term to find conversations, announcements, and templates.
                </p>
                <InboxItemList
                    v-else
                    :items="items"
                    :empty-message="`No results for &quot;${query}&quot;.`"
                />
            </div>
        </div>
    </main>
</template>
