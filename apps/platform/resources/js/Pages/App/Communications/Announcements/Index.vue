<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

interface AnnouncementSummary {
    id: string;
    title: string;
    createdByName: string | null;
    audienceType: string;
    recipientCount: number | null;
    status: string;
    priority: string;
    publishedAt: string | null;
    createdAt: string | null;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
}

interface Props {
    announcements: Paginated<AnnouncementSummary>;
    canAnnounce: boolean;
}

defineProps<Props>();

function audienceLabel(type: string): string {
    return type === 'school_wide' ? 'Entire School' : 'Selected Members';
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <div class="flex items-center justify-between">
            <div>
                <a class="text-xs text-slate-400 underline" href="/app/communications"
                    >← Communication Hub</a
                >
                <h1 class="mt-1 text-xl font-semibold">Announcements</h1>
            </div>
            <Link
                v-if="canAnnounce"
                href="/app/communications/announcements/create"
                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white"
            >
                + New Announcement
            </Link>
        </div>

        <div
            v-if="announcements.data.length === 0"
            class="mt-6 rounded border border-dashed border-slate-300 p-8 text-center"
        >
            <p class="text-sm text-slate-500">No announcements yet.</p>
            <p v-if="canAnnounce" class="mt-1 text-xs text-slate-400">
                Start one with "New Announcement" above.
            </p>
        </div>

        <ul v-else class="mt-6 divide-y divide-slate-200 rounded border border-slate-200">
            <li v-for="announcement in announcements.data" :key="announcement.id">
                <a
                    class="block px-4 py-3 hover:bg-slate-50"
                    :href="`/app/communications/announcements/${announcement.id}`"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">{{ announcement.title }}</span>
                        <span
                            class="rounded px-1.5 py-0.5 text-xs font-medium"
                            :class="{
                                'bg-slate-100 text-slate-600': announcement.status === 'draft',
                                'bg-emerald-100 text-emerald-700':
                                    announcement.status === 'published',
                                'bg-red-100 text-red-600': announcement.status === 'cancelled',
                            }"
                        >
                            {{ announcement.status }}
                        </span>
                    </div>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ audienceLabel(announcement.audienceType) }}
                        <template v-if="announcement.recipientCount !== null">
                            · {{ announcement.recipientCount }} recipients</template
                        >
                        · by {{ announcement.createdByName ?? 'Unknown' }}
                    </p>
                </a>
            </li>
        </ul>

        <p v-if="announcements.last_page > 1" class="mt-4 text-xs text-slate-400">
            Page {{ announcements.current_page }} of {{ announcements.last_page }} ({{
                announcements.total
            }}
            total)
        </p>
    </main>
</template>
