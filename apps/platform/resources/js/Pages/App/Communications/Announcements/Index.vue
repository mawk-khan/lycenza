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
    scheduledAt: string | null;
    requestedChannels: string[] | null;
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
    filters: { status: string | null };
    canAnnounce: boolean;
}

defineProps<Props>();

const AUDIENCE_LABELS: Record<string, string> = {
    school_wide: 'Entire School',
    individual: 'Selected Members',
    student: 'Students',
    guardian: 'Guardians',
    guardians_of_students: 'Guardians of Selected Students',
};

function audienceLabel(type: string): string {
    return AUDIENCE_LABELS[type] ?? type;
}

const tabs: Array<{ label: string; value: string | null }> = [
    { label: 'All', value: null },
    { label: 'Drafts', value: 'draft' },
    { label: 'Scheduled', value: 'scheduled' },
    { label: 'Published', value: 'published' },
    { label: 'Cancelled', value: 'cancelled' },
];

function tabHref(value: string | null): string {
    return value === null
        ? '/app/communications/announcements'
        : `/app/communications/announcements?status=${value}`;
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

        <nav class="mt-4 flex gap-1 text-xs">
            <a
                v-for="tab in tabs"
                :key="tab.label"
                :href="tabHref(tab.value)"
                class="rounded px-2 py-1"
                :class="
                    (filters.status ?? null) === tab.value
                        ? 'bg-slate-900 font-medium text-white'
                        : 'text-slate-500 hover:bg-slate-100'
                "
            >
                {{ tab.label }}
            </a>
        </nav>

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
                                'bg-blue-100 text-blue-700':
                                    announcement.status === 'pending_approval',
                                'bg-teal-100 text-teal-700': announcement.status === 'approved',
                                'bg-rose-100 text-rose-700': announcement.status === 'rejected',
                                'bg-amber-100 text-amber-700': announcement.status === 'scheduled',
                                'bg-emerald-100 text-emerald-700':
                                    announcement.status === 'published',
                                'bg-red-100 text-red-600': announcement.status === 'cancelled',
                            }"
                        >
                            {{ announcement.status.replace('_', ' ') }}
                        </span>
                    </div>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ audienceLabel(announcement.audienceType) }}
                        <template v-if="announcement.recipientCount !== null">
                            · {{ announcement.recipientCount }} recipients</template
                        >
                        <template
                            v-if="announcement.status === 'scheduled' && announcement.scheduledAt"
                        >
                            · scheduled for {{ announcement.scheduledAt }}</template
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
