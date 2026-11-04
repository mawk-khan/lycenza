<script setup lang="ts">
import { formatDateTime } from '../../../../datetime';
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
    staff: 'Staff only (current employees)',
    individual: 'Selected Members',
    student: 'Students',
    guardian: 'Guardians',
    guardians_of_students: 'Guardians of Selected Students',
    grade: 'Grade (Academic Cohort)',
    section: 'Section (Academic Cohort)',
    subject_offering: 'Subject Offering (Academic Cohort)',
    fee_reminder: 'Fee reminder',
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
    <main class="mx-auto max-w-4xl p-8 font-sans text-ink">
        <div class="flex items-center justify-between">
            <div>
                <a class="text-xs text-ink-muted underline" href="/app/communications"
                    >← Communication Hub</a
                >
                <h1 class="mt-1 text-xl font-semibold">Announcements</h1>
            </div>
            <Link
                v-if="canAnnounce"
                href="/app/communications/announcements/create"
                class="btn btn-primary"
            >
                + New Announcement
            </Link>
        </div>

        <nav class="mt-4 flex gap-1 text-xs">
            <a
                v-for="tab in tabs"
                :key="tab.label"
                :href="tabHref(tab.value)"
                class="rounded-xs px-2 py-1"
                :class="
                    (filters.status ?? null) === tab.value
                        ? 'bg-accent-strong font-medium text-white'
                        : 'text-ink-muted hover:bg-graphite-200'
                "
            >
                {{ tab.label }}
            </a>
        </nav>

        <div
            v-if="announcements.data.length === 0"
            class="mt-6 rounded-xs border border-dashed border-control-line p-8 text-center"
        >
            <p class="text-sm text-ink-muted">No announcements yet.</p>
            <p v-if="canAnnounce" class="mt-1 text-xs text-ink-muted">
                Start one with "New Announcement" above.
            </p>
        </div>

        <ul v-else class="mt-6 divide-y divide-line rounded-xs border border-line">
            <li v-for="announcement in announcements.data" :key="announcement.id">
                <a
                    class="block px-4 py-3 hover:bg-graphite-100"
                    :href="`/app/communications/announcements/${announcement.id}`"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">{{ announcement.title }}</span>
                        <span
                            class="rounded-xs px-1.5 py-0.5 text-xs font-medium"
                            :class="{
                                'tag-neutral': announcement.status === 'draft',
                                'tag-info':
                                    announcement.status === 'pending_approval' ||
                                    announcement.status === 'scheduled',
                                'tag-positive':
                                    announcement.status === 'approved' ||
                                    announcement.status === 'published',
                                'tag-negative': announcement.status === 'rejected',
                                'tag-attention': announcement.status === 'cancelled',
                            }"
                        >
                            {{ announcement.status.replace('_', ' ') }}
                        </span>
                    </div>
                    <p class="mt-0.5 text-xs text-ink-muted">
                        {{ audienceLabel(announcement.audienceType) }}
                        <template v-if="announcement.recipientCount !== null">
                            · {{ announcement.recipientCount }} recipients</template
                        >
                        <template
                            v-if="announcement.status === 'scheduled' && announcement.scheduledAt"
                        >
                            · scheduled for {{ formatDateTime(announcement.scheduledAt) }}</template
                        >
                        · by {{ announcement.createdByName ?? 'Unknown' }}
                    </p>
                </a>
            </li>
        </ul>

        <p v-if="announcements.last_page > 1" class="mt-4 text-xs text-ink-muted">
            Page {{ announcements.current_page }} of {{ announcements.last_page }} ({{
                announcements.total
            }}
            total)
        </p>
    </main>
</template>
