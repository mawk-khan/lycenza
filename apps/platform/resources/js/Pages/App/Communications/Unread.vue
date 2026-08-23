<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import HubNav from '@/Components/App/Communications/HubNav.vue';
import InboxItemList, { type InboxItem } from '@/Components/App/Communications/InboxItemList.vue';

interface Props {
    items: InboxItem[];
    limit: number;
    totalUnreadCount: number;
    canAnnounce: boolean;
    canManage: boolean;
    canManageTemplates: boolean;
}

const props = defineProps<Props>();

const loadingMore = ref(false);
function showMore() {
    loadingMore.value = true;
    router.get(
        '/app/communications/unread',
        { limit: props.limit + 20 },
        { preserveScroll: true, onFinish: () => (loadingMore.value = false) },
    );
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-xs text-slate-400 underline" href="/app/communications"
            >← Communication Hub</a
        >
        <h1 class="mt-1 text-xl font-semibold">Unread</h1>

        <div class="mt-6 grid grid-cols-1 gap-8 sm:grid-cols-[160px_1fr]">
            <HubNav
                active="unread"
                :total-unread-count="totalUnreadCount"
                :can-announce="canAnnounce"
                :can-manage="canManage"
                :can-manage-templates="canManageTemplates"
            />

            <div>
                <InboxItemList :items="items" empty-message="No unread communications." />

                <button
                    v-if="items.length >= limit"
                    type="button"
                    :disabled="loadingMore"
                    class="mt-4 w-full rounded border border-slate-200 py-1.5 text-xs text-slate-500 hover:bg-slate-50 disabled:opacity-50"
                    @click="showMore"
                >
                    Show more
                </button>
            </div>
        </div>
    </main>
</template>
